# Architecture

What Saito is built from, how the pieces are turned into a release, and what
happens between a browser and the server on a single click.

Written against 8.4.12. Where a number appears it was counted, not remembered;
where something is a judgement it says so.

## 1. The parts

Saito is a **CakePHP 5 application on PHP 8.4**, served by nginx and PHP-FPM,
against MySQL or MariaDB. The forum's own code lives in `src/`; nearly
everything optional lives in `plugins/`.

```
src/          the application: controllers, models, auth, view helpers
plugins/      19 plugins — features, themes, and the admin area
templates/    server-rendered views (PHP templates, not a template language)
frontend/     TypeScript sources for the browser
webroot/      what the web server serves, including the built assets
config/       routes, bootstrap, and the installation's own settings
vendor/       Composer dependencies
```

### Plugins

Three kinds sit in the same directory, which is worth knowing before reading it:

**Features** — `Admin`, `Api`, `BbcodeParser`, `Bookmarks`, `Commonmark`,
`Cron`, `Detectors`, `Feeds`, `ImageUploader`, `Installer`, `MailObfuscator`,
`SaitoHelp`, `SaitoSearch`, `Sitemap`, `Stopwatch`, `Webhooks`.

**Themes** — `Bota`, `Nova`, `Macnemo`. A fourth, `Macfix`, exists only on the
`theme/macfix` branch and is not part of a release; installations that use it
build it themselves.

**The odd one out** — `Installer`, which runs *before* the application is
configured and therefore cannot depend on it.

### The theme chain

Themes inherit rather than duplicate. `Bota` is the base and carries the
Bootstrap 5.3 foundation and the shared partials; `Nova` sets its variables
first and then pulls Bota in; `Macnemo` and `Macfix` build on Nova the same way.

The mechanism is Sass variable defaults: Bota declares its variables with
`!default`, so a child theme that sets them beforehand wins. **A theme variable
that loses its `!default` silently breaks every theme below it** — this is the
single most fragile seam in the stylesheet layer.

### What the browser gets

Not a single-page application. Pages are rendered on the server; the browser
gets **htmx** for swapping fragments and **Alpine.js** for local interactivity,
loaded from three built bundles:

| Bundle | Size | Loaded | Purpose |
|---|---|---|---|
| `boot.bundle.js` | 0.6 KB | synchronously in `<head>` | stylesheet choice and font scale, before the first paint |
| `htmx-threads.bundle.js` | 156 KB | end of `<body>` | the forum: htmx, Alpine, and 20 feature modules |
| `admin.bundle.js` | 48 KB | admin pages only | the administration area |

`boot` is separate for one reason: it has to run *first*, or the page paints in
the wrong theme and then corrects itself visibly.

The feature modules under `frontend/src/islands/features/` are the "islands" —
`editor`, `uploads`, `passkeys`, `widgets`, `threads`, `drafts` and fourteen
more. Each attaches to markup the server produced; none owns a page.

### The API

`/api/v2` still exists and is used, contrary to what its name suggests about
being retired. Eight routes remain — four for `Bookmarks`, four for
`ImageUploader`, including the thumbnails the htmx views embed. Ask
`bin/cake routes` rather than guessing which paths are live. It authenticates by
JWT bearer token only, never by cookie, which is why it is exempt from CSRF
checking (see the reasoning recorded in `src/Application.php`).

## 2. The build

Nothing in `webroot/js` or the themes' `webroot/css` is written by hand. Both
are produced by `yarn build:release`, which is what the release pipeline runs
and what a developer runs locally to see the real thing.

```
yarn build:release
  ├── clean:js        remove previous bundles
  ├── css             sass → expanded CSS
  │     ├── css:static   webroot/css/src/static.scss
  │     └── css:theme    Bota, Nova, Macnemo (and Macfix, on its branch)
  ├── css:postcss     autoprefixer and friends
  ├── css:purge       PurgeCSS — the lossy step
  ├── build           vite, once per entry
  └── assets:copy     fonts and third-party stylesheets into webroot/
```

