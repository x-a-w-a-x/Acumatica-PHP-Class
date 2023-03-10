<?php
/*
Documentation ACUMATICA à cette adresse :
https://help-2021r2.acumatica.com/(W(28))/Help?ScreenId=ShowWiki&pageid=ff22837c-cd3a-410e-b768-88ca6e53b165

Reste à faire :
- gestion du timeOut du jeton de connexion
- gestion des méthodes de connexion 'Authorization Code' et 'Implicit'
*/

class Acumatica{

	private $tenant;
	private $branch;
	private $instanceURL;
	private $endPointURL;
	private $connectionType;
	private $cookie = 'C:\wamp64\www\acumatica\cookie.txt';	// modifer ce chemin selon le système de fichier - voir si passer cette variable dans le constructeur...
	private $token;
	private $curl;
	private $headerOut;
	public $curlInfo = null;
	public $headers = null;
	public $body = null;
	public $httpTxtCode = null;
	
	public function __construct(string $instanceURL, string $tenant, string $endPointURL = '/Default/20.200.001', string $branch = null) {
        $this->instanceURL = $instanceURL;
        $this->endPointURL = $endPointURL;
        $this->tenant = $tenant;
        $this->branch = $branch;
		$this->connectionType = false;
		$this->headerOut = false;
	}


	
/*  LoginBasic     HTTP Methode :
POST /auth/login HTTP/1.1
Host: <Acumatica ERP instance URL>/entity
Accept: application/json
Content-Type: application/json

{
   "name" : "<username>",
   "password" : "<password>",
   "tenant" : "<tenant>",
   "branch" : "<branch>"
}
*/
/**
* Connexion méthode basique
*
*	@param		string	$login	username
*	@param		string	$pass	password
*	@return		boolean	true si connexion réussie
*/
	public function loginBasic($login, $pass, string $branch = null){

		if($branch <> null) $this->branch = $branch;
		elseif($this->branch == null) throw new Exception("Erreur Flex loginBasic : l'établissement n'est pas spécifié");
		
		$this->headerOut = array(
				'Content-Type: application/json',
				'Accept: application/json'
		);

		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL.'/entity/auth/login',
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS =>'{
				"name" : "'.$login.'",
				"password" : "'.$pass.'",
				"tenant" : "'.$this->tenant.'",
				"branch" : "'.$this->branch.'", 
				"locale" : "fr-FR"
				}',
			CURLOPT_COOKIEJAR => $this->cookie,
		));

		$this->call(true);
		if ($this->curlInfo['http_code'] == 204){
			$this->connectionType = "basic";
			return true;
		}else return false;
	}

/* loginCredential 		HTTP Methode :
POST /connect/token HTTP/1.1
Host: <Acumatica ERP instance URL>/identity
content-type: application/x-www-form-urlencoded

{ 
	"scope" : "api",
	"username" : "<username>",
	"password" : "<password>",
	"client_id" : "<client_id>",
	"client_secret" : "<client_secret>",
	"grant_type" : "password"
}
*/	
/**
* Connexion méthode Resource Owner Password Credentials
*
*	@param		string	$login	username
*	@param		string	$pass	password
*	@param		string	$clientID	identifiant de l'API cliente
*	@param		string	$secret	secret de l'API cliente
*	@return		boolean	true si connexion réussie 
*/
	public function loginCredential($login, $pass, $clientID, $secret){
		
		$post = "client_id=".$clientID."&client_secret=".$secret."&scope=api&grant_type=password&username=".$login."&password=".$pass;
		$this->headerOut = array(
			  "Content-Type: application/x-www-form-urlencoded",
			  "Content-Length: ".strlen($post)
		);

		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL.'/identity/connect/token',
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => $post		// Ne pas passer un tableau sinon le header Content-Type : multipart/form-data
		));

		$this->call(true);
		if ($this->curlInfo['http_code'] == 200){
			$this->connectionType = "ownerCredential";
			$tab = json_decode($this->body, true);
			$this->token = $tab["access_token"];
			return true;
		}else return false;
	}


