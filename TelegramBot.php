<?php
namespace JuliusPC;

require 'vendor/autoload.php';

use GuzzleHttp\ {
  Client,
  Exception\ClientException
};

class TelegramBot {

  protected $dbh;
  protected $token;
  protected $id;
  protected $httpclient;

  protected $welcome_message = 'Hi!';
  protected $stop_message = 'stopped';

  /**
   * Set welcome message for new users.
   * @param strin $message The message.
   */
  public function setWelcomeMessage(string $message) {
    $this->welcome_message = $message;
    return $this;
  }

  /**
   * Get set welcome message for new users.
   */
  public function getWelcomeMessage() : string {
    return $this->welcome_message;
  }

  /**
   * Set stop message for users quitting the bot.
   */
  public function setStopMessage(string $message) {
    $this->stop_message = $message;
    return $this;
  }

  /**
   * Get set stop message for users quitting the bot.
   */
  public function getStopMessage() : string {
    return $this->stop_message;
  }

  /**
   * Constructs new TelegramBot instance
   * @param \PDO $dbh PDO instance used for storing known chat_ids
   * @param string $token The token obtained from <https://t.me/BotFather>
   */
  public function __construct(\PDO $dbh, string $token) {
    $this->dbh = $dbh;
    $this->token = trim($token);
    $this->id = trim(explode(':', $this->token)[0]);

    $dbh->exec('CREATE TABLE IF NOT EXISTS `tgnb_chats` (
      `id`	INTEGER,
      `date_added`	INTEGER,
      PRIMARY KEY(`id`)
    );');
    
    $dbh->exec('CREATE TABLE IF NOT EXISTS `tgnb_updates` (
      `id`	INTEGER,
      `date_added`	INTEGER,
      `update_json`	TEXT,
      PRIMARY KEY(`id`)
    );');

    $dbh->exec('DELETE FROM `tgnb_updates` WHERE `date_added` < '.$dbh->quote((time()- 3600*24*7)));

    $this->httpclient = new Client([
      'base_uri' => 'https://api.telegram.org/bot'.$this->token.'/',
      'timeout'  => 2.0,
    ]);
  }

  /**
   * Call the api with parameters.
   * @param string $endpoint Name of endpoint without prefix /
   * @param array $parameters Associative array with parameters to send.
   */
  public function queryApi(string $endpoint, array $parameters = []) {
    try {
      $response = $this->httpclient->request('POST', $endpoint, ['query' => $parameters, 'http_errors' => false]);
    } catch (ClientException $e) {
      // just catch everything because api returns statuscode 403 *and* JSON encoded body
      // error handling is up to the next layer
      return (string)$response->getBody();
    }
    return (string)$response->getBody();
  }

  /**
   * removes given chat_id from the database
   * @param string $id chat_id
   * @return bool success
   */
  protected function removeId(string $id) : bool {
    return $this->dbh->exec('DELETE FROM `tgnb_chats` WHERE id = '.$this->dbh->quote($id));
  }

