<?php
if ( !defined( 'MEDIAWIKI' )) {
	die('This is a MediaWiki extension, and must be run from within MediaWiki.');
}

//require_once('submodules/OAuth2-Client/OAuth2Client.php');

class SpecialOAuth2Dataporten extends SpecialPage {

	private $client;
	private $table = 'dataporten_users';

	public function __construct() {
		if( !self::OAuthEnabled() ) return;

		parent::__construct('OAuth2Dataporten');
		global $wgOAuth2Dataporten, $wgServer, $wgArticlePath;

		$this->client = new OAuth2([
			"client_id" 		 => $wgOAuth2Dataporten['client']['id'],
			"client_secret" 	 => $wgOAuth2Dataporten['client']['secret'],
			"redirect_uri" 		 => $wgServer . str_replace( '$1', 'Special:OAuth2Dataporten/callback', $wgArticlePath),
			"auth" 				 => $wgOAuth2Dataporten['config']['auth_endpoint'],
			"token" 			 => $wgOAuth2Dataporten['config']['token_endpoint'],
			//$wgOAuth2Dataporten['config']['info_endpoint'],
			"authorization_type" => $wgOAuth2Dataporten['config']['auth_type']]);
	}

	public function execute( $parameter ) {
		$this->setHeaders();
		switch($parameter) {
			case 'redirect':
				$this->_redirect();
				break;
			case 'callback':
				$this->_callback();
				break;
			default:
				$this->_default();
				break;
		}
	}

	private function _logout() {
		global $wgOAuth2Dataporten, $wgOut, $wgUser;
		if( $wgUser->isLoggedIn() ) $wgUser->logout();

	}

	private function _redirect() {
		global $wgRequest;

		$state = uniqid('', true);
		$url   = $wgRequest->getVal('returnto');
		
		$dbw = wfGetDB(DB_MASTER);
		$dbw->insert( 'dataporten_states', 
			array( 'state' => $state,
				   'return_to' => $url ),
				   'Database::insert' );
		$dbw->begin();
		$this->client->redirect($state);
	}

	private function _callback() {
		global $wgOAuth2Dataporten, $wgOut, $wgRequest;

		$dbr = wfGetDB(DB_SLAVE);
		$row = $dbr->selectRow(
			'dataporten_states',
			'*',
			array('state' => $wgRequest->getVal('state')));

		$row = json_decode(json_encode($row),true);
		if(!$row) {
			throw new MWException('States differ');
		}

		$dbw = wfGetDB(DB_MASTER);
		$dbw->delete('dataporten_states',
					 array('state' => $wgRequest->getVal('state')));
		$dbw->begin();

		$access_token = $this->client->get_access_token();
		if( !$access_token ) {
			throw new MWException('Something went wrong fetching the access token');
		}

		$credentials = $this->fix_return($this->client->get_identity($access_token, $wgOAuth2Dataporten['config']['info_endpoint']));
		$groups = $this->client->get_identity($access_token, $wgOAuth2Dataporten['config']['group_endpoint']);

		$user = $this->userHandling($credentials);
		$user->setCookies();

		$this->add_user_to_groups($user, $groups);

		if($row['return_to']) {
			$title = Title::newFromText($row['return_to']);
		} else {
			$title = Title::newMainPage();
		}

		$wgOut->redirect($title->getFullUrl());

		return true;
	}

	private function add_user_to_groups($user, $groups) {
		foreach($groups as $key => $value) {
			$user->addGroup($groups[$key]["id"]);
		}
	}

	private function fix_return($response) {

		if(isset($response['name'])) {
			$name = $response['name'];
		} else if(isset($response['user']['name'])) {
			$name = $response['user']['name'];
		}

		if(isset($response['id'])) {
			$id = $response['id'];
		} else if(isset($response['userid'])) {
			$id = $response['userid'];
		} else if(isset($response['user_id'])) {
			$id = $response['user_id'];
		} else if(isset($response['user']['userid'])) {
			$id = $response['user']['userid'];
		} else if(isset($response['user']['user_id'])) {
			$id = $response['user']['user_id'];
		}

		if(isset($response['email'])) {
			$email = $response['email'];
		} else if(isset($response['user']['email'])) {
			$email = $response['user']['email'];
		}

		$oauth_identity = array(
			'id' 	   => $id,
			'email'    => $email,
			'name'     => $name,
		);

		return $oauth_identity;
	}

	private function _default() {
		global $wgOAuth2Dataporten, $wgOut, $wgUser, $wgExtensionAssetsPath;
		/*
		$wg->setPagetitle(wfMsg('dataporten-login-header', 'Dataporten'));
		/*if(!$wgUser->isLoggedIn()) {
			$wgOut->addWikiMsg('dataporten-you-can-login-to-this-wiki')
		}*/
		return true;
	}

	private function userHandling($credentials) {
		global $wgOAuth2Dataporten, $wgAuth;

		$name 		= $credentials['name'];
		$id 		= $credentials["id"]; 
		$email 		= $credentials["email"];
		$externalId = $id;
		$dbr 		= wfGetDB(DB_SLAVE);
		$row 		= $dbr->selectRow(
			$this->table,
			'*',
			array('external_id' => $externalId)
		);

		if($row) { 				//Dataporten-user already exists
			return User::newFromId($row->internal_id);
		}
		$user = User::newFromName($id, 'creatable');
		if( false === $user || $user->getId() != 0) {
			throw new MWException('Unable to create user.');
		}
		$user->setRealName($name);
		if ( $wgAuth->allowPasswordChange() ) {
			$user->setPassword(User::randomPassword());
		}
		if($email) {
			$user->setEmail($email);
			$user->setEmailAuthenticationTimestamp(time());
		}
		$user->addToDatabase();
		$dbw = wfGetDB(DB_MASTER);
		$dbw->replace(
			$this->table,
			array('internal_id', 'external_id'),
			array('internal_id' => $user->getId(),
				  'external_id' => $externalId),
			__METHOD__);
		return $user;
	}

	public static function OAuthEnabled() {
		global $wgOAuth2Dataporten;
		return isset(
			$wgOAuth2Dataporten['client']['id'],
			$wgOAuth2Dataporten['client']['secret'],
			$wgOAuth2Dataporten['config']['auth_endpoint'],
			$wgOAuth2Dataporten['config']['token_endpoint'],
			$wgOAuth2Dataporten['config']['info_endpoint'],
			$wgOAuth2Dataporten['config']['auth_type']
		);
	}

}