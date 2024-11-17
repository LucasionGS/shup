## About Shup

Shup is a self-hosted upload platform that allows you to upload files easily to your server. It is built with Laravel and is open-source.

## Features
- Upload images, videos, and other files
- Compact URLs for sharing with your domain. (e.g. `https://example.com/f/abc123`)
- Shorten any URL with the built-in URL shortener
- Upload paste bins.
- Protect your uploads with a password, which also encrypts the data on the server.
- Automatically delete uploads after a specified amount of time.
- Optional ephemeral anonymous uploads.
- Easy integration with ShareX and other upload tools.
- Limit per-user storage space.
- ...and more to come!

## Installation
As of now, Shup is not yet ready for production use. However, you can still install it for testing purposes or if you are prepared to deal with potential bugs and issues. (Reports and contributions are welcome!)

### Requirements
- PHP 8.2
- Composer
- Laravel compatible database (MySQL, Postgres, SQLite, etc.)
- PHP-supporting Web server (Nginx, Apache, etc.)

### Steps
1. Clone the repository. It is recommended to use the stable branch for the latest stable version.
```bash
git clone https://github.com/LucasionGS/shup --branch stable
```

2. Install dependencies
```bash
composer install
```

3. Copy the `.env.example` file to `.env` and fill in the necessary details.
```bash
cp .env.example .env
```

Shup uses SQLite by default.
If you don't want to use SQLite, you should change these values in the `.env` file (example for MySQL):
```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shup
DB_USERNAME=shup_user
DB_PASSWORD=xx123mypassword321xx
```

If you intend to use Shup for a production environment, you should also set the `APP_ENV` variable to `production` and set `APP_DEBUG` to `false`.
```bash
APP_ENV=production
APP_DEBUG=false
```


4. Run the necessary Laravel setup commands
```bash
php artisan key:generate # Generate a new application key
php artisan migrate # Run the database migrations
```

5. Run the app
  
#### Using a web server (Production or Development)
- Set the document root to the `public` directory.
- Make sure the web server has the necessary permissions to read and write to the `storage` and `bootstrap/cache` directories.

For NGINX users, here is an example configuration:
```nginx
server {
  server_name yoursite.dev;

  root /var/www/html/yoursite.dev/public;

  add_header X-Frame-Options "SAMEORIGIN";
  add_header X-Content-Type-Options "nosniff";

  index index.php;
  charset utf-8;

  client_max_body_size 4G; # Adjust this to your needs. Remember to adjust the PHP settings as well.
  fastcgi_intercept_errors on;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }  
  error_page 404 /index.php;
  
  location ~ /\.(?!well-known).* {
    deny all;
  }

  location ~ \.php$ {
   fastcgi_pass unix:/run/php/php8.2-fpm.sock;
   fastcgi_split_path_info ^((?U).+\.php)(/?.+)$;
   fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
   fastcgi_param PATH_INFO $fastcgi_path_info;
   fastcgi_param PATH_TRANSLATED $document_root$fastcgi_path_info;
   fastcgi_read_timeout 600s;
   fastcgi_send_timeout 600s;
   fastcgi_index index.php;
   include /etc/nginx/fastcgi_params;
   fastcgi_hide_header X-Powered-By;
  }

  listen 80;
}
```

#### Using the built-in PHP server (Development)
  This is not recommended for production use. You should only use this for testing purposes.
```bash
php artisan serve
```