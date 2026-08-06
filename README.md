# Saito

[![Static Analysis](https://github.com/Panxatony/Saito/actions/workflows/static-analysis.yml/badge.svg?branch=develop)](https://github.com/Panxatony/Saito/actions/workflows/static-analysis.yml)
[![Release](https://github.com/Panxatony/Saito/actions/workflows/release.yml/badge.svg)](https://github.com/Panxatony/Saito/actions/workflows/release.yml)
[![DeepSource](https://app.deepsource.com/gh/Panxatony/Saito.svg/?label=active+issues&show_trend=true)](https://app.deepsource.com/gh/Panxatony/Saito/)
[![DeepSource](https://app.deepsource.com/gh/Panxatony/Saito.svg/?label=resolved+issues&show_trend=true)](https://app.deepsource.com/gh/Panxatony/Saito/)

## What is it?

Saito is a web-forum with [conversation threading][ConversationThreading]. It is different from the majority of other forums as it puts the emphasis on performance and presenting conversations in a classic tree-style threaded view.

A lot of optimization went into serving long existing, small- to mid-sized communities with moderate traffic but hundreds of thousands of existing postings. It is able to displays hundreds of individual postings on a single page while running on a inexpensive, shared hosting account.

[cake]: http://cakephp.org/
[htmx]: https://htmx.org/
[alpine]: https://alpinejs.dev/
[ConversationThreading]: https://en.wikipedia.org/wiki/Conversation_threading

## Requirements

- PHP 8.4+ (extensions: gd, exif, intl, mbstring, pdo, simplexml)
- Database (MySQL/MariaDB tested, [others untested](https://book.cakephp.org/5/en/orm/database-basics.html#supported-databases)).
  **Transactional tables are required.** Operations that touch several rows at
  once — merging two threads, for one — rely on a transaction to keep them
  together, and MyISAM accepts the request without honouring it, silently. Since
  8.3.0 a migration converts the core tables to InnoDB; an installation older
  than the 2018 schema should read the 8.3.0 entry in the
  [changelog](CHANGELOG.md) before upgrading, because converting a large
  `entries` table takes minutes and holds a lock.

## Get Started

A ready-to-use tarball containing all necessary files is available on the [release page](https://github.com/Panxatony/Saito/releases), next to its SHA-256 checksum. Unpack it, upload it to your server, open it in a browser, and follow the instructions on the screen.

## Deployment

For a production install — nginx + PHP-FPM, MariaDB, TLS, backups and the
upgrade path — follow **[Deploying Saito on Debian 13](docs/deployment-debian.md)**.

Two things worth knowing up front:

- Themes are plugins. After adding one, expose its assets with
  `bin/cake plugin assets symlink`, otherwise its CSS is served as 404.
- `config/saito_config.php` is install-specific. Never copy a fresh one over a
  running install — merge the changes instead.

**[docs/configuration.md](docs/configuration.md) is the reference for both
layers**: the twenty-odd keys in `config/saito_config.php` and the thirty-three
environment variables, each with its default and what it actually does. It also
says which of the two wins where they name the same setting, which is not the
obvious way round.

The third layer, the admin area, is not in it: those settings are edited in the
browser and stored in the database.

## Themes

Saito ships three: **Nova** (the default — a modern take on Bota), **Bota** (the
long-standing base, still selectable) and **Macnemo** (the macnemo identity,
built on Nova). A theme sets its variables and then imports its parent's
`theme.scss`, so a new theme is usually a short file of colours.

Every theme variable must be declared with `!default` — without it a child theme
cannot override the value, and the override fails silently.

## Development

Larger work that is known and scheduled but not yet done is collected in
[todo.md](todo.md), filed under the release it is meant for. Worth a look before starting anything structural: the
reasoning, the measurements and the reasons something was *not* done are there
rather than in the commit history.

### Set-Up Environment

You need a more or less generic environement providing:

-  PHP with `composer` for the server-backend (mainly build on [CakePHP][cake])
-  **Node.js >= 22.11** with `yarn` for the browser assets ([htmx][htmx] + [Alpine][alpine] islands, CSS and fonts). `.nvmrc` names the version the pipelines use; the build tooling sets that floor and `yarn install` refuses below it.
-  a database

### Install Files

Checkout the files from git-repository and install the dependencies:

```shell
composer install;
yarn install;
```

Move dependency-assets into the right places:

```shell
yarn setup
```

Run all test cases:

```shell
composer test-all
```

`composer test-all` runs PHPUnit, PHPStan, the TypeScript type-checker and
ESLint — `yarn lint` is `tsc --noEmit && eslint`, and both pipelines run it as
their own job. Until 8.3.0 nothing ever type-checked: `tsconfig.json` has asked
for strict checking for years while the build went through esbuild, which strips
types without looking at them. Code style is checked
separately with `composer cs-check`, and is not part of `test-all`: the code
base is some 270 violations away from its own PHPCS standard, so wiring it in
would mean a command that can only ever fail. `composer cs-fix` applies what
PHPCBF can fix — it rewrites source files, so run it deliberately and never as
part of a test.

See `package.json` and `composer.json` for further development commands.

### Create Production Files

To generate all the minimized assets for production:

```shell
yarn build:release
```

That builds the theme stylesheets and three JavaScript bundles:
`htmx-threads.bundle.js` (the forum), `admin.bundle.js` (the backend) and
`boot.bundle.js` — a few hundred bytes loaded synchronously in `<head>` that pick
the theme stylesheet and the font scale before the first paint. It is a separate
bundle because it has to run first, and because it exists at all so that no page
carries an inline `<script>`; see the content-security policy note in
[docs/deployment-debian.md](docs/deployment-debian.md).

### Create A Release

Write the CHANGELOG section first, then push a version tag. CI runs the test
suite, builds the tarball from a clean checkout and publishes it on the release
page with its checksum, using that section as the release notes:

```shell
sh dev/check-changelog.sh 8.2.5        # does CHANGELOG.md describe it yet?
git tag 8.2.5 && git push github 8.2.5 # substitute the version you are releasing
```

The same check runs as the first job of both pipelines and fails the release
before anything is built. It exists because the description is the one part of a
release nothing else produces: version, tag and tarball all appear by
themselves, so a missing section used to go out unnoticed — the release job
simply published the bare version number as its own notes.

Do not create the GitHub release by hand. The release job calls `gh release
create` itself, with the tarball and its checksum attached; an already-existing
release makes it fail with *a release with the same tag name already exists*,
and the assets never get built. Push the tag and let it run.

One thing outside this repository has to follow a release: the project page at
<https://saito.macnemo.de> names the current version in both languages
(`index.html` and `en/index.html`, the paragraph marked `class="release"`). It
is deliberately a plain sentence rather than something generated — the page is
four static files and makes no request of its own — so it only stays true if it
is edited along with the release.

## Credits

Saito was created and, from 2012 to 2020, carried by **Schlaefer** — 4154 of the
commits in this repository are his. The threading model, the performance work
that lets a shared-hosting account serve hundreds of postings on one page, and
the test suite that still makes changes safe today all came from him. This fork
stands on that work — thank you.

**Gert Dietrich** and **kt007** contributed in those early years as well, and
their work is still in here.

## FAQ

### How does it compare to [Schraib]

[Schraib] is another threaded forum, and a deliberately different answer to the
same problem. It is plain PHP with PDO — around 25k lines, no framework, no
Composer, no build step — aimed squarely at operators who do not want to
maintain a toolchain: unzip, point a browser at it, done. On classic shared
hosting with Apache that is a genuinely smaller thing to run than Saito.

Saito trades that simplicity for a foundation: CakePHP, a test suite, database
migrations, and an asset pipeline. That is more moving parts and a real install
procedure, and it is what makes larger changes — a PHP major version, a frontend
rewrite — something you can do without holding your breath.

Two practical differences worth knowing. Schraib is young (0.3.x at the time of
writing), so it has had less time to accumulate the edge cases a decade-old
forum runs into. And its hardening leans on Apache `.htaccess` — on nginx those
rules are silently ignored, so anything relying on them has to be rebuilt in the
server config.

If you want a forum you can drop on a hoster and forget, look at Schraib. If you
want one you can keep developing, stay here.

[Schraib]: https://schraib.de/download

### How does it compare to [mylittleforum]

Actually this forum was written to replace a mylittleforum installation with a more modern approach. Mylittleforum is a noteworthy starting place if you want a threaded web-forum. There aren't that many out there. Mylittleforum exists for many years now and offers great features.

*Disclaimer: Subjective opinion ahead…*

But there are a shortcommings, mainly: performance and maintainability. If a mylittleforum installation reaches a few hundred thousand postings it is going to slow down. Also it was written when PHP was a much worse language: there are no test cases, which makes it more fragile to changes.

[mylittleforum]: https://mylittleforum.net/