  /**
   * Broadcasts given message to all registered chats
   *
   * @param string $message Message to send
   * @return int Number of sent Messages (equals number active chats)
   */
  public function sendBroadcastMessage(string $message) : int {
    $count = 0;
    $result = $this->dbh->query('SELECT id FROM tgnb_chats');
    while($row = $result->fetch(\PDO::FETCH_ASSOC)) {
      if($this->sendMessage($message, $row['id'])) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Sends Message to given chat_id. Removes chat_id from database in case user blocked bot.
   *
   * @param string $text Message text
   * @param string $chat_id The chat’s id
   * @param string $parse_mode either HTML, MarkdownV2 or Markdown – default is HTML
   */
  protected function sendMessage(string $text, string $chat_id, string $parse_mode = 'HTML') : bool {
    $result = json_decode(
      $this->queryApi(
        'sendMessage',
        compact('chat_id', 'text', 'parse_mode')
      ),
      true);
    if(!($result['ok']??true)) {
      if($result['error_code']??'' == 403) {
        $this->removeId($chat_id);
      }
      return false;
    }
    return true;
  }

  /**
   * processes multiple updates as provided by getUpdates()
   * @param string $update JSON encoded update
   * @return int Number of processed updates
   */
  public function processUpdates(string $update) : int {
    $count = 0;
    $update = json_decode($update, true);
    $ids = [];
    if(!$update['ok']) {
      return false;
    }

    if($update->ok??false) {
      foreach ($update->result as $update) {
        if($this->processUpdate($update)) {
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * processes single update. Removes chat_id from database if bot was removed from chat.
   *
   * @param array $update single update as assoziative array
   * @return bool True if update was processed successful
   */
  public function processUpdate(array $update) : bool {
    $result = $this->dbh->exec('INSERT INTO `tgnb_updates`
      (id, date_added, update_json)
      VALUES
      ('.$this->dbh->quote($update['update_id']).', '.$this->dbh->quote(time()).', '.$this->dbh->quote(\json_encode($update,  JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)).')');
    // update_id already exists, discard
    if($result != 1) {
      return true;
    }
    $id = $update['message']['chat']['id'];
    if($update['message']['entities'][0]['type']??'' == 'bot_command') {
      preg_match('#^(\/)?([^\s@]+)#i', $update['message']['text'], $matches);
      if(isset($matches[2]) && !empty($matches[2]) && $matches[2] != 'start') {
        return $this->executeCommand($matches[2], $update);
      }
    }
    if(isset($update['message']['left_chat_member']) || isset($update['message']['left_chat_participant'])) {
      if(
        $update['message']['left_chat_member']['id'] == $this->id
        ||
        $update['message']['left_chat_member']['id'] == $this->id
      ) {
        return $this->removeId($id);
      }
    }
    
    return $this->addIdIfNotExists($id);
  }

  /**
   * Pull updates from the api. Does not work when webhook is set.
   */
  public function getUpdates() : string {
    return $this->queryApi('getUpdates');
  }

  /**
   * sets Webhook
   *
   * @param string $url URL of the webhook
   * @return bool True if operation was successful
   */
  public function setWebhook(string $url) : bool {
    if(preg_match('%^((https://)|(www\.))([a-z0-9-].?)+(:[0-9]+)?(/.*)?$%i', $url)) {
      $result = json_decode($this->queryApi('setWebhook', compact('url')), true);
      return $result['ok']??false;
    }
    return false;
  }

  /**
   * deletes set webhook
   */
  public function deleteWebhook() : bool {
    return $this->queryApi('deleteWebhook');
  }

  /**
   * get info about the set webhook
   */
  public function getWebhookInfo() : string {
    return $this->queryApi('getWebhookInfo');
  }

  /**
   * Adds chat_id to database if it not already exists
   * @param string $id chat_id
   * @param bool $silent If true don’t send welcome message.
   * @return bool success
   */
  public function addIdIfNotExists(string $id, bool $silent = false) {
    $result = $this->dbh->exec('INSERT INTO `tgnb_chats`
      (id, date_added)
      VALUES
      ('.$this->dbh->quote($id).', '.$this->dbh->quote(time()).')'
    );
    // if chat_id is new
    if($result == 1) {
      if (!$silent && !empty($this->welcome_message)) {
        return $this->sendMessage($this->welcome_message, $this->dbh->lastInsertId());
      }
      return true;
    }
    return false;
  }

  /**
   * Sets the bots supported commands.
   * @param string $commands JSON encoded String of commands: json_encode([['command'=>'', 'description'=>'']])
   * @return bool success
   */
  public function setMyCommands(string $commands) : bool {
    return $this->queryApi('setMyCommands', ['commands' => $commands]);
  }

  /**
   * get already set commands from the api
   */
  public function getMyCommands() : string {
    return $this->queryApi('getMyCommands');
  }

  /**
   * Extend this method if you need to implement custom commands.
   * @param string $command parsed command without leading /
   * @param array $update Update object from API
   * @return bool success 
   */
  protected function executeCommand(string $command, array $update) : bool {
    $id = $update['message']['chat']['id']??'';
    switch ($command) {
      case 'stop':
        $this->sendMessage($this->stop_message, $id);
        return $this->removeId($id);

      case 'start':
        return $this->addIdIfNotExists($id);
      
      default:
        return false;
    }
  }

  /**
   * Prepare HTML for bots as they can’t send arbitrary HTML. This is not a HTML sanitzer as HTML Purifier is!
   * @param string $html HTML formatted input.
   * @return string Stripped down to bare formatting HTML.
   */
  public function sanitizeHTML(string $html) {
    return \strip_tags($string, '<b><strong><i><em><u><ins><s><strike><del><a><code><pre>');
  }
}
