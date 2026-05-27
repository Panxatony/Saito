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

The walkthrough below sets up a fresh Debian 13 ("Trixie") box with Saito served via nginx + PHP-FPM, backed by MariaDB. Adjust paths, domain, and credentials as needed.

### 1. System packages

```shell
sudo apt update
sudo apt install -y \
    nginx \
    php8.2-fpm php8.2-cli \
    php8.2-gd php8.2-intl php8.2-mbstring php8.2-mysql php8.2-xml php8.2-curl php8.2-zip \
    mariadb-server \
    certbot python3-certbot-nginx \
    unzip curl
```

### 2. Database

```shell
sudo mysql -e "CREATE DATABASE saito CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'saito'@'localhost' IDENTIFIED BY 'CHANGE_ME';"
sudo mysql -e "GRANT ALL PRIVILEGES ON saito.* TO 'saito'@'localhost'; FLUSH PRIVILEGES;"
```

### 3. Deploy the release

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

### 4. nginx vhost

A reference configuration ships with the release at `config/nginx/saito.conf.example`. Copy it into place and adjust `server_name`, `root`, certificate paths, and the `fastcgi_pass` socket:

```shell
sudo cp /var/www/saito/config/nginx/saito.conf.example /etc/nginx/sites-available/saito.conf
sudo ln -s /etc/nginx/sites-available/saito.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 5. TLS certificate

```shell
sudo certbot --nginx -d forum.example.com
```

Certbot wires the certificate paths into the vhost and reloads nginx. A systemd timer takes care of renewals.

### 6. Run the installer

Open `https://forum.example.com/` in a browser. Saito's web installer will create the database schema, the first admin account, and the basic forum settings. After it finishes, the installer disables itself; if you ever need to re-run it, delete `config/installer/installed.txt`.

### 7. Upgrades

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
