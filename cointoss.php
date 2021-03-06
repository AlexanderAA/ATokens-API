<?
/*
 * cointoss.php
 * (c) 2010 ATokens
 *
 * Example application for the ATokens.com API
 *
 * This application implements a simple coin flipping game, with "winnings"
 * paid out via the ATokens.com API.
 *
 */

session_start();

/* Remove this line if you're talking to the public atokens.com site */
define('ATOKENS_OAUTH_URL_OVERRIDE', "https://atokens.com");
define('ATOKENS_API_BASE','http://localhost:5001/api');

require_once('OAuth.php');
require_once('AtokensOAuth.php');

/* Obtain these details from your app details page */
$ATOKENS_CONSUMER_KEY = "c00e1d4c3049dbe84baef062fa948334";
$ATOKENS_CONSUMER_SECRET = "aa612a81abf44e5037378c0107648dfc";

/* Which level of access your app requires. Unless you're providing tools to
  help the user manage their own account, this should almost always be 'basic' */
$ATOKENS_ACCESS = "basic";

/* This URL points to this script on your web server. If you're testing on a
  local apache/php instance then it's likely to be http://localhost/something
*/
$APP_CALLBACK_URL = "http://localhost/atokens-php/cointoss.php";
/* This is an account ID the application can access directly (the owner). This is
  used when receiving/sending credits etc */
$APP_ACCOUNT_ID = 2;

$consumer = new OAuthConsumer($ATOKENS_CONSUMER_KEY, $ATOKENS_CONSUMER_SECRET, $APP_CALLBACK_URL);


if (isset($_GET['logout'])) {
    /* Remove session variables. Useful mostly for the demo */
    unset($_SESSION['request_token']);
    unset($_SESSION['access_token']);
    header('Location: ' . $APP_CALLBACK_URL);
    die;
}

/* Do we have an access token? if not, we need to get one */
if (!isset($_SESSION['access_token'])) {
    /* Construct api */
    $api = new OAuthClient(new AtokensServiceProvider(), $consumer);
    
    /* Two possible conditions: either we're returning from the authorize request or not */
    
    /* Callback from authorize? */
    if (!(isset($_SESSION['request_token']) && isset($_GET['oauth_verifier']))) {
        /* No, we have no access token, we need to get one by generating a request token then
          asking the user to authorize it */
        
        /* Get request token */
        $request_token = $api->getRequestToken($ATOKENS_ACCESS, $APP_CALLBACK_URL);
        #print_r($request_token); # Useful if you're not sure you've got one
        $_SESSION['request_token'] = serialize($request_token);
        
        /* Redirect user to authorize URL (in this case, it'll be somewhere on atokens.com) */
        header("Location: " . $api->getAuthorizeUrl($request_token));
        die;
        
    } else {
        /* Yep, callback, so we're authorized and we can trade our request token for an access token */
        $request_token = unserialize($_SESSION['request_token']);
        $access_token = $api->getAccessToken($request_token, $_GET['oauth_verifier']);
        
        /* Put access token into session. It's all we need now */
        $_SESSION['access_token'] = serialize($access_token);
        
        /* Redirect to cointoss game, now with access token */
        header('Location: ' . $APP_CALLBACK_URL);
        die;
    }
}

/* If we reach this point, we have an access token. Yay! we can get information */

/* Get app api as well */
$app_api = new OAuthClient(new AtokensServiceProvider(), $consumer);
$app_token = $app_api->selfAuthorize($APP_ACCOUNT_ID);
$app_api = new AtokensClient(new AtokensServiceProvider(), $consumer, $app_token);

/* Construct api object*/
$user_api = new AtokensClient(new AtokensServiceProvider(), $consumer, unserialize($_SESSION['access_token']));

/* Obtain the users balance */
$account = $user_api->summary();

/* Obtain the applications balance */
$app_account = $app_api->summary();

?>
Accessing <?=$account->account?> <?=$account->name?>, with a default balance of <?=$account->default_balance?>ec.
<br />
This app is owned by <?=$app_account->name?>, with a balance of <?=$app_account->default_balance?>ec.
<br />
Now offering 50 credits to ourselves
<?
$new_balance = $app_api->offer($app_account->default_balance_id, $account->account, 50, "Test test wheee", 14);
?>
with resulting balance of <?=$new_balance?>
<br />
Now moving 5 credits from them to us
<?
$prepare_id = $app_api->prepare($app_account->default_balance_id, $account->account, 5, "Test test prepare");
?>
prepare id is <?=$prepare_id?><br />
committing<br />
<?
$app_api->commit($account->default_balance_id, $prepare_id);
$account = $user_api->summary();
?>
Final balance is <?=$account->default_balance?>
