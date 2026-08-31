# Development Setup

How to run Saito locally for development.

## Requirements

- **PHP >= 8.4.3** with the usual CakePHP extensions (intl, mbstring, pdo_mysql,
  simplexml, ...). The patch level matters: parts of the locked tree
  (`symfony/clock`, PHPUnit 13, Psalm 6) refuse anything older.
- **MySQL** or **MariaDB**
- [**Composer**](https://getcomposer.org/) for the PHP dependencies
- **Node.js >= 22.11** with [**Yarn**](https://yarnpkg.com/) for the frontend
  assets. `.nvmrc` names the exact version the pipelines run (24 LTS). Below the
  floor `yarn install` stops with an engine error naming `cssnano` rather than
  Node, which is easy to misread as a broken lockfile.

## 1. Get the Code

```shell
git clone https://github.com/Panxatony/Saito.git
cd Saito
```

## 2. Backend

Install the PHP dependencies:

```shell
composer install
```

### Configuration

Saito reads its runtime configuration from environment variables (see
`config/app.php`). Copy the template and fill in the values:

```shell
cp config/.env.default config/.env
```

Set at least:

- `DATABASE_URL` — e.g. `mysql://user:pass@localhost/saito`
- `SECURITY_SALT`, `SECURITY_COOKIE_SALT`, `SECURITY_JWT_SALT` — random strings
- `DEBUG=true` for development

### Database

Create the database referenced by `DATABASE_URL`, then run the migrations:

```shell
bin/cake migrations migrate
```

## 3. Frontend Assets

The island bundles and the theme stylesheets are built from `package.json`
scripts (Vite + dart-sass under the hood). Install the Node dependencies —
`yarn.lock` pins the exact, reproducible versions — and build the development
assets:

```shell
yarn install
yarn setup            # pull vendor assets into place for development
```

For production (minified, purged) assets:

```shell
yarn build:release
```

Every theme is compiled by one script, `yarn css` — Bota, Nova and Macnemo
together, because Nova imports Bota's partials and Macnemo imports Nova's. Change
a partial and rebuild all of them, or the themes drift apart.

**`yarn build:release` does one more thing than a local build, and it matters.**
After minifying, it runs `dev/purge-css.js`, which drops every rule no template
or PHP class refers to — the compiled themes carry about 1600 classes and the
forum uses roughly 150, so this is 810 KB down to 271. Bootstrap stays a
dependency; only the shipped CSS is trimmed.

So what you compile locally is the full stylesheet and what ships is not. That
gap is a real hazard: a rule only the purge removes looks fine in `yarn css` and
is missing on the server, with nothing in any log. `dev/pixel-diff.sh` is what
closes it — it renders real pages against both and counts differing pixels.
**Run it after touching `purgecss.config.js`, and treat anything but zero as a
missing safelist entry rather than an acceptable number.**

## 4. Run the App

Use CakePHP's built-in server for development:

```shell
bin/cake server
```

Then open <http://localhost:8765>. In production Saito is served by a real web
server (nginx / Apache) in front of PHP-FPM.

## 5. Tests

Backend (PHPUnit):

```shell
composer phpunit
composer coverage        # writes an HTML coverage report to docs/local/
```

There are no frontend tests. Karma and Jasmine went with the SPA and nothing
replaced them, so the TypeScript under `frontend/src` is covered by ESLint
(`yarn lint`) and by nothing else. Worth knowing before you change it.

## 6. Static Analysis & Code Style

```shell
composer phpstan         # PHPStan (framework-aware, cakedc/cakephp-phpstan)
composer cs-check        # CodeSniffer against the CakePHP coding standard
composer cs-fix          # auto-fix what it can
```

CI (GitHub Actions) runs the test suite and PHPStan on every pull request and on
pushes to `main` / `develop`.
