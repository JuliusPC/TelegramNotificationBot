<?php
$update = json_decode( file_get_contents("php://input"), true );

require __DIR__.'/TelegramBot.php';
use JuliusPC\TelegramBot;

$dbh = new \PDO('sqlite:/path/to/botdb.sqlite3');
$token = 'YOUR_API_TOKEN_HERE';

$bot = new TelegramBot($dbh, $token);
$bot->setWelcomeMessage('Moin!');
echo $bot->processUpdates($bot->getUpdates())
