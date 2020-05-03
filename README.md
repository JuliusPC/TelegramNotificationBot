# TelegramNotificationBot

This library is aimed at notifying telegram users via [bot API](https://core.telegram.org/bots/api). I use it for broadcasting news extracted from a RSS feed to different chats (channels). It uses a SQLite-Database (others may work, just change the [SQLite DSN](https://www.php.net/manual/en/ref.pdo-sqlite.connection.php) in the PDO constructor) to keep track of subscribed chats. If the bot is removed from chats, received a `/stop` command or blocked by users it will remove those `chat_ids` from the database.

This is only a thin layer on top the bot api that abstracts away the nasty things like keeping track of the known `chat_id`s. Basic understanding how the bot API works is still required.

Usage hints:

1. install library:
  - install via Composer: `composer require JuliusPC/TelegramNotificationBot`
  - clone or download this repo, use `composer install` to install dependencies
2. [obtain bot access token](https://core.telegram.org/bots/api)
3. write your code using this library

Importing the library:

~~~php
require __DIR__ . '/TelegramBot.php';
use JuliusPC\TelegramBot;
~~~

... or using Composer:

~~~php
require __DIR__ . '/vendor/autoload.php';
use JuliusPC\TelegramBot;
~~~

Configuring the bot:

~~~php
$dbh = new \PDO('sqlite:/path/to/botdb.sqlite3');
$token = 'YOUR_ACCESS_TOKEN_HERE';

$bot = new TelegramBot($dbh, $token);

// adjust the bots defaults to your need.
$bot->setWelcomeMessage('Moin!');
$bot->setStopMessage('Ciao.');
~~~

## Getting updates via webhooks (recommended):

Setup of webhooks (you only need to this once or after changes):

~~~php
// set webhook
$bot->setWebhook('https://example.org/someobscurefolder/webhook.php');

// some other useful stuff
// remove webhook
//$bot->deleteWebhook();

// get infos about configured webhooks
//echo $bot->getWebhookInfo();
~~~

Put this in the file `/someobscurefolder/webhook.php` (don’t name it like this 😉):

~~~php
// process update from webhook
$update = json_decode( file_get_contents("php://input"), true );
echo $bot->processUpdate($update);
~~~

## Getting updates via polling

Run this periodically:

~~~php
// process Updates in case not using webhooks
$bot->processUpdates($bot->getUpdates());
~~~

## Sending Messages

~~~php
// send broadcast to all users
$bot->sendBroadcastMessage('Hello from <b>'.date('H:i').'</b>');
~~~

## Dealing with commands

Let Telegram clients know what commands your bot understands (again as webhooks: you need to do this once or after changes):

~~~php
$commands = [
    [
        'command'=>'start',
        'description'=>'starts bot'
    ],
    [
        'command'=>'stop',
        'description'=>'stops bot'
    ]
];
$bot->setMyCommands(json_encode());
~~~

If you want to implement your own command, the easiest way to do this is extending the use `TelegramBot` class. In this little example we add a commad that echos the message:

~~~php
class AdvancedBot extends TelegramBot {
    protected function executeCommand(string $command, array $update) : bool {
        if($command == 'echo') {
            $message = \json_encode($update, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $id = $update['message']['chat']['id']??'';
            return $this->sendMessage('<pre>'.htmlspecialchars($message).'</pre>', $id);
        } else {
            // we don’t know this command, so let the parent class deal with it:
            parent::executeCommand($command, $update);
        }
    }
}

// of course you need to instantiate and use your new class and not the old one...
$bot = new AdvancedBot($db_handle, $token)
~~~