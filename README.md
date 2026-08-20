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

## Running with Docker (recommended)

The bundled stack runs everything Shup needs: PHP-FPM, nginx, MariaDB, Redis, a
queue worker, and the scheduler. TLS is expected to terminate at a reverse proxy
in front of the stack, so nginx here speaks plain HTTP on a single host port.

### First run

```bash
cp .env.docker.example .env.docker

# Generate an application key and paste it into .env.docker as APP_KEY
docker compose run --rm app php artisan key:generate --show

# Then set at minimum: APP_URL, DB_PASSWORD / MARIADB_PASSWORD,
# and MARIADB_ROOT_PASSWORD.

docker compose up -d --build
```

The app is then served on `http://localhost:8080`; point your reverse proxy at
that port and forward the `X-Forwarded-*` headers. The first account registered
becomes the administrator.

> **Note on `SHUP_HTTP_PORT`.** Docker Compose interpolates `${...}` from the
> `.env` file in this directory (the local development config), *not* from
> `.env.docker`. To change the published port, either export it
> (`SHUP_HTTP_PORT=9000 docker compose up -d`) or run compose with
> `--env-file .env.docker`. Everything else is read inside the containers from
> `.env.docker` and needs no such handling.

### Migrating an existing (SQLite) install into Docker

Both the database rows and the uploaded blobs need to move. Short codes and
on-disk file names are unchanged, so every existing share link keeps working.

```bash
# 1. Start the database and bring up the stack. The app container runs
#    `php artisan migrate` on boot, creating the schema in MariaDB.
docker compose up -d --build

# 2. Copy the old SQLite database into the container and import it.
docker compose cp database/database.sqlite app:/tmp/legacy.sqlite
docker compose exec app php artisan shup:migrate-sqlite /tmp/legacy.sqlite --truncate

# 3. Copy the uploaded files into the storage volume.
docker compose cp storage/app/private/. app:/var/www/html/storage/app/private/
docker compose exec app chown -R www-data:www-data storage/app

# 4. Reconcile the storage counters.
docker compose exec app php artisan shup:recalculate_storage
docker compose exec app php artisan shup:recalculate_physical_storage
```

`shup:migrate-sqlite` copies table by table in foreign-key-safe order through
the query builder rather than replaying a SQLite dump, because the two engines
disagree on types, quoting and booleans. It also carries API tokens across into
the current hashed-plus-encrypted storage, so existing CLI and ShareX
configurations continue to authenticate without being reissued.

Verify before cutting over the proxy:

```bash
curl -sI http://localhost:8080/up          # expect 200
docker compose exec app php artisan shup:role you@example.com   # confirm Admin
```

### Day-to-day

```bash
docker compose logs -f app            # application log
docker compose exec app php artisan   # any artisan command
docker compose down                   # stop (volumes are preserved)
```

> `docker compose exec` runs as **root**, while PHP-FPM serves requests as
> `www-data`. Any artisan command that *writes* into `storage` should therefore
> run as the web user, otherwise it can leave files the application cannot read
> back:
>
> ```bash
> docker compose exec -u www-data app php artisan <command>
> ```
>
> Read-only commands are fine either way, and the entrypoint re-applies
> ownership to `storage` on every start.

Back up the `shup-storage` volume (uploaded files) and the `shup-db` volume
(database) together; restoring one without the other leaves dangling records.

## Manual installation
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

## Updating
To update Shup, you can simply pull the latest changes from the repository and run the necessary commands that might be required.

Running them even if they are not required should not cause any issues.
```bash
git pull
composer install
npm install
php artisan migrate
npm run build
```