/* Logout		HTTP Methode : 
POST /auth/logout HTTP/1.1
Host: <Acumatica ERP instance URL>/entity
Accept: application/json
Content-Type: application/json
*/
/**
* Déconnexion
*
*	@return		boolean	true si déconnexion réussie 
*/
	public function logout(){

		$this->headerOut = array(
				'Content-Type: application/json',
				'Accept: application/json',
				'Content-Length: 0' 
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL.'/entity/auth/logout',
			CURLOPT_CUSTOMREQUEST => 'POST',
		));

		$this->call();
		if ($this->curlInfo['http_code'] == 204){
			$this->connectionType = false;
			return true;
		}else return false;
	}


/*  select   HTTP Methode :
GET ?<parameters> HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/json
Content-Type: application/json
*/
/**
* Retourne les données d'une entité avec ses éléments liés. Une sélection vide renvoie une erreur 500
*
*	@param		string	$entity	l'entité à retourner
*	@param		array	$params	tableau associatif de paramètres acceptant
*								les clefs  ID, select, filter, expand, top, skip, custom.
*								Utilisez ID ou filter.
*	@return		array	tableau associatif 
*/
	public function select($entity, $params = null){
		$listParam = array("select", "filter", "expand", "top", "skip", "custom");
		$strParam = "";
		if($params){
			$tmp = array();
			foreach($params as $key=>$val){ 
				if (in_array($key, $listParam)) $tmp[] = "$".$key."=".rawurlencode($val);
				if ($key == "ID") $strParam = "/".$val;
			}
			$strParam .= "?".implode("&", $tmp);
		}

		$this->headerOut = array(
			'Content-Type: application/json',
			'Accept: application/json'
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/".$entity.$strParam,
			CURLOPT_CUSTOMREQUEST => 'GET',
		));

		$this->call();
		return json_decode($this->body, true);
	}


/*  Insert		HTTP Methode :
PUT / HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/json
Content-Type: application/json
If-None-Match: *  (opt)

{
  "CustomerID" : {value : "JOHNGOOD"},
  "CustomerName" : {value : "John Good"},
  "CustomerClass" : {value : "DEFAULT"},
  "MainContact" : 
    {
      "Email" : {value : "demo@gmail.com"},
      "Address" : 
        {
          "AddressLine1" : {value : "4030 Lake Washington Blvd NE"},
          "AddressLine2" : {value : "Suite 100"},
          "City" : {value : "Kirkland"},
          "State" : {value : "WA" },
          "PostalCode" : {value : "98033"}
        }      
    }  
}
*/
/**
*  Crée un enregistrement. Les contraintes de champs obligatoires s'appliquent.
*
*	@param		string	$entity	l'entité à créer
*	@param		array	$datas	tableau associatif de données
*	@return		array	tableau associatif de l'entité créée. Contient l'identifiant.
*/
	public function insert($entity, $datas){

		$this->headerOut = array(
			'Content-Type: application/json',
			'Accept: application/json',
			'If-None-Match: *'
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/".$entity,
			CURLOPT_CUSTOMREQUEST => 'PUT',
			CURLOPT_POSTFIELDS => json_encode($datas)
		));

		$this->call();
		return json_decode($this->body, true);
	}


/*  update   HTTP Methode :
PUT ?<parameters> HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/json
Content-Type: application/json

{
  "CustomerClass" : {value : "ECCUSTOMER"},
}
*/
/**
*  Met à jour un enregistrement
*
*	@param		string	$entity	l'entité à mettre à jour
*	@param		array	$datas	tableau associatif de données
*	@param		array	$params	tableau associatif de paramètres acceptant
*								les clefs  ID, select, filter, expand, top, skip, custom.
*								Utilisez ID ou filter.
*	@return		array	tableau associatif 
*/
	public function update($entity, $datas, $params = null){
		$listParam = array("select", "filter", "expand", "top", "skip", "custom");
		$strParam = "";
		if($params){
			$tmp = array();
			foreach($params as $key=>$val){ 
				if (in_array($key, $listParam)) $tmp[] = "$".$key."=".rawurlencode($val);
				if ($key == "ID") $strParam = "/".$val;
			}
			$strParam .= "?".implode("&", $tmp);
		}

		$this->headerOut = array(
			'Content-Type: application/json',
			'Accept: application/json',
			'If-Match: *',
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/".$entity.$strParam,
			CURLOPT_CUSTOMREQUEST => 'PUT',
			CURLOPT_POSTFIELDS => json_encode($datas)
		));

		return json_decode($this->call(), true);
	}


