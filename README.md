## Racego API
Racego API is the backend of [Racego](https://github.com/maheini/Racego), a race management tool.

## Setup instruction
1. Any Mysql or MariaDB database is supported. Use [racego_database.sql](https://github.com/maheini/Racego-api/blob/master/racego_database.sql) as import to set up the DB structure and content.
2. Open config.php and set up your DB connection settings
3. Upload api.php, config.php and RacegoController.php to your preferred Webserver (Apache, Nginx, Litespeed...) and let's go!

## Requirements
 - PHP 7.0 or higher with PDO drivers enabled for one of these database systems:
   - MySQL >= 5.6 / MariaDB >= 10.0