### Sass

Compiled with Dart Sass against `--load-path=node_modules`, so Bootstrap and
Font Awesome are pulled in as dependencies rather than by relative path. Three
deprecation categories are **fatal** by choice — `slash-div`, `global-builtin`,
`color-functions` — so a cleared class of warning cannot quietly come back.

### PurgeCSS — read this before touching the config

This step cuts the seven stylesheets from about 1.4 MB to 460 KB, roughly a
70 % saving. **It fails silently**: PurgeCSS keeps a rule only when the class
name appears as a literal string in the scanned content, so a class the scanner
never sees is deleted without complaint.

Three blind spots were found the hard way and are the reason the safelist and
the content globs look the way they do:

1. **Markup is built in `src/`, not only in `templates/`.** Thread renderers and
   helpers emit their own classes. Missing those globs once stripped the entire
   thread tree — 27 % of the page.
2. **Icon names are composed at runtime.** `$iconLabel('sign-in', …)` produces
   `fa-sign-in`, which exists nowhere as a literal, so Font Awesome is kept
   whole rather than analysed.
3. **Some classes only exist after `@extend`.**

The gate is `dev/pixel-diff.sh`: twelve comparisons, three themes in both
presets across three pages and three viewport widths. **Zero differing pixels**
is the standard, not "close enough". Re-run it after any change to
`purgecss.config.js`.

### Vite

One build per entry, three entries, each emitted as a **self-contained IIFE** —
no vendor/app chunk split, one file per bundle. The IIFE matters beyond
packaging: nothing declared inside can reach the global object, which is why the
analyser's warnings about globals in these files are false positives. The built
bundle assigns exactly three names to `window`: `htmx`, `Alpine`, `onpopstate`,
all deliberately.

### From tag to tarball

The GitHub Actions pipeline is tag-driven. In order: check that the CHANGELOG
describes this version → run the full PHPUnit suite against a real MariaDB →
`composer audit --locked --no-dev` as a security gate → `composer install
--no-dev` → `yarn build:release` → pack.

The tarball excludes `.git`, `.github`, `dev/`, `build/` and the developer-only
documents, and **includes `vendor/` with a production autoloader**. That last
point is load-bearing: production hosts have no Composer, so the release package
is the only supported way to get a consistent `vendor/` there. Copying an
autoloader from a developer checkout onto a `--no-dev` installation produces an
immediate HTTP 500.

## 3. Browser to backend

### The path a request takes

```
Browser
  └── edge nginx            TLS terminates here; sets X-Forwarded-Proto
        └── nginx :80       forwards everything to Anubis
              └── Anubis    proof-of-work wall against scrapers
                    └── nginx :8081   the forum vhost
                          └── php-fpm  (fastcgi_param HTTPS on)
                                └── CakePHP
```

Two details in that chain cause trouble when forgotten:

**TLS terminates at the edge**, so PHP only knows the request was HTTPS because
the forum vhost passes `fastcgi_param HTTPS on`. Omit it and CakePHP stops
marking the session cookie `Secure` — not with an error, just with a weaker
cookie. This happened on the beta install and went unnoticed for weeks.

**Anubis sits in front of everything.** Clients that cannot solve a JavaScript
proof of work — RSS readers, API clients — need an explicit allow rule in
`botPolicies.yaml`, or they are shown a challenge page instead of content.

### The middleware queue

Order is behaviour here, not style:

```
ErrorHandler → Asset → [trustProxy] → Routing → SaitoBootstrap → BodyParser
  → EncryptedCookie → Authentication → Csrf → SecurityHeaders
```

- **trustProxy runs before routing** so the real client IP and the https scheme
  are known while the request URL is being built. It is gated by
  `Saito.trustProxy`: an installation reachable directly must *not* trust those
  headers, because anyone could send them.
