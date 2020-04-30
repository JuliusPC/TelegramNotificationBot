<?php

class TelegramBot {

  protected $dbh;
  protected $token;
  protected $telegramApiUrl;

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
    $this->telegramApiUrl = 'https://api.telegram.org/bot'.urlencode($this->token).'/';

    $dbh->exec('CREATE TABLE IF NOT EXISTS `chats` (
      `id`	INTEGER,
      `date_added`	INTEGER,
      PRIMARY KEY(`id`)
    );');
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

  public function sendMessage(string $message, string $chat_id) : bool {
    $result = json_decode(file_get_contents($this->telegramApiUrl.'sendMessage?chat_id='.urlencode($chat_id).'&text='.urlencode($message).'&parse_mode=HTML'), true);
    return $result['ok']??false;
  }

  public function processUpdate($update) : int {
    $count = 0;

    $update = json_decode($update);
    $ids = [];
    if($update->ok??false) {
      foreach ($update->result as $update) {
        $ids[] = $update->message->chat->id;
      }
      $ids = array_unique($ids);

      foreach ($ids as $id) {
        $result = $this->dbh->exec('INSERT INTO `chats` (id, date_added) VALUES ('.$this->dbh->quote($id).', '.$this->dbh->quote(time()).')');
        // if chat_id is new
        if($result == 1) {
          if(!empty($this->welcome_message)) {
            $this->sendMessage($this->welcome_message, $this->dbh->lastInsertId());
          }
          $count++;
        }
      }
    }
    return $count;
  }

  public function getUpdates() : string {
    return file_get_contents($this->telegramApiUrl.'getUpdates');
  }

  public function setWebhook(string $url) : bool {
    if(preg_match('%^((https://)|(www\.))([a-z0-9-].?)+(:[0-9]+)?(/.*)?$%i', $url)) {
      $result = json_decode(file_get_contents($this->telegramApiUrl.'setWebhook?url='.urlencode($url)), true);
      return $result['ok']??false;
    }
    return false;
  }
}
