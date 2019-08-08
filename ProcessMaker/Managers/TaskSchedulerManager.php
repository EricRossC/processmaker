<?php

namespace ProcessMaker\Managers;

use Carbon\Carbon;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDOException;
use ProcessMaker\Facades\WorkflowManager;
use ProcessMaker\Models\Process;
use ProcessMaker\Models\ProcessRequest;
use ProcessMaker\Models\ScheduledTask;
use ProcessMaker\Models\TimerExpression;
use ProcessMaker\Nayra\Bpmn\Models\BoundaryEvent;
use ProcessMaker\Nayra\Bpmn\Models\IntermediateCatchEvent;
use ProcessMaker\Nayra\Bpmn\Models\StartEvent;
use ProcessMaker\Nayra\Contracts\Bpmn\EntityInterface;
use ProcessMaker\Nayra\Contracts\Bpmn\FlowElementInterface;
use ProcessMaker\Nayra\Contracts\Bpmn\TimerEventDefinitionInterface;
use ProcessMaker\Nayra\Contracts\Bpmn\TokenInterface;
use ProcessMaker\Nayra\Contracts\Engine\JobManagerInterface;
use ProcessMaker\Nayra\Contracts\EventBusInterface;
use ProcessMaker\Nayra\Storage\BpmnDocument;

class TaskSchedulerManager implements JobManagerInterface, EventBusInterface
{
    private static $today = null;
    protected $registerStartEvents = false;

    /**
     *
     * Register in the database any Timer Start Event of a process
     *
     * @param \ProcessMaker\Models\Process $process
     * @return void
     * @internal param string $script Path to the javascript to load
     */
    public function registerTimerEvents(Process $process)
    {
        ScheduledTask::where('process_id', $process->id)
            ->where('type', 'TIMER_START_EVENT')
            ->delete();
        $definitions = $process->getDefinitions();
        if ($definitions) {
            $definitions->getEngine()->getJobManager()->enableRegisterStartEvents();
            $processDefinitions = $definitions->getElementsByTagNameNS(BpmnDocument::BPMN_MODEL, 'process');
            foreach($processDefinitions as $processDefinition) {
                $element = $processDefinition->getBpmnElementInstance();
                $definitions->getEngine()->loadProcess($element);
            }
            $definitions->getEngine()->getJobManager()->disableRegisterStartEvents();
        }
    }

    /**
     * Enable register start events during process loading
     */
    public function enableRegisterStartEvents()
    {
        $this->registerStartEvents = true;
    }

    /**
     * Disable register start events during process loading
     */
    public function disableRegisterStartEvents()
    {
        $this->registerStartEvents = false;
    }

    /**
     * Schedule a task in the database
     *
     * @param Process $process
     * @param array $configuration
     * @param string $type
     *
     * @return ScheduledTask
     */
    private function scheduleTask(
        $interval,
        FlowElementInterface $element,
        TokenInterface $token = null
    ) {
        $timeEventType = $interval instanceof DateTimeInterface ? 'TimeDate' : ($interval instanceof DatePeriod ? 'TimeCycle' : ($interval instanceof DateInterval ? 'TimeDuration' : null));
        $types = [
            IntermediateCatchEvent::class => 'INTERMEDIATE_TIMER_EVENT',
            StartEvent::class => 'TIMER_START_EVENT',
            BoundaryEvent::class => 'BOUNDARY_TIMER_EVENT',
        ];
        $configuration = [
            'type' => $timeEventType,
            'interval' => $interval,
            'element_id' => $element->getId(),
        ];
        $scheduledTask = new ScheduledTask();
        $process = $element->getOwnerProcess()->getEngine()->getProcess();
        $scheduledTask->process_id = $token ? $token->process_id : $process->id;
        $scheduledTask->process_request_id = $token ? $token->processRequest->id : null;
        $scheduledTask->process_request_token_id = $token ? $token->id : null;
        $scheduledTask->configuration = json_encode($configuration);
        $scheduledTask->type = $types[get_class($element)];
        $scheduledTask->last_execution = $this->today()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $scheduledTask->save();
        return $scheduledTask;
    }

