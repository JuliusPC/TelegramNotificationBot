<?php
namespace JuliusPC;

class TelegramBot {

  protected $dbh;
  protected $token;
  protected $id;

  protected $welcome_message = 'Welcome!';

  public function setWelcomeMessage(string $message) {
    $this->welcome_message = $message;
    return $this;
  }

  public function getWelcomeMessage() : string {
    return $this->welcome_message;
  }

  public function __construct(\PDO $dbh, string $token) {
    $this->dbh = $dbh;
    $this->token = $token;
    $this->id = trim(explode(':', $token)[0]);

    $dbh->exec('CREATE TABLE IF NOT EXISTS `chats` (
      `id`	INTEGER,
      `date_added`	INTEGER,
      PRIMARY KEY(`id`)
    );');
  }

  public function queryApi(string $endpoint, array $parameters = []) : string {
    $url = 'https://api.telegram.org/bot'.urlencode($this->token).'/'.trim($endpoint, ' /');
    $param = http_build_query($parameters);
    return file_get_contents($url.(($param)?'?'.$param:''));
  }

  public function sendBroadcastMessage(string $message) : int {
    $count = 0;
    $result = $this->dbh->query('SELECT id FROM chats');
    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
      if($this->sendMessage($message, $row['id'])) {
        $count++;
      }
    }
    return $count;
  }

  public function sendMessage(string $text, string $chat_id, string $parse_mode = 'HTML') : bool {
    $result = json_decode(
      $this->queryApi(
        'sendMessage',
        compact('chat_id', 'text', 'parse_mode')
      ),
      true);
    return $result['ok']??false;
  }

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

  public function processUpdate(array $update) : bool {
    $id = $update['message']['chat']['id'];
    if(isset($update['message']['left_chat_member']) || isset($update['message']['left_chat_participant'])) {
      if($update['message']['left_chat_member']['id'] == $this->id || $update['message']['left_chat_member']['id'] == $this->id) {
        return $this->dbh->exec('DELETE FROM `chats` WHERE id = '.$this->dbh->quote($id));
      }
    }
    $result = $this->dbh->exec('INSERT INTO `chats` (id, date_added) VALUES ('.$this->dbh->quote($id).', '.$this->dbh->quote(time()).')');
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

  public function setWebhook(string $url) : bool {
    if(preg_match('%^((https://)|(www\.))([a-z0-9-].?)+(:[0-9]+)?(/.*)?$%i', $url)) {
      $result = json_decode($this->queryApi('setWebhook', compact('url')), true);
      return $result['ok']??false;
    }
    return false;
  }
}
