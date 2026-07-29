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
[htmx]: https://htmx.org/
[alpine]: https://alpinejs.dev/
[SaitoHomepage]: https://saito.siezi.com/
[SaitoSupport]: https://saito-forum.de/
[ConversationThreading]: https://en.wikipedia.org/wiki/Conversation_threading

## Requirements

- PHP 8.4+ (extensions: gd, exif, intl, mbstring, pdo, simplexml)
- Database (MySQL/MariaDB tested, [others untested](https://book.cakephp.org/5/en/orm/database-basics.html#supported-databases)).

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

## Themes

Saito ships three: **Nova** (the default — a modern take on Bota), **Bota** (the
long-standing base, still selectable) and **Macnemo** (the macnemo identity,
built on Nova). A theme sets its variables and then imports its parent's
`theme.scss`, so a new theme is usually a short file of colours.

Every theme variable must be declared with `!default` — without it a child theme
cannot override the value, and the override fails silently.

## Development

### Set-Up Environment

You need a more or less generic environement providing:

-  PHP with `composer` for the server-backend (mainly build on [CakePHP][cake])
-  node with `yarn` and `grunt-cli` for the browser assets ([htmx][htmx] + [Alpine][alpine] islands, CSS and fonts)
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

`composer test-all` runs PHPUnit, PHPStan and ESLint. Code style is checked
separately with `composer cs-check`, and is not part of `test-all`: the code
base is some 270 violations away from its own PHPCS standard, so wiring it in
would mean a command that can only ever fail. `composer cs-fix` applies what
PHPCBF can fix — it rewrites source files, so run it deliberately and never as
part of a test.

See the `Gruntfile`, `package.json` and `composer.json` for further development
commands.

### Create Production Files

To generate all the minimized assets for production:

```shell
grunt release
```

### Create A Release

Push a version tag. CI runs the test suite, builds the tarball from a clean
checkout and publishes it on the release page with its checksum:

```shell
git tag 8.2.1 && git push github 8.2.1   # substitute the version you are releasing
```

## Credits

Saito was created and, for well over a decade, carried by **Schlaefer**. The
threading model, the performance work that lets a shared-hosting account serve
hundreds of postings on one page, and the test suite that still makes changes
safe today are all his. This fork stands on that work — thank you.

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