    /**
     * Checks the schedule_tasks table to execute jobs
     *
     * @param Schedule $schedule
     */
    public function scheduleTasks(Schedule $schedule)
    {
        try {
            if (!Schema::hasTable('scheduled_tasks')) {
                return;
            }

            $tasks = ScheduledTask::all();

            $today = $this->today();
            foreach ($tasks as $task) {
                $config = json_decode($task->configuration);

                $lastExecution = new DateTime($task->last_execution, new DateTimeZone('UTC'));

                if ($lastExecution === null) {
                    continue;
                }
                $nextDate = $this->nextDate($today, $config, $lastExecution);

                // if no execution date exists we go to the next task
                if (empty($nextDate)) {
                    continue;
                }
                if ($nextDate <= $today) {
                    switch ($task->type) {
                        case 'TIMER_START_EVENT':
                            $this->executeTimerStartEvent($task, $config);
                            $task->last_execution = $today->format('Y-m-d H:i:s');
                            $task->save();
                            break;
                        case 'INTERMEDIATE_TIMER_EVENT':
                            $executed = $this->executeIntermediateTimerEvent($task, $config);
                            $task->last_execution = $today->format('Y-m-d H:i:s');
                            if ($executed) {
                                $task->save();
                            }
                            break;
                        case 'BOUNDARY_TIMER_EVENT':
                            $executed = $this->executeBoundaryTimerEvent($task, $config);
                            $task->last_execution = $today->format('Y-m-d H:i:s');
                            if ($executed) {
                                $task->save();
                            }
                            break;
                        default:
                            throw new Exception('Unknown timer event: ' . $task->type);
                    }
                }
            }
        } catch (PDOException $e) {
            Log::error('The connection to the database had problems');
        }
    }

    /**
     * Execute a timer start event
     *
     * @param ScheduledTask $task
     * @param object $config
     */
    public function executeTimerStartEvent(ScheduledTask $task, $config)
    {
        // Get the event BPMN element
        $id = $task->process_id;
        if (!$id) {
            return;
        }

        $process = Process::find($id);

        // If a process is configured to pause timer start events we do nothing
        if ($process->pause_timer_start === 1) {
            return;
        }

        $definitions = $process->getDefinitions();
        if (!$definitions->findElementById($config->element_id)) {
            return;
        }
        $event = $definitions->getEvent($config->element_id);
        $data = [];

        //Trigger the start event
        $processRequest = WorkflowManager::triggerStartEvent($process, $event, $data);
    }

    /**
     * Execute a timer start event
     *
     * @param ScheduledTask $task
     * @param object $config
     */
    public function executeIntermediateTimerEvent(ScheduledTask $task, $config)
    {
        //Get the event BPMN element
        $id = $task->process_id;
        if (!$id) {
            return;
        }

        $process = Process::find($id);
        $request = ProcessRequest::find($task->process_request_id);

        $definitions = $process->getDefinitions();
        if (!$definitions->findElementById($config->element_id)) {
            return;
        }

        $catches = $request->tokens()
            ->where('element_id', $config->element_id)
            ->where('status', 'ACTIVE')->get();
        $executed = false;
        foreach ($catches as $catch) {
            WorkflowManager::completeCatchEvent($process, $request, $catch, []);
            $executed = true;
        }

        return $executed;
    }

    /**
     * Execute a timer start event
     *
     * @param ScheduledTask $task
     * @param object $config
     */
    public function executeBoundaryTimerEvent($task, $config)
    {
        //Get the event BPMN element
        $id = $task->process_id;
        if (!$id) {
            return;
        }

        $process = Process::find($id);
        $request = ProcessRequest::find($task->process_request_id);

        $definitions = $process->getDefinitions();
        $catch = $definitions->getBoundaryEvent($config->element_id);
        if (!$catch) {
            return;
        }

        $activity = $catch->getAttachedTo();
        $tokens = $request->tokens()
            ->where('element_id', $activity->getId())
            ->where('status', 'ACTIVE')->get();

        $executed = false;
        foreach ($tokens as $token) {
            WorkflowManager::triggerBoundaryEvent($process, $request, $token, $catch, []);
            $executed = true;
        }

        return $executed;
    }

