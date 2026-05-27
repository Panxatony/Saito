# Saito

## What is it?

Saito is a web-forum with [conversation threading][ConversationThreading]. It is different from the majority of other forums as it puts the emphasis on performance and presenting conversations in a classic tree-style threaded view.

A lot of optimization went into serving long existing, small- to mid-sized communities with moderate traffic but hundreds of thousands of existing postings. It is able to displays hundreds of individual postings on a single page while running on a inexpensive, shared hosting account.

[Test it here][SaitoSupport] (login: test/test).

## Status

[![Build Status](https://secure.travis-ci.org/Schlaefer/Saito.png?branch=master)](http://travis-ci.org/Schlaefer/Saito)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Schlaefer/Saito/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/Schlaefer/Saito/?branch=develop)

[cake]: http://cakephp.org/
[marionette]: https://marionettejs.com/
[SaitoHomepage]: https://saito.siezi.com/
[SaitoSupport]: https://saito-forum.de/
[ConversationThreading]: https://en.wikipedia.org/wiki/Conversation_threading

## Requirements

- PHP 8.0+ (extensions: gd, exif, intl, mbstring, pdo, simplexml)
- Database (MySQL/MariaDB tested, [others untested](https://book.cakephp.org/3.0/en/orm/database-basics.html#supported-databases)).

## Get Started

A ready-to-use ZIP containing all necessary files is available on the [release page](https://github.com/Schlaefer/Saito/releases). Unzip it, upload it to your server, open it in a browser, and follow the instructions on the screen.

## Deployment on Debian 13

The walkthrough below takes a stock Debian 13 ("Trixie") server to a fully running Saito instance served via nginx + PHP-FPM, backed by MariaDB and protected by a Let's Encrypt certificate. Adjust paths, domain, and credentials as you go.

### 0. Prerequisites

- A Debian 13 host you can `ssh` into with `sudo` rights.
- A DNS `A`/`AAAA` record for `forum.example.com` already pointing at the server's public IP (Certbot's challenge needs this).
- Ports `80` and `443` reachable from the public internet.

### 1. System packages

```shell
sudo apt update
sudo apt full-upgrade -y
sudo apt install -y \
    nginx \
    php8.2-fpm php8.2-cli \
    php8.2-gd php8.2-intl php8.2-mbstring php8.2-mysql php8.2-xml php8.2-curl php8.2-zip \
    mariadb-server \
    certbot python3-certbot-nginx \
    unzip curl ca-certificates
```

### 2. Enable services and firewall

```shell
sudo systemctl enable --now mariadb php8.2-fpm nginx
# Optional but recommended: lock the box down to SSH + HTTP(S).
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
```

### 3. Set up MariaDB

Run the bundled hardening script — set a strong root password, drop anonymous accounts, disable remote root login, and remove the `test` database:

```shell
sudo mariadb-secure-installation
```

Then create the Saito database and a dedicated user (utf8mb4 is required for full Unicode/emoji support):

```shell
sudo mariadb <<'SQL'
CREATE DATABASE saito CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'saito'@'localhost' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON saito.* TO 'saito'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Verify the credentials work before moving on:

```shell
mysql -u saito -p'CHANGE_ME' -e 'SHOW DATABASES;' saito
```

### 4. Tune PHP-FPM

Saito stores uploads through PHP, so PHP's upload limits need to match the nginx `client_max_body_size` (16 MB in the reference vhost). Edit `/etc/php/8.2/fpm/php.ini`:

```ini
memory_limit = 256M
upload_max_filesize = 16M
post_max_size = 18M
date.timezone = Europe/Berlin
```

Reload PHP-FPM after the change:

```shell
sudo systemctl reload php8.2-fpm
```

### 5. Deploy the release

Download the tarball produced by the CI release stage (`saito-<tag>.tar.gz`) and unpack it under `/var/www/saito`:

```shell
sudo mkdir -p /var/www
sudo tar -xzf saito-<tag>.tar.gz -C /var/www/
sudo mv /var/www/saito-<tag> /var/www/saito
sudo chown -R www-data:www-data /var/www/saito
sudo find /var/www/saito/tmp /var/www/saito/logs -type d -exec chmod 770 {} \;
```

Edit `/var/www/saito/config/app.php` and replace the `__SALT__` placeholders plus the `Datasources.default` block with your real credentials. Alternatively set environment variables and let Saito read them via `env(…)`:

- `SECURITY_SALT`, `SECURITY_COOKIE_SALT` (each at least 32 random characters)
- `DATABASE_URL` (e.g. `mysql://saito:CHANGE_ME@localhost/saito?encoding=utf8mb4`)
- `DEBUG=false`

If you prefer keeping the variables in a file rather than the systemd/FPM unit, copy the bundled template and edit it:

```shell
sudo -u www-data cp /var/www/saito/config/.env.default /var/www/saito/config/.env
sudo chmod 640 /var/www/saito/config/.env
sudo -u www-data nano /var/www/saito/config/.env
```

The file must live at `config/.env` (next to `app.php`); it is `.gitignore`d and never shipped with a release. To make Saito load it on every request, uncomment the dotenv block at the top of `config/bootstrap.php` — it's disabled by default to keep production deployments environment-driven.

### 6. nginx vhost

A reference configuration ships with the release at `config/nginx/saito.conf.example`. Copy it into place and adjust `server_name`, `root`, certificate paths, and the `fastcgi_pass` socket:

```shell
sudo cp /var/www/saito/config/nginx/saito.conf.example /etc/nginx/sites-available/saito.conf
sudo ln -s /etc/nginx/sites-available/saito.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 7. TLS certificate

```shell
sudo certbot --nginx -d forum.example.com
```

Certbot wires the certificate paths into the vhost and reloads nginx. A systemd timer takes care of renewals.

### 8. Run the installer

Open `https://forum.example.com/` in a browser. Saito's web installer will create the database schema, the first admin account, and the basic forum settings. After it finishes, the installer disables itself; if you ever need to re-run it, delete `config/installer/installed.txt`.

### 9. Backups

At a minimum back up the database and the user-uploaded files. A simple nightly job via `cron.daily`:

```shell
sudo tee /etc/cron.daily/saito-backup > /dev/null <<'SH'
#!/bin/sh
set -e
DEST=/var/backups/saito
DATE=$(date +%F)
mkdir -p "$DEST"
mysqldump --single-transaction --quick saito | gzip > "$DEST/db-$DATE.sql.gz"
tar czf "$DEST/uploads-$DATE.tar.gz" -C /var/www/saito webroot/useruploads
find "$DEST" -type f -mtime +30 -delete
SH
sudo chmod 700 /etc/cron.daily/saito-backup
```

Add database credentials via `/root/.my.cnf` (mode `600`) so `mysqldump` doesn't need them on the command line.

### 10. Upgrades

For subsequent releases, drop the new tarball next to the running install, swap the symlink (or move the directory) and re-run `composer install --no-dev` only if you've updated `composer.lock` outside of the packaged release. Then visit the site once — Saito's updater detects schema changes and applies migrations.

## Development

### Set-Up Environment

You need a more or less generic environement providing:

-  PHP with `composer` for the server-backend (mainly build on [CakePHP][cake])
-  node with `yarn` and `grunt-cli` for the browser-frontend (mainly build on [Marionette][marionette])
-  a database

There's a docker file for *development* in `dev/docker/…`

### Install Files

Checkout the files from git-repository and install the dependencies:

```shell
composer install;
yarn install;
```

Move dependency-assets into the right places:

```shell
grunt dev-setup
```

Run all test cases:

```shell
composer test-all
```

See the `Gruntfile`, `packages.json` and `composer.json` for additional devleopment-commands.

### Create Production Files

To generate all the minimized assets for production:

```shell
grunt release
```

### Create A Release Zip

To generate a zip-package as found on the release page for distribution:

```shell
vendor/bin/phing
```

## FAQ

### How does it compare to [mylittleforum]

Actually this forum was written to replace a mylittleforum installation with a more modern approach. Mylittleforum is a noteworthy starting place if you want a threaded web-forum. There aren't that many out there. Mylittleforum exists for many years now and offers great features.

*Disclaimer: Subjective opinion ahead…*

But there are a shortcommings, mainly: performance and maintainability. If a mylittleforum installation reaches a few hundred thousand postings it is going to slow down. Also it was written when PHP was a much worse language: there are no test cases, which makes it more fragile to changes.

[mylittleforum]: https://mylittleforum.net/
