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
long-standing base, still selectable) and **Local** (the macnemo identity, built
on Nova). A theme sets its variables and then imports its parent's `theme.scss`,
so a new theme is usually a short file of colours.

Every theme variable must be declared with `!default` — without it a child theme
cannot override the value, and the override fails silently.

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
