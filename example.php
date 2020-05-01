<?php
require __DIR__.'/TelegramBot.php';
use JuliusPC\TelegramBot;

$dbh = new \PDO('sqlite:/path/to/botdb.sqlite3');
$token = 'YOUR_API_TOKEN_HERE';

$bot = new TelegramBot($dbh, $token);
$bot->setWelcomeMessage('Moin!');
echo $bot->processUpdates($bot->getUpdates()) . ' Updates ';
//echo $bot->sendBroadcastMessage('Hallo um <b>'.date('H:i').'</b> Uhr!') . ' abonnierte Chats ';

//$bot->setWebhook('https://example.org/someobscurefolder/webhook.php');
