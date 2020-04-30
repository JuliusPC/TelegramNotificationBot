<?php
require __DIR__.'/TelegramBot.php';

$dbh = new \PDO('sqlite:'.__DIR__.'/botdb.sqlite3');

$bot = new TelegramBot($dbh, $token);
$bot->setWelcomeMessage('Moin!');
echo $bot->processUpdate($bot->getUpdates()) . ' Updates ';
//echo $bot->sendBroadcastMessage('Hallo um <b>'.date('H:i').'</b> Uhr!') . ' abonnierte Chats ';

//$bot->setWebhook('https://example.org/someobscurefolder/webhook.php');