    /**
     * Get next date for a timer configuration
     *
     * @param DateTimeInterface $currentDate
     * @param object $intervalConfig
     * @param DateTimeInterface $lastExecution
     * @return void
     */
    public function nextDate(DateTimeInterface $currentDate, $timerConfig, DateTimeInterface $lastExecution = null)
    {
        $result = null;
        switch ($timerConfig->type) {
            case 'TimeDate':
                $result = $this->nextDateForOneDate($currentDate, $timerConfig->interval, $lastExecution);
                break;
            case 'TimeCycle':
                $result = $this->nextDateForCyclical($currentDate, $timerConfig->interval, $lastExecution);
                break;
            case 'TimeDuration':
                $result = $this->nextDateForDuration($currentDate, $timerConfig->interval, $lastExecution);
                break;
        }

        return $result;
    }

    /**
     * Load a DateTime|DatePeriod|DateInterval from a json object
     *
     * @param object $timer
     *
     * @return DateTime|DatePeriod|DateInterval
     */
    private function loadTimerFromJson($timer)
    {
        if (isset($timer->date)) {
            return new DateTime($timer->date, new DateTimeZone($timer->timezone));
        } elseif(isset($timer->start)) {
            $start = $this->loadTimerFromJson($timer->start);
            $interval = $this->loadTimerFromJson($timer->interval);
            $end = $timer->end ? $this->loadTimerFromJson($timer->end) : null;
            $recurrences = $timer->recurrences;
            return new DatePeriod($start, $interval, $end ? $end : ($recurrences ?: -1));
        } elseif(isset($timer->y)) {
            return new DateInterval(sprintf(
                'P%sY%sM%sDT%sH%sM%sS',
                $timer->y,
                $timer->m,
                $timer->d,
                $timer->h,
                $timer->i,
                $timer->s + $timer->f
            ));
        } elseif(is_string($timer)) {
            $expression = new TimerExpression();
            $expression->setBody($timer);
            return $expression([]);
        }
    }

    /**
     * Get the next datetime event of a cycle.
     *
     * @param DateTimeInterface $currentDate
     * @param string $nayraInterval
     *
     * @return DateTimeInterface
     */
    private function nextDateForCyclical(DateTimeInterface $currentDate, $jsonCycle, DateTimeInterface $lastExecution = null)
    {
        $cycle = $this->loadTimerFromJson($jsonCycle);
        $nextDateTime = null;
        $dateTime = clone $cycle->start;
        $recurrences = $cycle->recurrences;
        while(true) {
            if ( ($cycle->end && $dateTime > $cycle->end)
                || (!$cycle->end && $cycle->recurrences && $recurrences <= 0) ) {
                break;
            }
            if ( (!$lastExecution || $lastExecution < $dateTime)) {
                $nextDateTime = clone $dateTime;
                break;
            }
            $recurrences --;
            $dateTime->add($cycle->interval);
        }
        return $nextDateTime;
    }

    /**
     * Get the next DateTime execution for a datetime timer
     *
     * @param DateTimeInterface $currentDate
     * @param object $jsonDateTime
     * @param DateTimeInterface $lastExecution
     *
     * @return DateTime
     */
    private function nextDateForOneDate(DateTimeInterface $currentDate, $jsonDateTime, DateTimeInterface $lastExecution = null)
    {
        $dateTime = $this->loadTimerFromJson($jsonDateTime);
        return (!$lastExecution || $lastExecution < $dateTime) ? $dateTime : null;
    }

    /**
     * Get the next DateTime execution for a duration timer
     *
     * @param DateTimeInterface $currentDate
     * @param object $jsonInterval
     * @param DateTimeInterface $lastExecution
     *
     * @return DateTime
     */
    private function nextDateForDuration(DateTimeInterface $currentDate, $jsonInterval, DateTimeInterface $lastExecution = null)
    {
        $interval = $this->loadTimerFromJson($jsonInterval);
        $dateTime = (clone ($lastExecution ?: $currentDate))->add($interval);
        return (!$lastExecution || $lastExecution < $dateTime) ? $dateTime : null;
    }