/*  delete   HTTP Methode :
DELETE /<entity id> HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/json
Content-Type: application/json
*/
/**
*  Supprime un enregistrement
*
*	@param		string	$entity	l'entité à supprimer
*	@param		array	$id	identifiant
*	@return		array	tableau associatif 
*/
	public function delete($entity, $id){

		$this->headerOut = array(
			'Content-Type: application/json',
			'Accept: application/json'
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/".$entity."/".$id,
			CURLOPT_CUSTOMREQUEST => 'DELETE',
		));

		return json_decode($this->call(), true);
	}


/* putFile HTTP Methode :
PUT /<entityID>/files/<fileName> HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/json
Content-Type: application/octet-stream

"<file contents here>"
*/
/**
*  lie un fichier à un enregistrement
*
*	@param		string	$entity	l'entité à laquelle est lié le fichier
*	@param		string	$entityID	clef 1 de l'entité  (ex: pour un client, le champ 'CustomerID' et non le champ 'id')
*	@param		string	$fichier	adresse du fichier. Attention : ne pas utiliser '\' comme séparateur
*/
	public function putFile($entity, $entityID, $fichier){

		$this->headerOut = array(
			'Content-Type: application/octet-stream',
			'Accept: application/json'
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/".$entity."/".$entityID."/files/".rawurlencode(basename($fichier)),
			CURLOPT_CUSTOMREQUEST => 'PUT',
			CURLOPT_POSTFIELDS => file_get_contents($fichier)
		));

		return $this->call();
	}


/* getFile HTTP Methode :
GET /<file id> HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/octet-stream
Content-Type: application/json
*/
/**
*  Récupère le contenu d'un fichier
*
*	@param		string	$fichier	identifiant du fichier
*	@return		string	contenu du fichier 
*/
	public function getFile($fichier){

		$this->headerOut = array(
			'Accept: application/octet-stream',
			'Content-Type: application/json'
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/files/".$fichier,
			CURLOPT_CUSTOMREQUEST => 'GET',
		));

		return $this->call();
	}


/*	action HTTP Methode
POST /<action> HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/json
Content-Type: application/json

{ 
	"entity" :
	{
		"OrderType" : {"value" : "SO"}, 
		"OrderNbr" : {"value" : "000001"} 
	},
	"parameters" : 
	{}
}
*/
/**
*  effectue une action
*
*	@param		string	$entity	l'entité à décrire
*	@param		string	$action	l'action à effectuer
*	@param		array	$params	Identification des éléments concernés et des paramètres de l'action
*	@return		array	tableau associatif sans valeur 
*/
	public function action($entity, $action, $params){

		$this->headerOut = array(
			'Content-Type: application/json',
			'Accept: application/json',
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/".$entity."/".$action,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => json_encode($params)
		));

		return $this->call();
	}

/**
*  récupère le contenu d'une requête Odata. Ne fonctionne pas avec loginBasic.
*
*	@param		string	$query	la requête à obtenir
*	@param		array	$params	tableau de paramêtres
*	@param		boolean	$singleTenant	vrai si une seul tenant sur le serveur
*	@return		array	tableau associatif  
*/
	public function oData($query, $params = array(), $singleTenant=true){
		$this->headerOut = array(
			'Content-Type: application/json',
			'Accept: application/json'
		);

		$params["format"] = "json";
		foreach($params as $key=>$val) $tmp[] = "$".$key."=".rawurlencode($val);
		$strParam = "?".implode("&", $tmp);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/OData/".($singleTenant?'': $this->tenant."/").$query.$strParam,
			CURLOPT_CUSTOMREQUEST => 'GET',
		));

		$this->call();
		$return = json_decode($this->body, true);
		return $return["value"];
	}

