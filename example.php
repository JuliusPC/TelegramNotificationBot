<?php
require __DIR__.'/TelegramBot.php';
use JuliusPC\TelegramBot;

require __DIR__.'/config.php';

// setup
$bot = new TelegramBot($dbh, $token);
$bot->setWelcomeMessage('Hi!');

// process Updates in case not using webhooks
$bot->processUpdates($bot->getUpdates());

// send broadcast to all users
$bot->sendBroadcastMessage('Hello <b>'.date('H:i').'</b>');

// set webhook
//$bot->setWebhook('https://example.org/someobscurefolder/webhook.php');

// remove webhook
//$bot->deleteWebhook();

// get infos about configured webhooks
//echo $bot->getWebhookInfo();

// process update from webhook
/*
$update = json_decode( file_get_contents("php://input"), true );
echo $bot->processUpdate($update);
*/
