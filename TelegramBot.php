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

  protected $welcome_message = 'Welcome!';

  public function setWelcomeMessage(string $message) {
    $this->welcome_message = $message;
    return $this;
  }

  public function getWelcomeMessage() : string {
    return $this->welcome_message;
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

    $dbh->exec('CREATE TABLE IF NOT EXISTS `chats` (
      `id`	INTEGER,
      `date_added`	INTEGER,
      PRIMARY KEY(`id`)
    );');

    $this->httpclient = new Client([
      'base_uri' => 'https://api.telegram.org/bot'.$this->token.'/',
      'timeout'  => 2.0,
    ]);
  }

  public function queryApi(string $endpoint, array $parameters = []) {
    try {
      $response = $this->httpclient->request('GET', $endpoint, ['query' => $parameters, 'http_errors' => false]);
    } catch (ClientException $e) {
      // just catch everything because api returns statuscode 403 *and* JSON encoded body
      // error handling is up to the next layer
      return (string)$response->getBody();
    }
    return (string)$response->getBody();
  }

  protected function removeId($id) : bool {
    return $this->dbh->exec('DELETE FROM `chats` WHERE id = '.$this->dbh->quote($id));
  }

  /**
   * Broadcasts given message to all registered chats
   *
   * @param string $message Message to send
   * @return int Number of sent Messages (equals number active chats)
   */
  public function sendBroadcastMessage(string $message) : int {
    $count = 0;
    $result = $this->dbh->query('SELECT id FROM chats');
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
      if($result['error_code']??'' == 403 && preg_match('/blocked/i', $result['description']??'')) {
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
    $id = $update['message']['chat']['id'];
    if(isset($update['message']['left_chat_member']) || isset($update['message']['left_chat_participant'])) {
      if(
        $update['message']['left_chat_member']['id'] == $this->id
        ||
        $update['message']['left_chat_member']['id'] == $this->id
      ) {
        return $this->removeId($id);
      }
    }
    $result = $this->dbh->exec('INSERT INTO `chats`
      (id, date_added)
      VALUES
      ('.$this->dbh->quote($id).', '.$this->dbh->quote(time()).')'
    );
    // if chat_id is new
    if($result == 1 && !empty($this->welcome_message)) {
      $this->sendMessage($this->welcome_message, $this->dbh->lastInsertId());
      return true;
    }
    return false;
  }

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

  public function deleteWebhook() : bool {
    return $this->queryApi('deleteWebhook');
  }

  public function getWebhookInfo() : string {
    return $this->queryApi('getWebhookInfo');
  }
}
