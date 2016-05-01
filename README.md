# HipChat Twitter Quotes
This application fetches tweets from an account on a regular basis and provides tweets based on partial searches in a HipChat room.

## Installation Instructions
1. Create a MySQL database and user and grant full permissions on the database to the user.
2. Copy `config.php.dist` to `config.php`. Edit `config.php`, filling in required configuration values.
3. Ensure `always_populate_raw_post_data = -1` in your `php.ini` when using PHP 5.6+.
4. Point your document root to the `web` subfolder.
5. Redirect all non-found requests to the `web/index.php` front controller.
  * `.htaccess`/`VirtualHost` definition on Apache
  * `try_files` directive in nginx config.
6. Run `./composer-install.sh` in the project root to install [Composer](https://getcomposer.org) and all the project dependencies.
7. Create the database schema by running `php commands/db_migrations.php` in the project root.
  * This may need to be run after any upgrades of the project as well.
8. Schedule `php commands/update_twitter.php` as a cron job as often as you'd like Twitter statuses to be updated.
  * Only statuses newer than the latest are fetched, so this uses very little data.
9. Add the integration to your HipChat rooms using the URL format:
  * `https://your_base_url/capabilities.json`
10. Configure the Twitter account to monitor and the trigger command in the integration's Configure tab.

## Usage
If your trigger is `/tq`, you can use the following commands:
* **`/tq`** &mdash; Random quote
* **`/tq help`** &mdash; These usage instructions
* **`/tq latest`** &mdash; The latest tweet from the monitored account
* **`/tq search text`** &mdash; Quote matching the search text
  * First, exact phrase is searched.
  * Second, all words in the order provided.
  * Third, all words, without considering order.
  * Fourth, any of the words.
  * In all cases, the most recent Tweet match is used.

## Screenshots

### Configuration
![Configuring](https://raw.githubusercontent.com/cbenard/hipchat-twitter-quotes/master/web/assets/images/twitterquotes-screenshot-configure.png)

### Usage
![Usage 1](https://raw.githubusercontent.com/cbenard/hipchat-twitter-quotes/master/web/assets/images/twitterquotes-screenshot-usage-1.png)
![Usage 2](https://raw.githubusercontent.com/cbenard/hipchat-twitter-quotes/master/web/assets/images/twitterquotes-screenshot-usage-2.png)

## Notes
1. This was developed using PHP 5.6. It will probably work with PHP 5.5. I don't know about anything lower than that.

## Contributing
Pull requests are welcome and will be reviewed promptly.

## License
This software is licensed under the GPL. See [LICENSE.md](LICENSE.md) for more information.
