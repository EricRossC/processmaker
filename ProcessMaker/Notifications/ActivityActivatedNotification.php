<?php

namespace ProcessMaker\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use ProcessMaker\Models\Process;
use ProcessMaker\Models\ProcessRequestToken as Token;
use ProcessMaker\Nayra\Contracts\Bpmn\TokenInterface;

class ActivityActivatedNotification extends Notification
{
    use Queueable;

    private $processUid;
    private $instanceUid;
    private $tokenUid;
    private $tokenElement;
    private $tokenStatus;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(TokenInterface $token)
    {
        $this->processUid = $token->processRequest->process->getKey();
        $this->instanceUid = $token->processRequest->getKey();
        $this->tokenUid = $token->getKey();
        $this->tokenElement = $token->element_id;
        $this->tokenStatus = $token->status;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast', 'database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $process = Process::find($this->processUid); //->name
        $definitions = $process->getDefinitions();
        $activity = $definitions->getActivity($this->tokenElement);
        $token = Token::find($this->tokenUid);
	$request = $token->processRequest;

	$title = sprintf('Tarea creada: %s', $activity->getName());
	$ts = $token->created_at->toIso8601String();
	$url = sprintf('/tasks/%s/edit', $token->id);
	$url = url(route('tasks.edit', [ 'task' => $token->id ], false));

        return (new MailMessage)
	    ->subject($title)
	    ->greeting('¡Hola persona!')
	    ->line('Se ha creado la tarea "' . $activity->getName() . '" con fecha ' . $ts . ' como parte de la solicitud "'. $request->name .'"')
	    //->line(print_r($request, true))
	    ->action('Ir a la tarea', $url)
	    ->salutation('Atentamente,<br/>Workflow Curricular')
	    ;
    }

    /**
     * Get the database representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        return $this->toArray($notifiable);
    }

    /*
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $process = Process::find($this->processUid);
        $definitions = $process->getDefinitions();
        $activity = $definitions->getActivity($this->tokenElement);
        $token = Token::find($this->tokenUid);
        $request = $token->processRequest;
        return [
            'type' => 'TASK_CREATED' ,
            'message' => sprintf('Task created: %s', $activity->getName()),
            'name' => $activity->getName(),
            'processName' => $process->name,
            'request_id' => $request->getKey(),
            'userName' => $token->user->getFullName(),
            'user_id' => $token->user->id,
            'dateTime' => $token->created_at->toIso8601String(),
            'uid' => $this->tokenUid,
            'url' => sprintf(
                '/tasks/%s/edit',
                $token->id
            )
        ];
    }

    /*
     * Get the broadcast representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

}
