# Configuration

Every setting an operator can give Saito, in one place: the ones edited in
`config/saito_config.php` and the ones handed in through the environment.

**Three layers, and this file covers two of them.** `config/saito_config.php`
first, because it is the one nothing else documents; then the environment.

The third is the **admin area**, and it is not documented here: everything a
forum owner changes while the forum runs — the forum's timezone, subject length,
registration policy, categories — is edited in the browser and stored in the
`settings` table.

### Which layer wins

The database is loaded into `Saito.Settings`, and what `saito_config.php` put
there is passed in as a preset:

```php
// SettingsTable::load()
$settings = $preset + $settings;   // PHP keeps the LEFT side on a collision
```

So **the file wins over the admin area**, not the other way round. That is worth
knowing before assuming a setting can be changed in the browser: where both name
the same key, the admin form saves the value, the database holds it, and the
running forum ignores it.

**Today nothing collides.** The file contributes four keys under
`Saito.Settings` — `ParserPlugin`, `uploadDirectory`,
`answeringAutoSelectCategory` and `uploader` — and none of them is an admin
setting; checked against both a fresh install and a forum with 37 settings rows
on 2026-08-02. Everything else the file sets lives under `Saito.*` rather than
`Saito.Settings`, where the admin area never reaches at all.

The trap is only there for whoever adds the next key. Adding one under
`Saito.Settings` that an admin form also writes creates a setting that cannot be
changed, and it fails silently — no error, the form just has no effect.

A rule that has cost time before: the forum's timezone is an admin setting, not
`APP_DEFAULT_TIMEZONE`. See below.

## `config/saito_config.php`

Everything below is set by **editing the file**, not by an environment variable.
It is install-specific: keep your own copy, and on an update *merge* rather than
overwrite it — an incoming release carries the defaults, not your imprint.

Keys that also read an environment variable (`installed`, `updated`, `language`,
`beta`, `noindex`, `trustProxy`, `debug.email`) are in the tables further down
instead; set those from the environment.

Where a setting has a page of its own, this table points at it rather than
repeating it: themes and the front page in [customizing.md](customizing.md), the
privacy text in [privacy-policy-template.md](privacy-policy-template.md).

### Pages and markup an installation has to provide

| Key | Default | What it does |
|---|---|---|
| `imprint` | `''` | Trusted HTML for `/pages/impressum`, linked from the footer. Empty shows a "not configured" notice. Legally required in some jurisdictions — nobody else can fill this in for you. |
| `privacy` | `''` | The same for `/pages/privacy`. What you have to declare depends on your hosting and your admin settings; [privacy-policy-template.md](privacy-policy-template.md) lists what Saito itself processes. |
| `headHtml` | `''` | Injected into every page's `<head>` — an analytics snippet, say. **Rendered unescaped.** If a content-security policy is in force, an inline script here is exactly what it blocks; put the code in a file and reference it. |
| `bannerHtml` | `''` | HTML between the header bar and the content, in `div.ads_top`. Also unescaped, also operator-only. The container is omitted entirely when empty. |

### Switches

| Key | Default | What it does |
|---|---|---|
| `notice` | `true` | The banner explaining the modernised frontend. Meant for the weeks around a switch; turn it off once that has passed. |
| `noticeHelp` | `true` | The second line of that banner, pointing newcomers at the help icon. Separate on purpose — the notice above stops being true, this one does not. |
| `widgetsForGuests` | `true` | Whether visitors who are not signed in see the widget rail (who is online, recent postings). `false` also makes the fragment endpoint answer a guest with nothing, so it cannot be read by fetching it directly either. |
| `unreadRail` | `true` | The short vertical bar beside unread thread lines. Unread is marked three ways — accent colour, bold, and this bar — so `false` drops the bar and leaves the other two. A forum whose readers are used to colour alone reads the bar as clutter. The space stays transparent, so switching it does not shift the list sideways. |
| `X-Frame-Options` | `SAMEORIGIN` | The header sent with every response. Only loosen it if something genuinely has to frame the forum. |
| `Globals.postingsPerThread` | `10` | An empiric average used for sizing, not a limit. |
| `debug.logInfo` | `false` | Writes additional non-error information to `info.log`. |