    /**
     * Get today date.
     *
     * @return DateTime
     */
    public function today()
    {
        return self::$today ?: (Carbon::now())->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Set a fake today date
     *
     * @param mixed $today
     *
     * @return DateTime
     */
    public static function fakeToday($today)
    {
        if ($today === null) {
            return self::$today = $today;
        }
        self::$today = $today instanceof DateTime ? clone $today : (new DateTime($today))->setTimezone(new DateTimeZone('UTC'));
        return clone self::$today;
    }

    /**
     * Schedule a job for a specific date and time for the given BPMN element,
     * event definition and an optional Token object
     *
     * @param string $datetime in ISO-8601 format
     * @param TimerEventDefinitionInterface $eventDefinition
     * @param EntityInterface $element
     * @param TokenInterface $token
     *
     * @return $this
     */
    public function scheduleDate(
        $datetime,
        TimerEventDefinitionInterface $eventDefinition,
        FlowElementInterface $element,
        TokenInterface $token = null
    ) {
        if ($token || ($this->registerStartEvents && !$token)) {
            $this->scheduleTask($datetime, $element, $token);
        }
    }

    /**
     * Schedule a job for a specific cycle for the given BPMN element, event definition
     * and an optional Token object
     *
     * @param string $cycle in ISO-8601 format
     * @param TimerEventDefinitionInterface $eventDefinition
     * @param EntityInterface $element
     * @param TokenInterface $token
     */
    public function scheduleCycle(
        $cycle,
        TimerEventDefinitionInterface $eventDefinition,
        FlowElementInterface $element,
        TokenInterface $token = null
    ) {
        if ($token || ($this->registerStartEvents && !$token)) {
            $this->scheduleTask($cycle, $element, $token);
        }
    }

    /**
     * Schedule a job execution after a time duration for the given BPMN element,
     * event definition and an optional Token object
     *
     * @param string $duration in ISO-8601 format
     * @param TimerEventDefinitionInterface $eventDefinition
     * @param EntityInterface $element
     * @param TokenInterface $token
     */
    public function scheduleDuration(
        $duration,
        TimerEventDefinitionInterface $eventDefinition,
        FlowElementInterface $element,
        TokenInterface $token = null
    ) {
        if ($token || ($this->registerStartEvents && !$token)) {
            $this->scheduleTask($duration, $element, $token);
        }
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  string|array $events
     * @param  mixed $listener
     *
     * @return void
     */
    public function listen($events, $listener)
    { }

    /**
     * Determine if a given event has listeners.
     *
     * @param  string $eventName
     *
     * @return bool
     */
    public function hasListeners($eventName)
    { }

    /**
     * Register an event subscriber with the dispatcher.
     *
     * @param  object|string $subscriber
     *
     * @return void
     */
    public function subscribe($subscriber)
    { }

    /**
     * Dispatch an event until the first non-null response is returned.
     *
     * @param  string|object $event
     * @param  mixed $payload
     *
     * @return array|null
     */
    public function until($event, $payload = [])
    { }

    /**
     * Dispatch an event and call the listeners.
     *
     * @param  string|object $event
     * @param  mixed $payload
     * @param  bool $halt
     *
     * @return array|null
     */
    public function dispatch($event, $payload = [], $halt = false)
    { }

    /**
     * Register an event and payload to be fired later.
     *
     * @param  string $event
     * @param  array $payload
     *
     * @return void
     */
    public function push($event, $payload = [])
    { }

    /**
     * Flush a set of pushed events.
     *
     * @param  string $event
     *
     * @return void
     */
    public function flush($event)
    { }

    /**
     * Remove a set of listeners from the dispatcher.
     *
     * @param  string $event
     *
     * @return void
     */
    public function forget($event)
    { }

    /**
     * Forget all of the queued listeners.
     *
     * @return void
     */
    public function forgetPushed()
    { }
}