- **EncryptedCookie before Authentication**, because the remember-me cookie is
  encrypted and has to be readable by the time authentication looks at it.
- **CSRF skips `/api/v2`**, which is safe only because that scope accepts no
  cookie credential at all.

### Rendering, and where htmx fits

A normal page is rendered by CakePHP and delivered whole. From then on, most
interactions replace a *fragment*:

25 templates carry htmx attributes — 22 `hx-get`, 15 `hx-post`, 34 `hx-swap`,
29 `hx-target`. The server answers those requests with the fragment alone, using
`htmx_*` templates and a layout that emits no `<head>`. Alpine handles what
needs no server at all: menus, toggles, local state.

This is why the forum has no client-side router and no shared client state to
keep in sync. It is also why `hx-target` and `hx-swap` are worth reading
carefully — they are the contract between a template and the action that answers
it, and nothing checks them.

### Authentication on a request

`AuthUserComponent::initialize()` runs on every request and settles who the
visitor is, in this order:

1. **Feed token** (`Feeds.FeedToken`) — a signed URL identifies a member to
   their RSS reader.
2. **Session** — the ordinary case.
3. **Remember-me cookie** — when the session has expired. This is where a
   three-part token `[account, expiry, signature]` is verified.
4. Otherwise: bot, or guest.

Two guards then run against whatever was established:

- A session whose **password fingerprint** no longer matches the account is
  dropped, which is what makes a password change log out every *other* device.
- For an account with two-factor authentication, a remember-me cookie is
  honoured **only** alongside a trusted-device token issued after a second
  factor was actually proved. The cookie alone is stateless and cannot be
  revoked; the device row can.

### Which password formats are accepted

Two, and the list is short on purpose:

| Format | Length | Accepted | |
|---|---|---|---|
| bcrypt | 60 | yes | everything this software has written for years |
| salted sha1 | 50 | yes | what mylittleforum 2.x wrote; rewritten as bcrypt on the next login |
| plain md5/sha1 | 32/40 | **no** | older installations still carry these |

The third is refused deliberately: accepting an unsalted hash from that era
would turn something trivially crackable back into a working credential. Nobody
is locked out permanently — the password reset issues a bcrypt hash like any
other login would, without reading the old value.

Measured on the production install in August 2026: 534 accounts still held a
32-character hash, none used since 2013, against 287 on bcrypt. 374 of them had
written postings, which is why the answer empties a column rather than deleting
accounts:

```
bin/cake clear_unusable_passwords            # count them, change nothing
bin/cake clear_unusable_passwords --clear    # empty those columns
```

It decides by shape, not by length: usable means either PHP recognises the hash
(`password_get_info`) or it is the 50 hex characters the mylittleforum format
uses. A hash it cannot classify is left alone.

A command rather than a migration, because emptying a member's password is the
operator's decision about their own data — not something a release should do to
them while they are reading the CHANGELOG. Whether an account dormant for
thirteen years should be *kept* is a separate, larger question, and a
data-protection one rather than a security one.

A cookie whose token is not in the current shape is now discarded rather than
merely refused. Before 8.4.9 it was refused on every request and left in place,
which returned the member to the login form indefinitely — they could not clear
it themselves, because it is `HttpOnly`.

## Where the fragile parts are

Collected here because each has cost real time, and none is visible from the
code alone:

| | |
|---|---|
| Theme variables without `!default` | break every child theme, silently |
| PurgeCSS content globs | delete markup nobody scanned, silently |
| `fastcgi_param HTTPS on` | its absence weakens cookies, silently |
| `vendor/` autoloader from a dev checkout | immediate HTTP 500 |
| `config/bootstrap.php` | lives in `config/` but is **not** installation-specific — a deploy that skips `config/` wholesale breaks the site |
| `.env` values containing spaces | must be quoted since 8.4.10, or the forum will not start |

The last two are the same mistake in two forms: assuming a directory's name
tells you what is safe to skip.