### Behaviour

| Key | Default | What it does |
|---|---|---|
| `Settings.ParserPlugin` | `BbcodeParser` | The markup parser. A replacement lives in `plugins/<name>Parser`. |
| `Settings.uploadDirectory` | `webroot/useruploads/` | Where uploads are written. Trailing separator required. On a deployment that replaces the whole tree, this wants to be a symlink to shared storage. |
| `Settings.answeringAutoSelectCategory` | `false` | `true` preselects the first available category in the posting form; `false` makes the author choose. |
| `themes.default` | `Nova` | What a fresh installation starts with. Existing installs keep whatever their own config says. |
| `themes.available` | *(unset)* | Additional themes offered to everyone. See [customizing.md](customizing.md). |
| `themes.users` | *(unset)* | Themes offered to named users only: `[<user-id> => ['<theme>']]`. Useful for trying one out on the live forum. |
| `bots` | *(built-in list)* | Extra user-agent patterns to treat as robots, merged with the built-in list. A bot is served the "everything read" dummy, so a wrong entry here makes a real member's unread state disappear. |

### Uploads

Set through the `UploaderConfig` builder at the bottom of the file. The limits
are Saito's own — **PHP's `upload_max_filesize` and `post_max_size` and the web
server's body limit have to be at least as large**, or the upload fails before
Saito ever sees it.

| Call | Default | What it does |
|---|---|---|
| `setMaxNumberOfUploadsPerUser()` | `5000` | Files one member may hold at a time. |
| `setDefaultMaxFileSize()` | `8MB` | Applies to every type without its own limit. |
| `setDefaultMaxResize()` | `650kB` | Above this, jpeg and png are re-encoded smaller. |
| `setImageCompressionQuality()` | `92` | Quality when re-encoding, 0–100. |
| `setMaxImagePixels()` | `40000000` | Width × height ceiling. Rejects decompression bombs — small on disk, enormous once decoded. 40 MP covers ordinary camera and phone photos. |
| `addType()` | see file | Allowed mime types, optionally with a per-type limit: `addType('image/jpeg', '19MB')`. Shipped with audio, jpeg/png/webp, `text/plain` and mp4/webm. |

## Environment

The rest of this page. Saito reads these through CakePHP's `env()`, so each one
can come from the web-server environment (an FPM pool's `env[…]`, a systemd
unit) or from `config/.env`, for which `config/.env.default` is the template.
Anything not set falls back to the default in the table.

Prefer these over editing `config/app.php`: they survive an update, which a file
in the release tarball does not.

### Application

| Variable | Default | What it does |
|---|---|---|
| `APP_NAME` | `Saito` | Names the cache-key prefix and the default mail sender name. |
| `DEBUG` | `false` | Error pages with stack traces, DebugKit, no template caching. **Never true on a public install** — it exposes configuration and queries. |
| `APP_ENCODING` | `UTF-8` | Internal character encoding. No reason to change it. |
| `APP_DEFAULT_LOCALE` | `en_US` | Drives `intl`: date, time and number *formatting*. Not the UI language. |
| `APP_DEFAULT_TIMEZONE` | `UTC` | **Leave at `UTC`.** Not the forum's timezone — that is an admin setting, and Saito renders displayed times into it. This tells PHP how to *read* what comes out of the database, and that is UTC: CakePHP pins its own connection to `+00:00` whatever the DSN says. A local zone here makes every timestamp wrong by the offset. |
| `SAITO_LANGUAGE` | `en` | UI translation bundle: `de` or `en`. |
| `SAITO_FULL_BASE_URL` | *(derived)* | Absolute base URL (`https://forum.example.org`). Set it when Saito cannot work the URL out itself — behind a proxy, or for links in mail sent from the command line. |
| `INSTALLED` | `false` | `false` routes every request to the installer. The installer sets it. |
| `UPDATED` | `false` | `false` routes to the updater whenever `db_version` and the code version differ. Set `true` on an install that is deployed by other means and manages its own schema — a beta that is re-cloned nightly, for instance. |

