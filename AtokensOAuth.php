<?php
 
/**
 * A General purpose client for OAuth APIs
 *
 * @requires OAuth-php (http://oauth.googlecode.com/svn/code/php/) *
 * @copyright OAuth.net (C) 2009
 *
 * Special thanks to Morten Fangel for providing this code via gist for
 * use in this library.
 */
 
/**
 * The exception thrown when something bad happens
 */
class OAuthClientException extends Exception {}
 
if (!defined('ATOKENS_API_BASE')) {
	define('ATOKENS_API_BASE','https://atokens.com/api');
}

/**
 * A class to identify different service providers
 * @author Morten Fangel <fangel@sevengoslings.net>
 */
class OAuthServiceProvider {
	private $request_token_uri;
	private $authorization_uri;
	private $self_authorize_uri;
	private $access_token_uri;
	
	/**
	 * @param string $rt URI of the 'request token' endpoint
	 * @param string $a  URI of the 'authorize request token' endpoint
	 * @param string $sa  URI of the 'self-authorize request token' endpoint
	 * @param string $at URI of the 'access token' endpoint
	 */
	public function __construct($rt, $a, $sa, $at) {
		$this->request_token_uri = $rt;
		$this->authorization_uri = $a;
		$this->self_authorize_uri = $sa;
		$this->access_token_uri  = $at;
	}
	
	public function request_token_uri() { return $this->request_token_uri; }
	public function authorization_uri() { return $this->authorization_uri; }
	public function self_authorize_uri() { return $this->self_authorize_uri; }
	public function access_token_uri()  { return $this->access_token_uri; }
}

/**
 * Atokens Service Provider
 */
class AtokensServiceProvider extends OAuthServiceProvider {
    public function __construct() {
        parent::__construct(
            "http://localhost:5001/api/oauth/request_token",
            "http://localhost:5001/api/oauth/authorize",
            "http://localhost:5001/api/oauth/self_authorize",
            "http://localhost:5001/api/oauth/access_token"
        );
    }
}

class OAuthClient {
	private $service_provider;
	private $oauth_consumer;
	private $oauth_token;
	private $hmac_signature_method;
  
	/**
	 * Create a new instance
	 * @param OAuthConsumer $c Your consumer info
	 * @param OAuthToken $t Your AccessToken (null if none)
	 */
	public function __construct( OAuthServiceProvider $sp, OAuthConsumer $c, OAuthToken $t = null ) {
		$this->service_provider = $sp;
		$this->oauth_consumer = $c;
		$this->oauth_token = $t;
		$this->hmac_signature_method = $hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
 
	}
 
	/**
	 * Fetches a new RequestToken for you to use..
	 * @throws OAuthClientException
	 * @return OAuthToken
	 */
	public function getRequestToken( $access_type, $callback = 'oob') {
		$req = OAuthRequest::from_consumer_and_token(
			$this->oauth_consumer,
			null,
			'GET',
			$this->service_provider->request_token_uri()
		);
		$req->set_parameter('oauth_callback', $callback);
                $req->set_parameter('access_type', $access_type);

		$token_str = $this->_performRequest($req);
		parse_str($token_str, $token_arr);
 
		if( isset($token_arr['oauth_token'], $token_arr['oauth_token_secret']) ) {
			return new OAuthToken($token_arr['oauth_token'], $token_arr['oauth_token_secret']);
		} else {
			return null;
		}
	}
 
	/**
	 * Returns the URL you can direct the user to for authorization
	 * @param OAuthToken $request_token
	 * @param string $callback_url
	 * @return string
	 */
	public function getAuthorizeUrl( OAuthToken $request_token ) {
		$url = $this->service_provider->authorization_uri() . '?oauth_token=' . $request_token->key;
		return $url;
	}
 
	/**
	 * Exchanges a RequestToken for a AccessToken
	 * @param OAuthToken $request_token
	 * @return OAuthToken
	 * @throws OAuthClientException
	 */
	public function getAccessToken( OAuthToken $request_token, $verifier) {
		$req = OAuthRequest::from_consumer_and_token(
			$this->oauth_consumer,
			$request_token,
			'GET',
			$this->service_provider->access_token_uri()
		);
		$req->set_parameter('oauth_verifier', $verifier);
 
		$token_str = $this->_performRequest($req, $request_token);
		parse_str($token_str, $token_arr);
 
		if( isset($token_arr['oauth_token'], $token_arr['oauth_token_secret']) ) {
			return new OAuthToken($token_arr['oauth_token'], $token_arr['oauth_token_secret']);
		} else {
			return null;
		}
	}

