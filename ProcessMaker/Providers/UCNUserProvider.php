<?php

namespace ProcessMaker\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

use InvalidArgumentException;

class UCNUserProvider implements UserProvider
{
    protected $hasher;
    protected $model;

    public function __construct($model)
    {
        $this->model = $model;
	// throw new InvalidArgumentException("p=".app('path.storage'));
    }

    public function createModel()
    {
        $class = '\\'.ltrim($this->model, '\\');

        return new $class;
    }
    protected function newModelQuery($model = null)
    {
        return is_null($model)
                ? $this->createModel()->newQuery()
                : $model->newQuery();
    }

    public function retrieveById($identifier)
    {
	    Log::info('retrieveById(' . $identifier . ')');

	$model = $this->createModel();

	return $this->newModelQuery($model)
		->where($model->getAuthIdentifierName(), $identifier)
		->first();
    }

    public function retrieveByToken($identifier, $token)
    {
	    Log::info('retrieveByToken(' . $identifier . ', ' . $token . ')');

	throw new InvalidArgumentException("retrieveByToken");
	    return null;
    }
    public function updateRememberToken(Authenticatable $user, $token)
    {
	    
	throw new InvalidArgumentException("updateRememberToken");
	    return null;
    }


    public function retrieveByCredentials(array $credentials) // username password
    {
	    Log::info('retrieveByCredentials: ' . print_r($credentials, true));

        if (empty($credentials) ||
           (count($credentials) === 1 &&
            Str::contains($this->firstCredentialKey($credentials), 'password'))) {
            return;
        }

	$credentials['username'] = $this->fixRUT($credentials['username']);

	Log::debug("fixing RUT to " . $credentials['username']);

        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if (Str::contains($key, 'password')) {
                continue;
            }

            if (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

	$user = $query->first();

	if (is_null($user)) {
		Log::debug("User is null, creating new model");

		$user = $this->createModel()->make([
			"username" => $credentials['username'],
			"firstname" => $credentials['username'],
			"email" => $credentials['username'] . "@ucn.cl",
			"password" => ""
		]);
		Log::debug("saving new user");
		$user->save();
	}

	Log::debug("Returning user");
	return $user;
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
	Log::info('validateCredentials: user=' . $user->username . " credentials=" . print_r($credentials, true));

        $plain = $credentials['password'];
	$result = $this->intentLoginUCN($user->username, $plain);

	Log::debug("intentLoginUCN(" . $user->username . ", " . $plain . ") returned " . ($result === false ? "false" : $result));

	if ($result !== false) {
		Log::debug("Setting firstname to " . $result);

		$user->setAttribute("firstname", $result);
		$user->setAttribute("lastname", $result);

		Log::debug("saving new user");
		$user->save();
		return true;
	}

	return false;
	throw new InvalidArgumentException("result=" . print_r($result === false ? "FALSO" : $result, true));
    }

    private function fixRUT($rut)
    {
	$rut = str_replace(".", "", $rut);
	$rut = str_replace("-", "", $rut);
	$rut = strtoupper($rut);
	return $rut;
    }

    private function intentLoginUCN($rut, $pass)
    {
	$rut = $this->fixRUT($rut);

	$dv = strtoupper(substr($rut, -1));

	$rutn = (int) substr($rut, 0, strlen($rut) - 1);
	$ruts = number_format($rutn/1.0, 0, ",", ".") . "-" . $dv;

	//if ($ruts == "12.840.176-8" && $pass == "123") { return true;} // 44165051408K
	//if ($ruts == "16.308.490-2" && $pass == "123") { return true;} // 44165051408K

	//if ($ruts == "19.489.362-0" && $pass == "123") { return true;} // 44165051408K
	//if ($ruts == "17.980.262-7" && $pass == "123") { return true;} // 179802627 OSORIO
	//if ($ruts == "18.825.519-1" && $pass == "hola") { return true;} // 181776056 188255191
	//if ($ruts == "15.019.848-8" && $pass == "123") { return true;}

	$url = 'https://online.ucn.cl/onlineucn/Servicio.asp';

	$data = array(
		"cod" => "",
		"origen" => "academico",
		"rut" => $rutn,
		"dv" => $dv,
		"rut_aux" => $ruts,
		"clave" => $pass,
		"Ingresar.x" => "71",
		"Ingresar.y" => "19"
	);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, true);
	//curl_setopt($ch, CURLOPT_VERBOSE, true); //

	$ckfile = tempnam ("/tmp", "CURLCOOKIE");
	curl_setopt ($ch, CURLOPT_COOKIEJAR, $ckfile);

	$headers = array();
	//$headers[] = 'X-Apple-Tz: 0';
	//$headers[] = 'X-Apple-Store-Front: 143444,12';
	//$headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
	//$headers[] = 'Accept-Encoding: gzip, deflate';
	//$headers[] = 'Accept-Language: en-US,en;q=0.5';
	$headers[] = 'Cache-Control: no-cache';
	//$headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
	//$headers[] = 'Host: www.example.com';
	$headers[] = 'Referer: https://online.ucn.cl/onlineucn/';
	$headers[] = 'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:28.0) Gecko/20100101 Firefox/28.0';
	//$headers[] = 'X-MicrosoftAjax: Delta=true';

	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	$output = curl_exec($ch);

	if (curl_errno($ch)) {
		//print "Error: " . curl_error($ch);
		return false;
	}

	curl_close ($ch);

	//print_r($output); //

	//return strpos($output, "Location: servicio.asp") !== false;

	if (strpos($output, "Location: servicio.asp") === false) { return false; }


	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	//curl_setopt($ch, CURLOPT_POST, 0);
	//curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, true);
	//curl_setopt($ch, CURLOPT_VERBOSE, true); //
	curl_setopt($ch, CURLOPT_COOKIEFILE, $ckfile);

	$headers = array();
	//$headers[] = 'X-Apple-Tz: 0';
	//$headers[] = 'X-Apple-Store-Front: 143444,12';
	//$headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
	//$headers[] = 'Accept-Encoding: gzip, deflate';
	//$headers[] = 'Accept-Language: en-US,en;q=0.5';
	$headers[] = 'Cache-Control: no-cache';
	//$headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
	//$headers[] = 'Host: www.example.com';
	$headers[] = 'Referer: https://online.ucn.cl/onlineucn/';
	$headers[] = 'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:28.0) Gecko/20100101 Firefox/28.0';
	//$headers[] = 'X-MicrosoftAjax: Delta=true';

	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	$output = curl_exec($ch);

	if (curl_errno($ch)) {
		//print "Error: " . curl_error($ch);
		return false;
	}

	curl_close ($ch);

	//print_r("segundo output:---------------------------------------------------\n");
	//print_r($output); //


	//<div id="datosUsuario">Sr(a).ERIC ROSS            CORTES     <span>
	//<div id="datosUsuario">Sr. Felipe Pena Graf <span>Bienvenido a los Servicios OnLineUCN</span></div>

	//$r = preg_match("|\>Sr....([^\<]*)<span|", $output, $matches);
	$r = preg_match("|\>Sr[^\.]*\.([^\<]*)<span|", $output, $matches);

	if ($r !== 1) { return false; }

	// exito
	$output = trim(preg_replace('!\s+!', ' ', $matches[1]));
	return $output;
    }


}