### Database

| Variable | Default | What it does |
|---|---|---|
| `DATABASE_URL` | — | `mysql://user:password@host/database?encoding=utf8mb4`. Required. |
| `DATABASE_TEST_URL` | — | The same for the test suite. **Point it at its own database** — the suite truncates tables. |

### Security

| Variable | Default | What it does |
|---|---|---|
| `SECURITY_SALT` | — | At least 32 random characters. Hashing and encryption key. Required. |
| `SECURITY_COOKIE_SALT` | — | The same, for cookie encryption. |
| `SECURITY_JWT_SALT` | *(falls back to the cookie salt)* | Signs the API tokens issued by the JWT path. |
| `SAITO_TRUST_PROXY` | `false` | Trust `X-Forwarded-*` for the client's real IP and the https flag. **Only with a trusted proxy in front.** On a directly reachable install a client can then forge its own IP, which defeats every throttle that counts per address. |

### Email

Without `EMAIL_SMTP_HOST` Saito uses PHP's `mail()`. Setting it switches the
whole transport to SMTP, and the remaining `EMAIL_SMTP_*` values apply.

| Variable | Default | What it does |
|---|---|---|
| `EMAIL_FROM_ADDRESS` | `noreply@localhost` | Sender address on everything the forum sends. |
| `EMAIL_FROM_NAME` | `Forum` | Sender name. |
| `EMAIL_SMTP_HOST` | *(unset)* | SMTP server. Unset means `mail()`. |
| `EMAIL_SMTP_PORT` | `587` | |
| `EMAIL_SMTP_USERNAME` | *(unset)* | |
| `EMAIL_SMTP_PASSWORD` | *(unset)* | |
| `EMAIL_SMTP_TLS` | `true` | STARTTLS. Turning it off sends credentials in the clear. |
| `EMAIL_TRANSPORT_DEFAULT_URL` | *(unset)* | A complete CakePHP transport DSN, for a transport the fields above cannot express. Overrides them. |

### Beta and staging installs

| Variable | Default | What it does |
|---|---|---|
| `SAITO_BETA` | `false` | Marks the install as a beta: a banner, and **outgoing mail off by default** — a beta usually runs on a clone of the live database with real addresses, and registration, notifications and password resets must not reach those people. |
| `SAITO_DEBUG_EMAIL` | *(follows `SAITO_BETA`)* | Overrides that: `true` lets a beta send, `false` silences a live install. |
| `SAITO_NOINDEX` | `false` | Emits `robots: noindex`, so a staging copy does not compete with the live forum in search results. Worth pairing with an `X-Robots-Tag` header at the web server, since a header cannot be missed by a crawler that ignores the meta tag. |

### Caches and logs

Each takes a CakePHP engine DSN. Unset means the default from `config/app.php`,
which is the file engine under `tmp/`. Worth setting only when moving a cache to
Redis or Memcached, or a log somewhere central.

| Variable | Covers |
|---|---|
| `CACHE_DEFAULT_URL` | The general cache. |
| `CACHE_CAKECORE_URL` | Translations, i18n. |
| `CACHE_CAKEMODEL_URL` | The schema cache — the one that has to be cleared after a migration. |
| `CACHE_CAKEROUTES_URL` | The compiled route table. |
| `LOG_ERROR_URL` | `error.log`. |
| `LOG_DEBUG_URL` | `debug.log`. |
| `LOG_QUERIES_URL` | The query log. Only written with `DEBUG=true`. |

### Not settable

`HTTP_HOST`, `HTTPS`, `REMOTE_ADDR`, `SERVER_NAME`, `SCRIPT_NAME`,
`HTTP_USER_AGENT`, `HTTP_X_MOZ`, `HTTP_X_PURPOSE` also appear in the source.
Those come from the web server per request; they are listed here only so that a
grep for `env(` does not leave anyone wondering.
