# HipChat Twitter Quotes
This application fetches tweets from an account on a regular basis and provides tweets based on partial searches in a HipChat room.

## Installation Instructions
1. Create a MySQL database and user and grant full permissions on the database to the user.
2. Copy `config.php.dist` to `config.php`. Edit `config.php`, filling in required configuration values.
3. Ensure `always_populate_raw_post_data = -1` in your `php.ini` when using PHP 5.6+.

## Contributing
Pull requests are welcome and will be reviewed promptly.

## License
This software is licensed under the GPL. See [LICENSE.md](LICENSE.md) for more information.
