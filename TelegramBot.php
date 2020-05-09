<?php
namespace JuliusPC;

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
   * Creates new TelegramBot instance
   * 
   * @param \PDO $dbh PDO instance used for storing known chat_ids
   * @param string $token The token obtained from <https://t.me/BotFather>
   */
  public function __construct(\PDO $dbh, string $token) {
    $this->dbh = $dbh;
    $this->token = trim($token);
    $this->id = trim(explode(':', $this->token)[0]);

    $dbh->exec('CREATE TABLE IF NOT EXISTS `tgnb_chats` (
      `chat_id`	INTEGER,
      `date_added`	INTEGER,
      PRIMARY KEY(`id`)
    );');
    
    $dbh->exec('CREATE TABLE IF NOT EXISTS `tgnb_updates` (
      `update_id`	INTEGER,
      `date_added`	INTEGER,
      `update_json`	TEXT,
      PRIMARY KEY(`id`)
    );');

    $dbh->exec('CREATE TABLE IF NOT EXISTS `tgnb_messages` (
      `chat_id`	INTEGER,
      `message_id` INTEGER,
      `date_added`	INTEGER,
      `chosen_id`	TEXT,
      PRIMARY KEY(`chat_id`, `message_id`)
    );');

    $dbh->exec('DELETE FROM `tgnb_updates` WHERE `date_added` < '.$dbh->quote((time()- 3600*24*7)));

    $dbh->exec('DELETE FROM `tgnb_messages` WHERE `date_added` < '.$dbh->quote((time()- 3600*24*7)));

    $this->httpclient = new Client([
      'base_uri' => 'https://api.telegram.org/bot'.$this->token.'/',
      'timeout'  => 2.0,
    ]);
  }

  /**
   * Call the api with parameters.
   * 
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
   * 
   * @param string $id chat_id
   * @return bool success
   */
  protected function removeId(string $id) : bool {
    $sth = $this->dbh->prepare('DELETE FROM `tgnb_chats` WHERE `chat_id` = ?');
    $sth->bindValue(1, $id, \PDO::PARAM_STR);
    return $sth->execute();
  }

  /**
   * Broadcasts given message to all registered chats
   *
   * @param string $message Message to send
   * @param string $chosen_id An identifier you can provide to identify the message when you want to edit it with editBroadcastMessage()
   * @return int Number of sent Messages (equals number active chats)
   */
  public function sendBroadcastMessage(string $message, string $chosen_id) : int {
    $count = 0;
    $result = $this->dbh->query('SELECT `chat_id` FROM `tgnb_chats`');
    $sth = $this->dbh->prepare('INSERT INTO `tgnb_messages` (`chat_id`, `message_id`, `date_added`, `chosen_id`)
    VALUES (?, ?, ?, ?)');
    while($row = $result->fetch(\PDO::FETCH_ASSOC)) {
      if($message_id = $this->sendMessage($message, $row['chat_id'])) {
        $sth->bindValue(1, $row['chat_id'], \PDO::PARAM_STR);
        $sth->bindValue(2, $message_id, \PDO::PARAM_STR);
        $sth->bindValue(3, time(), \PDO::PARAM_INT);
        $sth->bindValue(4, $chosen_id, \PDO::PARAM_STR);
        $sth->execute();
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
   * @return string The sent message’s id.
   */
  public function sendMessage(string $text, string $chat_id, string $parse_mode = 'HTML') : string {
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
    return $result['result']['message_id'];
  }

  /**
   * Edit a single Message identified by its chat_id and message_id by providing a new text.
   * 
   * @param string $text text
   * @param string $chat_id chat_id
   * @param string $message_id message_id
   * @param string $parse_mode parse_mode (default HTML)
   * @return string JSON encoded result
   */
  public function editMessageText(string $text, string $chat_id, string $message_id, string $parse_mode = 'HTML') : string {
    return $this->queryApi('editMessageText', compact('text', 'chat_id', 'message_id', 'parse_mode'));
  }

  /**
   * Edits an already sent broadcasted message in all chats
   *
   * @param string $message new message text
   * @param string $chosen_id The identifier you provided by sending the original message with sendBroadcastMessage()
   * @return int Number of edited Messages
   */
  public function editBroadcastMessage(string $message, string $chosen_id) : int{
    $sth = $this->dbh->prepare('SELECT `message_id`, `chat_id` FROM `tgnb_messages` WHERE `chosen_id` = ?');
    $sth->bindValue(1, $chosen_id);
    $sth->execute();
    $counter = 0;
    while($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
      $counter += (int)$this->editMessageText($message, $row['chat_id'], $row['message_id']);
    }
    return $counter;
  }

  /**
   * Deletes Message. This is only in the first 48 hours after sending possible.
   * 
   * @param string $chat_id chat_id
   * @param string $message_id message_id
   * @return bool true if message was deleted
   */
  public function deleteMessage(string $chat_id, string $message_id) : bool {
    $sth = $this->dbh->prepare('DELETE FROM `tgnb_messages` WHERE `chat_id` = ? AND `message_id` = ?');
    $sth->bindValue(1, $chat_id, \PDO::PARAM_STR);
    $sth->bindValue(2, $message_id, \PDO::PARAM_STR);
    return $sth->execute() && $this->queryApi('deleteMessage', compact('message_id', 'chat_id'));
  }

  /**
   * Deletes broadcasted messages identified by the id you have to choose when sending the messages.
   * 
   * @param string $chosen_id The id you have chosen to identify the sent messages.
   * @return int Number of deleted messages.
   */
  public function deleteBroadcastMessage(string $chosen_id) : int {
    $sth = $this->dbh->prepare('SELECT `message_id`, `chat_id` FROM `tgnb_messages` WHERE `chosen_id` = ?');
    $sth->bindValue(1, $chosen_id, \PDO::PARAM_STR);
    $sth->execute();
    $counter = 0;
    while($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
      $counter += (int)$this->deleteMessage($row['chat_id'], $row['message_id']);
    }
    return $counter;
  }

  /**
   * processes multiple updates as provided by getUpdates()
   * 
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
    $sth = $this->dbh->prepare('INSERT INTO `tgnb_updates`
    (update_id, date_added, update_json)
    VALUES
    (?, ?, ?)');
    $sth->bindValue(1, $update['update_id'], \PDO::PARAM_STR);
    $sth->bindValue(2, time(), \PDO::PARAM_INT);
    $sth->bindValue(3, \json_encode($update, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), \PDO::PARAM_STR);
    $result = $sth->execute();
    // update_id already exists, discard
    if(!$result) {
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
      if($update['message']['left_chat_member']['id'] == $this->id
        || $update['message']['left_chat_member']['id'] == $this->id) {
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
   * 
   * @param string $id chat_id
   * @param bool $silent If true don’t send welcome message.
   * @return bool success
   */
  public function addIdIfNotExists(string $id, bool $silent = false) {
    $sth = $this->dbh->prepare('INSERT INTO `tgnb_chats`
    (chat_id, date_added)
    VALUES
    (?, ?)');
    $sth->bindValue(1, $id, \PDO::PARAM_STR);
    $sth->bindValue(2, time(), \PDO::PARAM_INT);
    $result = $sth->execute();
    // if chat_id is new
    if($result) {
      if (!$silent && !empty($this->welcome_message)) {
        return $this->sendMessage($this->welcome_message, $this->dbh->lastInsertId());
      }
      return true;
    }
    return false;
  }

  /**
   * Sets the bots supported commands.
   * 
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
   * Handles bot commands. Extend this method if you want to implement custom commands.
   * 
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
   * 
   * @param string $html HTML formatted input.
   * @return string Stripped down to bare formatting HTML.
   */
  public function stripHTML(string $html) {
    return \strip_tags($string, '<b><strong><i><em><u><ins><s><strike><del><a><code><pre>');
  }
}