	/**
	 * Self-authorize for a given account
	 * @param int $account
	 * @return OAuthToken
	 */
	public function selfAuthorize($account) {
		$request_token = $this->getRequestToken('full','oob');
		$req = OAuthRequest::from_consumer_and_token(
			$this->oauth_consumer,
			$request_token,
			'GET',
			$this->service_provider->self_authorize_uri()
		);
		$req->set_parameter('account', $account);
		$req->set_parameter('request_token', $request_token->key);
		$v_result = $this->_performRequest($req, $request_token);
		parse_str($v_result,$verifier_result);
		return $this->getAccessToken($request_token, $verifier_result['oauth_verifier']);
	}

	/**
	 * Call a URI at the SP signed..
	 * @param string $uri
	 * @param array $params
	 * @return string
	 * @throws OAuthClientException;
	 */
	public function call( $uri, $params ) {
		if( !$this->oauth_token ) return array();
 
		$req = OAuthRequest::from_consumer_and_token(
			$this->oauth_consumer,
			$this->oauth_token,
			'GET',
			$uri,
			$params
		);
 
		return $this->_performRequest($req);
	} 

	/**
	 * Performs a OAuthRequest, returning the response
	 * You can give a token to force signatures with this
	 * token. If none given, the token used when creating
	 * this instance of CampusNotesAPI is used
	 * @param OAuthRequest $req
	 * @param OAuthToken $token 
	 * @return string
	 * @throws CNApiException
	 */
	private function _performRequest( OAuthRequest $req, OAuthToken $token = null ) {
		$token = ($token) ? $token : $this->oauth_token;
		$req->sign_request($this->hmac_signature_method, $this->oauth_consumer, $token);

		$curl = curl_init();
 
		$params = $req->get_parameters();
 
		foreach( array_keys($params) AS $i )
			if( substr($i, 0, 6) == 'oauth_' ) 
				unset($params[$i]);
 
 
		$url = $req->get_normalized_http_url();
		if( $req->get_normalized_http_method() == 'POST' ) {
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params) );
		} else {
			if( count($params) )
				$url .= '?' . http_build_query($params);
		}
 
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);		
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			$req->to_header()
		));
 
		$rtn = curl_exec($curl);
 
		if( !$rtn ) {
			throw new OAuthClientException( curl_error($curl) );
		} else if( curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200 ) {
			throw new OAuthClientException( $rtn );
		} else {
			return $rtn;
		}
	}
}

class AtokensClient extends OAuthClient {
	/**
	 * Gets a summary of the current account
	 * @return array
	 */
	public function summary() {
		return json_decode($this->call(ATOKENS_API_BASE."/account/summary", array()));
	}
	
	/**
	 * Send a credit offer
	 * @param int $from_balance Balance credits should be deducted from
	 * @param int $to_account Account credits are being offered to
	 * @param int $amount Amount of credits to offer
	 * @param string $note Short text note to include with offer
	 * @param int days $days Number of days until offer expires and credits are returned
	 * @return int Updated balance for this account
	 */
	public function offer($from_balance, $to_account, $amount, $note, $days=14) {
		$result = json_decode($this->call(ATOKENS_API_BASE."/credit/offer", array(
			"from_balance" => $from_balance,
			"to_account" => $to_account,
			"amount" => $amount,
			"note" => $note,
			"days" => $days
		)));
		return $result->balance;
	}
	
	/**
	 * Prepare a credit transfer
	 * @param int $from_balance Balance credits should be deducted from
	 * @param int $to_account Account credits are being transferred to
	 * @param int $amount Amount of credits to transfer
	 * @param string $note Short text note to include with transfer
	 * @return string Prepare ID
	 */
	public function prepare($from_balance, $to_account, $amount, $note) {
		$result = json_decode($this->call(ATOKENS_API_BASE."/credit/prepare", array(
			"from_balance" => $from_balance,
			"to_account" => $to_account,
			"amount" => $amount,
			"note" => $note
		)));
		return $result->prepare_id;
	}
	
	/**
	 * Commit a credit transfer
	 * @param int $to_balance Balance to commit credits to
	 * @param string $prepare_id Prepare ID
	 */
	public function commit($to_balance, $prepare_id) {
		$result = json_decode($this->call(ATOKENS_API_BASE."/credit/commit", array(
			"to_balance" => $to_balance,
			"prepare_id" => $prepare_id
		)));
	}
	
	
}
?>