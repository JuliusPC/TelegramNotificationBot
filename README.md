# TelegramNotificationBot

This program is aimed at notifying telegram users via [bot API](https://core.telegram.org/bots/api). I use it for broadcasting news extracted from a RSS feed to different chats (channels). Uses a SQLite-Database (others may work, just change the [SQLite DSN](https://www.php.net/manual/en/ref.pdo-sqlite.connection.php) in the PDO constructor) to keep track of subscribed chats. If the bot is removed from chats or blocked by users it will remove those chat_ids from the database.

This is more a boilerplate than a ready to use library that fits everybodys needs. Basic understanding how the bot API works is still required.

Usage hints:

1. clone or download this repo
2. use `composer install` to install dependencies
3. in case you are using webhooks rename `example.php` to something long and non guessable
4. make sure to place the SQLite database outside of your webserver’s document root
5. enter your bot’s token in `config.example.php` and rename it to `config.php`

