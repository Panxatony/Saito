# Saito

[![Static Analysis](https://github.com/Panxatony/Saito/actions/workflows/static-analysis.yml/badge.svg?branch=develop)](https://github.com/Panxatony/Saito/actions/workflows/static-analysis.yml)
[![Release](https://github.com/Panxatony/Saito/actions/workflows/release.yml/badge.svg)](https://github.com/Panxatony/Saito/actions/workflows/release.yml)
[![DeepSource](https://app.deepsource.com/gh/Panxatony/Saito.svg/?label=active+issues&show_trend=true)](https://app.deepsource.com/gh/Panxatony/Saito/)
[![DeepSource](https://app.deepsource.com/gh/Panxatony/Saito.svg/?label=resolved+issues&show_trend=true)](https://app.deepsource.com/gh/Panxatony/Saito/)

## What is it?

Saito is a web-forum with [conversation threading][ConversationThreading]. It is different from the majority of other forums as it puts the emphasis on performance and presenting conversations in a classic tree-style threaded view.

A lot of optimization went into serving long existing, small- to mid-sized communities with moderate traffic but hundreds of thousands of existing postings. It is able to displays hundreds of individual postings on a single page while running on a inexpensive, shared hosting account.

[Test it here][SaitoSupport] (login: test/test).

[cake]: http://cakephp.org/
[marionette]: https://marionettejs.com/
[SaitoHomepage]: https://saito.siezi.com/
[SaitoSupport]: https://saito-forum.de/
[ConversationThreading]: https://en.wikipedia.org/wiki/Conversation_threading

## Requirements

- PHP 8.4+ (extensions: gd, exif, intl, mbstring, pdo, simplexml)
- Database (MySQL/MariaDB tested, [others untested](https://book.cakephp.org/5/en/orm/database-basics.html#supported-databases)).

## Get Started

A ready-to-use ZIP containing all necessary files is available on the [release page](https://github.com/Panxatony/Saito/releases). Unzip it, upload it to your server, open it in a browser, and follow the instructions on the screen.

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
    php8.4-fpm php8.4-cli \
    php8.4-gd php8.4-intl php8.4-mbstring php8.4-mysql php8.4-xml php8.4-curl php8.4-zip \
    mariadb-server \
    certbot python3-certbot-nginx \
    unzip curl ca-certificates
```

### 2. Enable services and firewall

```shell
sudo systemctl enable --now mariadb php8.4-fpm nginx
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

### 4. Set up a dedicated PHP-FPM pool

When other vhosts share the same host, putting Saito on its own PHP-FPM pool keeps its environment variables (DB URL, security salts) isolated from everything else and lets you tune resource limits independently of the global `php.ini`.

A reference pool config ships with the release at `config/php-fpm/saito.pool.conf.example`. Copy it to the FPM pool directory and edit the `env[…]` block — at minimum replace the `__SALT__` placeholders and set a real `DATABASE_URL` (or comment those out if you'd rather configure Saito through `config/.env` or `config/app.php`):

```shell
sudo cp /var/www/saito/config/php-fpm/saito.pool.conf.example \
        /etc/php/8.4/fpm/pool.d/saito.conf
sudo chmod 640 /etc/php/8.4/fpm/pool.d/saito.conf
sudo nano /etc/php/8.4/fpm/pool.d/saito.conf
```

The reference pool already sets sane PHP runtime limits (`memory_limit = 256M`, `upload_max_filesize = 16M`, `post_max_size = 18M`, matching the nginx `client_max_body_size`). `clear_env = yes` ensures the env vars declared in the pool are the only ones Saito sees — they won't leak to the default `www` pool used by other sites.

Validate the FPM config and reload:

```shell
sudo php-fpm8.4 -t
sudo systemctl reload php8.4-fpm
```

`systemctl status php8.4-fpm` should now list both the default `www` pool and the new `saito` pool.

### 5. Deploy the release

The CI release stage produces `saito-<tag>.tar.gz`. When you download it
from the GitLab job artifacts page you'll receive a wrapper `.zip`
(e.g. `saito-v7.0.0.zip`) that contains
`build/saito-<tag>.tar.gz` and a `.sha256` next to it — unpack the zip
first, verify the checksum, then deploy the inner tarball under
`/var/www/saito`:

```shell
unzip -d /tmp/saito-release saito-<tag>.zip
cd /tmp/saito-release/build
sha256sum -c saito-<tag>.tar.gz.sha256
sudo mkdir -p /var/www
sudo tar -xzf saito-<tag>.tar.gz -C /var/www/
sudo mv /var/www/saito-<tag> /var/www/saito
sudo chown -R www-data:www-data /var/www/saito
sudo find /var/www/saito/tmp /var/www/saito/logs -type d -exec chmod 770 {} \;
```

Plugins ship their own `webroot/` (CSS, JS, fonts, icons). Saito's
nginx vhost serves static assets directly from `/var/www/saito/webroot`,
so each plugin's `webroot/` needs to be exposed there. Cake's bundled
console command creates the symlinks for you:

```shell
sudo -u www-data /var/www/saito/bin/cake plugin assets symlink
```

This step is **required on every release** — the symlinks are not part
of the tarball, so a fresh extract will be missing them and every
plugin-served stylesheet (e.g. `/bota/css/theme.css`) returns 404 until
the command runs.

Edit `/var/www/saito/config/app.php` and replace the `__SALT__` placeholders plus the `Datasources.default` block with your real credentials. Alternatively set environment variables and let Saito read them via `env(…)`:

- `SECURITY_SALT`, `SECURITY_COOKIE_SALT`, `SECURITY_JWT_SALT` (each at least 32 random characters; `SECURITY_JWT_SALT` signs the API tokens issued by the JWT authentication path)
- `DATABASE_URL` (e.g. `mysql://saito:CHANGE_ME@localhost/saito?encoding=utf8mb4`)
- `APP_DEFAULT_TIMEZONE` (e.g. `Europe/Berlin`) — falls back to UTC
- `APP_DEFAULT_LOCALE` (e.g. `de_DE`) — drives `intl` date/number formatting; default `en_US`
- `SAITO_LANGUAGE` (`de` or `en`) — picks the UI translation bundle; default `en`
- `DEBUG=false`

If you prefer keeping the variables in a file rather than the systemd/FPM unit, copy the bundled template and edit it:

```shell
sudo -u www-data cp /var/www/saito/config/.env.default /var/www/saito/config/.env
sudo chmod 640 /var/www/saito/config/.env
sudo -u www-data nano /var/www/saito/config/.env
```

The file must live at `config/.env` (next to `app.php`); it is `.gitignore`d and never shipped with a release. To make Saito load it on every request, uncomment the dotenv block at the top of `config/bootstrap.php` — it's disabled by default to keep production deployments environment-driven.

### 6. nginx vhost

A reference configuration ships with the release at `config/nginx/saito.conf.example`. It already targets the dedicated `php8.4-fpm-saito.sock` from step 4 — change the `fastcgi_pass` to `/run/php/php8.4-fpm.sock` if you skipped the pool step. Adjust `server_name`, `root`, and the certificate paths to match your environment:

```shell
sudo cp /var/www/saito/config/nginx/saito.conf.example /etc/nginx/sites-available/saito.conf
sudo ln -s /etc/nginx/sites-available/saito.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

The reference config sets the recommended security response headers —
`X-Frame-Options`, `X-Content-Type-Options: nosniff`, `Referrer-Policy` and HSTS
— and repeats `nosniff` inside the static-asset and `/useruploads/` location
blocks (a location with its own `add_header` does not inherit the server-level
ones, and that static regex also serves uploaded images). Saito additionally
sends `nosniff` / `Referrer-Policy` from the application layer, so dynamic pages
stay covered even behind a different web server.

A commented-out `Content-Security-Policy` starting point is included as well. It
is off by default because CSP is install-specific and a wrong policy breaks the
page: Saito's SPA relies on inline scripts (so a strict, nonce-based CSP is not
shipped yet), and any analytics or external host you embed must be added to
`script-src`/`connect-src`. Enable it only after widening it for your setup and
checking the browser console for violations.

> **HSTS is a one-way commitment.** The example ships
> `Strict-Transport-Security: max-age=31536000; includeSubDomains`. Only keep it
> once HTTPS works reliably for the domain **and all its subdomains** — while it
> is active browsers refuse plain HTTP. Lower the `max-age` (or drop the header)
> first if you are still testing. If TLS terminates at an upstream proxy, set
> HSTS there (or on the edge that faces the client) rather than only here.

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

### 10. Refresh the Public Suffix List (optional)

`data/public_suffix_list.dat` ships a snapshot of [publicsuffix.org](https://publicsuffix.org/) used by the URL-parsing helpers (e.g. extracting `youtube.com` from a posted link). The list changes slowly — established TLDs are stable, but new gTLDs land occasionally. If you rely on tight domain matching, refresh the file every few months:

```shell
sudo -u www-data curl -sS -o /var/www/saito/data/public_suffix_list.dat \
    https://publicsuffix.org/list/public_suffix_list.dat
```

No restart needed — the file is read on demand.

### 11. Upgrades

For subsequent releases, drop the new tarball next to the running install, swap the symlink (or move the directory) and re-run `composer install --no-dev` only if you've updated `composer.lock` outside of the packaged release. Re-run `bin/cake plugin assets symlink` so the plugin asset symlinks are re-created in the fresh `webroot/`.

Then apply database migrations. Two paths exist depending on your deploy style:

- **Web path (default).** Visit the site once after the swap — Saito's in-app updater detects schema changes and runs the migrations on the first request. Suitable for hands-off deploys where the FPM environment already has all `env[…]` configured (so the web request sees the same DB the migrations need).
- **CLI path (deploy automation).** Run `bin/cake migrations migrate` as the `www-data` user before re-enabling traffic. Because the cake CLI does not inherit env from the FPM pool, you have to feed `DATABASE_URL` (and the security salts) on the command line — extract them from `/etc/php/8.4/fpm/pool.d/saito.conf` once and pass them through `env`. Use this path when you want a deterministic deploy without a "first request" race, or when you need to surface migration errors in your deploy logs rather than the site's error page.

After migrations, visit the site once with a logged-out browser. The boot path exercises the middleware stack and surfaces any per-environment misconfiguration immediately.

#### Upgrading from 6.0.x to 7.0.x

7.0 is a framework upgrade (CakePHP 4.6 → 5). As with 6.0, **no database migration ships** with this version — the last schema change is still `Saito5x7x0` (early 2020), so your `phinxlog` table stays untouched.

**Jumping straight from 5.7.x?** You can skip 6.0 entirely. Because no migration has been added since `Saito5x7x0`, a 5.7.x database is already schema-identical to a 7.0.0 one: the updater accepts any `db_version >= 4.10.0`, runs `migrations migrate` as a no-op, and writes `7.0.0` into the settings row. The catch is that you take both framework jumps (Cake 3.10 → 4 → 5) and the PHP jump (7.x → 8.4) at once — so go straight to PHP 8.4, and a **custom** theme/plugin must carry both the Cake 3→4 changes (see the 5.7→6.0 "Custom themes" notes below) and any 4→5 changes before the swap. The bundled `Bota` theme is already ported.

Before you swing the symlink:

- **Take a database backup.** The upgrade is non-destructive, but the first request after deploy rewrites cache/session structures.
- **PHP 8.4 is now required.** Cake 5 itself runs on 8.1+, but Saito's locked dependency tree pulls in components (e.g. `symfony/string`, `symfony/filesystem`) that require **PHP ≥ 8.4**. The release tarball's `vendor/composer/platform_check.php` enforces this and a too-old runtime aborts every request with a fatal. The most common blocker is an existing 8.2/8.3 FPM pool — point the Saito vhost's `fastcgi_pass` at a `php8.4-fpm-saito.sock` (see steps 4 and 6 above).

**How `db_version` becomes `7.0.0`.** The schema version lives in the `settings` table (`name = 'db_version'`), separate from the code version (`Saito.v` in `src/Lib/version.php`). `App\Middleware\SaitoBootstrapMiddleware` compares the two on every request; when they differ it routes the request to the in-app updater. The updater runs any pending migrations (none for this jump) and then writes the code version into the `settings` row via `Installer\Lib\DbVersion::set()`. So on the first request after deploying 7.0.0 the version is reconciled automatically (web path). For a CLI/automation deploy without that "first request", set it explicitly — the cake CLI needs the FPM env on the command line:

```shell
sudo -u www-data env DATABASE_URL="mysql://saito:CHANGE_ME@localhost/saito?encoding=utf8mb4" \
    php8.4 /var/www/saito/bin/cake.php migrations migrate
# then reconcile the version marker (no-op migration jump → just the settings row):
mysql saito -e "UPDATE settings SET value='7.0.0' WHERE name='db_version';"
```

After deploy: run `bin/cake plugin assets symlink` and open the site once while logged out to exercise the middleware/auth stack.

#### Upgrading from 5.7.x to 6.0.x

6.0 is a framework upgrade (CakePHP 3.10 → 4). No database migration ships with this version — the last schema change was `Saito5x7x0` in early 2020. Your `phinxlog` table stays untouched.

That said, before you swing the symlink:

- **Take a database backup.** Standard hygiene; the upgrade itself is non-destructive, but the first request after deploy writes new cache and session structures.
- **Verify InnoDB + utf8mb4.** Older installations may still have MyISAM tables or a non-`utf8mb4_unicode_ci` collation. The 4.x→5.x migrations were supposed to fix this, but installations that skipped versions sometimes have stragglers. Check with `SHOW TABLE STATUS` — if you find MyISAM or `latin1`/`utf8` collations, run the relevant migrations from the 5.x line before the framework jump.
- **PHP 8.3+.** 6.0 drops PHP 7 and 8.0–8.2 support entirely (Cake 4.6's dependency tree caps at PHP 8.3 — going higher would require a Cake 5 upgrade). Adjust your PHP-FPM pool if needed; an existing 8.2 pool is the most common deploy blocker.

After deploy:

- Run `bin/cake plugin assets symlink` (as above).
- Open the site once while logged out — the boot path exercises the new middleware stack (BodyParser, CsrfProtection cookie name, AuthenticationMiddleware) and surfaces any per-environment misconfiguration immediately.

##### Custom themes

The bundled `Bota` theme has already been ported. If you maintain a custom theme outside the repo, the Cake-3 → 4 changes you need to make yourself are:

1. **Template paths.** Cake 4 expects `templates/` at the theme plugin root (not `src/Template/`), and the element directory is lowercase `element/` (not `Element/`). Case matters on Linux. Move/rename accordingly.
2. **View/Helper return types.** Methods that override CakePHP base classes (e.g. `View::initialize()`, custom Helpers) must match the parent signature exactly under PHP 8 — `public function initialize(): void` etc. Missing return types throw a fatal at request time, not at boot.
3. **`$helpers` property removed.** Cake 4.4 dropped the `public $helpers = [...]` convention on Views and Controllers. Move helper registrations to `$this->viewBuilder()->setHelpers([...])` (Controller) or `$this->loadHelper(...)` (View). The deprecation warning is loud; the underlying behaviour silently skips the helpers.
4. **Plugin asset symlinks.** Theme CSS/JS lives at `plugins/<Theme>/webroot/...` and must be exposed via `webroot/<theme_underscored>/`. `bin/cake plugin assets symlink` handles all bundled plugins in one go — including custom themes registered through `Saito.themes.available`.

## Development

### Set-Up Environment

You need a more or less generic environement providing:

-  PHP with `composer` for the server-backend (mainly build on [CakePHP][cake])
-  node with `yarn` and `grunt-cli` for the browser-frontend (mainly build on [Marionette][marionette])
-  a database

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