/* schema HTTP Methode :
GET /$adHocSchema HTTP/1.1
Host: <Acumatica ERP instance URL>/entity/<Endpoint name>/<Endpoint version>/<Top-level entity>
Accept: application/json
Content-Type: application/json
*/
/**
*  Décrit la structure de donnée d'une entité
*
*	@param		string	$entity	l'entité à décrire
*	@return		array	tableau associatif sans valeur 
*/
	public function schema($entity){

		$this->headerOut = array(
			'Accept: application/json'
		);
		
		$this->curl = curl_init();
		curl_setopt_array($this->curl, array(
			CURLOPT_URL => $this->instanceURL."/entity".$this->endPointURL."/".$entity."/\$adHocSchema",
			CURLOPT_CUSTOMREQUEST => 'GET',
		));

		$this->call();
		return json_decode($this->body, true);
	}


	private function call($connexion = false){
		if(!$connexion){
			if($this->connectionType == "basic") curl_setopt($this->curl, CURLOPT_COOKIEFILE, $this->cookie);
			if($this->connectionType == "ownerCredential") $this->headerOut[] = "Authorization: Bearer ".$this->token;
			if(!$this->connectionType){
				throw new Exception("Erreur Flex : Vous n'êtes pas connecté", 100);
				curl_close($this->curl);
				$this->headerOut = false;
				return false;
			}
		}

		if(is_array($this->headerOut)){
			curl_setopt_array ($this->curl, array(
				CURLOPT_HTTPHEADER => $this->headerOut,
				CURLOPT_HEADER => true
			));
		}

		curl_setopt_array($this->curl, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
		));

		$response = curl_exec($this->curl);
		
		if(curl_errno($this->curl)) throw new Exception("Erreur Flex cURL : ".curl_errno($this->curl)." - ".curl_error($this->curl), curl_errno($this->curl));
				
		$this->curlInfo = curl_getinfo($this->curl);
		$this->headers = $this->headersToArray( substr( $response, 0, $this->curlInfo['header_size']));
		$this->body = substr($response, $this->curlInfo['header_size']);
		curl_close($this->curl);
		$this->headerOut = false;
		
		switch ($this->curlInfo['http_code']){
			case 200 : 
				$this->httpTxtCode = "200 : The request has been completed successfully.";
				break;
			case 202 : 
				$this->httpTxtCode = "202 : The operation is in progress. The Location header of the response contains the URL that you can use to check the status of the operation by using the GET HTTP method. When the GET HTTP method with this URL returns 204 No Content, the operation is completed.";
				break;
			case 204 :
				$this->httpTxtCode = "204 : The request has been completed successfully.";
				break;
			case 400 :
				$this->httpTxtCode = "400 : The data specified in the request is invalid.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			case 401 :
				$this->httpTxtCode = "401 : The user is not signed in to the system.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			case 403 :
				$this->httpTxtCode = "403 : The user has insufficient rights to access the Acumatica ERP form that corresponds to the entity.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			case 404 :
				$this->httpTxtCode = "404 : The action with this name does not exist.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			case 412 :
				$this->httpTxtCode = "412 : You have used the If-None-Match header with the * value, and the record already exists.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			case 422 :
				$this->httpTxtCode = "422 :  The data specified in the request is invalid and the validation errors are returned in the error fields of the response body, as in the following example.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			case 429 :
				$this->httpTxtCode = "429 : The number of requests has exceeded the limit imposed by the license (see License Restrictions for API Users).";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			case 500 :
				$this->httpTxtCode = "500 : An internal server error has occurred.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
				break;
			default :
				$this->httpTxtCode = $this->curlInfo['http_code']." : Code http inconnu.";
				throw new Exception("Erreur Flex http ".$this->httpTxtCode, $this->curlInfo['http_code']);
		}							
							
		return $response;
	}


	private function headersToArray( $str ){
		$headers = array();
		$headersTmpArray = explode("\r\n", $str);
		for ($i = 0 ; $i < count($headersTmpArray) ; ++$i ){
			if (strlen($headersTmpArray[$i]) > 0 ){
				if (strpos($headersTmpArray[$i] , ":" )){
					$headerName = substr($headersTmpArray[$i], 0, strpos($headersTmpArray[$i], ":" ) );
					$headerValue = substr($headersTmpArray[$i], strpos($headersTmpArray[$i], ":" )+1 );
					$headers[$headerName] = $headerValue;
				}
			}
		}
		return $headers;
	}
}
?>