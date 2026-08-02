# Development Setup

How to run Saito locally for development.

## Requirements

- **PHP >= 8.4** with the usual CakePHP extensions (intl, mbstring, pdo_mysql,
  simplexml, ...)
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

The island bundles and the theme stylesheets are built with Grunt (Vite +
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

Every theme is compiled by one task, `grunt dart-sass:theme` — Bota, Nova and
Macnemo together, because Nova imports Bota's partials and Macnemo imports
Nova's. Change a partial and rebuild all of them, or the themes drift apart.
`grunt release` runs that task and then minifies the results.

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
