<?php
require __DIR__ . '/vendor/autoload.php';
if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
class GmailClient {
    const CREDS = 'credentials.json';
    const ALERT_MAIL = 'TradingView <noreply@tradingview.com>';
    const ALERT_HEADER = 'TradingView Alert: ';

    private $userId = 'me';
    public $oldMessagesIds = array();

    public $cli;
    public $logger;

    public function __construct() {

        $client = new Google_Client();
        $client->setApplicationName('Gmail API PHP Quickstart');
        $client->setScopes(Google_Service_Gmail::GMAIL_READONLY);
        $client->setAuthConfig(self::CREDS);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        // Load previously authorized token from a file, if it exists.
        // The file token.json stores the user's access and refresh tokens, and is
        // created automatically when the authorization flow completes for the first
        // time.
        $tokenPath = 'token.json';
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired.
        if ($client->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // Request authorization from the user.
                $authUrl = $client->createAuthUrl();
                printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = trim(fgets(STDIN));

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }
            }
            // Save the token to a file.
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        $this->cli = $client;
        $this->logger = $logger;
    }

    public function listMessages() {
      $pageToken = NULL;
      $messages = array();
      $opt_param = array();
      $service =  new Google_Service_Gmail($this->cli);

      do {
        try {
          if ($pageToken) {
            $opt_param['pageToken'] = $pageToken;
          }
          $messagesResponse = $service->users_messages->listUsersMessages($this->userId, $opt_param);
          if ($messagesResponse->getMessages()) {
            $messages = array_merge($messages, $messagesResponse->getMessages());
            $pageToken = $messagesResponse->getNextPageToken();
          }
        } catch (Exception $e) {
            return $e;
        }
      } while ($pageToken);

      return $messages;
    }

    public function populateMessagesIds() {
        try {
            $messages = $this->listMessages();
            foreach($messages as $message) {
                array_push($this->oldMessagesIds, $message['id']);
            }
        } catch (Exception $e) {
            return $e;
        }
    }

    public function getMessage($messageId) {
        $service = new Google_Service_Gmail($this->cli);

        try {
            $message = $service->users_messages->get($this->userId, $messageId);
            return $message;
        } catch (Exception $e) {
            return $e;
        }
    }

    public function isMessageAlert($message) {

        try {
            $headers = $message->getPayload()->getHeaders();
            foreach($headers as $header) {
                if($header['name'] == 'From' and $header['value'] == self::ALERT_MAIL) {
                    return True;
                }
            }
            return False;
        } catch (Exception $e) {
            return $e;
        }
    }

    public function getAlertSubject($message) {
        try {
            $headers = $message->getPayload()->getHeaders();
            foreach($headers as $header) {
                if($header['name'] == 'Subject') {
                    return str_replace(self::ALERT_HEADER, '', $header['value']);
                }
            }
            return False;
        } catch (Exception $e) {
            return $e;
        }
    }

    public function getNewMessagesIds() {
        $newMessagesIds = array();
        try {
            $messages = $this->listMessages();
            foreach($messages as $message) {
                if(!in_array($message['id'], $this->oldMessagesIds)) {
                    array_push($newMessagesIds, $message['id']);;
                }
            }
            return $newMessagesIds;
        } catch (Exception $e) {
            return $e;
        }
    }

//    public function getMessageSender(
}

?>
