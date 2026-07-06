# Development Setup

How to run Saito locally for development.

## Requirements

- **PHP >= 8.4** with the usual CakePHP extensions (intl, mbstring, pdo_mysql,
  simplexml, ...)
- **MySQL** or **MariaDB**
- [**Composer**](https://getcomposer.org/) for the PHP dependencies
- **Node.js** with [**Yarn**](https://yarnpkg.com/) for the frontend assets

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

The TypeScript SPA and the theme stylesheets are built with Grunt (webpack +
dart-sass under the hood). Install the Node dependencies — `yarn.lock` pins the
exact, reproducible versions — and build the development assets:

```shell
yarn install
grunt dev-setup      # pull vendor assets into place for development
```

For production (minified, purged) assets:

```shell
grunt release
```

Each active theme compiles its own stylesheets (and purges unused CSS) via its
`sass.sh`, e.g. `plugins/Local/sass.sh` (dart-sass + PurgeCSS); `grunt release`
runs this as part of the build.

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

The frontend specs run with Karma / Jasmine (see the `Gruntfile`).

## 6. Static Analysis & Code Style

```shell
composer phpstan         # PHPStan (framework-aware, cakedc/cakephp-phpstan)
composer cs-check        # CodeSniffer against the CakePHP coding standard
composer cs-fix          # auto-fix what it can
```

CI (GitHub Actions) runs the test suite and PHPStan on every pull request and on
pushes to `main` / `develop`.
