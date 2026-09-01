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
| `tos` | `''` | Trusted HTML for `/pages/tos`, linked from the footer and from the registration form when `tos_enabled` is on. **Empty renders a shipped German default** with your forum's name filled in and the operator taken from the imprint — set this only to replace it; [terms-of-service-template.md](terms-of-service-template.md) is the same text to start from. Changing the terms materially? Raise the `tos_version` setting (Admin → Settings) and every member is asked to agree again. |
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

### Behind a bot filter (Anubis, Cloudflare, and the like)

**The island's XHR requests must not be challenged.** A proof-of-work challenge
cannot be answered from an XHR: the filter returns its challenge page, htmx swaps
that HTML into the running page, and the reader watches the bot-check graphic
appear over the forum. Reloading fixes it, because a real navigation can solve
the challenge — so it looks intermittent and harmless, and it is neither.

This is not one or two paths. The frontend calls htmx endpoints across several
controllers — the new-posting count and widget rail poll in the background, and
opening a posting, replying, editing, the "show new postings" reload and the
profile forms all swap fragments in. Allow-listing them one path at a time does
not hold: a release adds an endpoint, it is not on the list, and the graphic is
back. Match the request instead of the route.

Every htmx request carries the header `HX-Request: true`, and none of them can
solve a challenge. That header is the rule. For Anubis:

```yaml
  - name: forum-htmx
    action: ALLOW
    expression:
      all:
        - '"Hx-Request" in headers'   # htmx sets this on every request
        - 'path.contains("/htmx-")'   # the forum's htmx routes are named htmx-*
```

The header alone would let anything that sets it skip the challenge site-wide;
the `path.contains("/htmx-")` clause keeps a forged header confined to the
fragment endpoints, which is where the exemption is needed and no further. (CEL
header keys are canonicalised, so `HX-Request` is read as `Hx-Request`.) On a
filter without CEL, fall back to a path allow-list — `path_regex:
^/(entries|users)/htmx-` — and remember to extend it when a new controller grows
htmx routes.

**Stylesheets, scripts and images need the same exemption**, and this one fails
in a shape that is easy to misread. A browser fetches them *after* it has passed
the check for the page that references them, but each fetch is a separate request
and the filter judges it separately. A challenged stylesheet comes back as
`HTTP 200` with `Content-Type: text/html`; the browser discards it, and the forum
renders unstyled. There is no bot graphic to see and nothing in the Saito log —
looking only for the challenge page will not find this.

```yaml
  - name: forum-static-assets
    action: ALLOW
    expression: 'path.matches("\\.(?:css|js|mjs|map|woff2?|ttf|otf|eot|svg|ico|png|jpe?g|gif|webp|avif)$")'
```

Note what the image extensions cost: uploaded attachments under
`/useruploads/` become fetchable without a challenge too. If that matters for
your forum, drop the image extensions from the rule and keep it to `css`, `js`,
`mjs`, `map` and the font types — the layout is then correct and the attachments
stay behind the filter.

To confirm the fix, read the **`Content-Type`**, not the status code: the
challenge answers `200` just as the real file does.

```console
$ curl -s -o /dev/null -w '%{http_code} %{content_type}\n' https://example.org/nova/css/theme.css
200 text/css
```

**Also check the challenge cookie's `SameSite`.** Anubis defaults to `None`,
which marks a first-party cookie as usable across sites — exactly what Safari's
tracking protection and Chrome's third-party cookie phase-out restrict. `Lax` is
correct here (`-cookie-same-site Lax`), and without it the cookie can go missing
on the very requests that need it.

**Why it surfaces on phones.** Anubis binds its clearance token to the client
IP (`X-Real-IP`). A phone's IP changes as it moves between cell and Wi-Fi, the
token stops matching, and the next request is challenged afresh — and if that
next request is an htmx XHR, the challenge lands in the page rather than in a
navigation that could solve it. The rule above is what makes that harmless.

### Outbound notifications

Under `Saito.webhooks.user`, for a companion app or a moderation queue that
lives outside the forum. Provided by the `Webhooks` plugin.

| Key | Default | What it does |
|---|---|---|
| `url` | `''` | Where to post. **Empty switches the whole plugin off**, listener included, so installations that do not use it pay nothing. |
| `secret` | `''` | Shared with the receiver. The body is signed with HMAC-SHA256 and sent as `X-Saito-Signature: sha256=…`. Set it: without one, a receiver cannot tell this forum's call from anybody else's who guessed the URL, and URLs end up in logs. |
| `events` | all three | Which of `register`, `activate`, `delete` to send. Omitting the key means all of them. |
| `timeout` | `3` | Seconds. |
| `legacyContactFields` | `false` | **Deprecated on arrival, and it will be removed.** Puts the member's email address and IP back into the payload, for a receiver written against an older integration. It exists so a forum can upgrade Saito now and rewrite that receiver afterwards rather than doing both at once — not as a supported way to ship personal data. Every send with it enabled writes a warning to the log. The IP still follows `store_ip` and `store_ip_anonymized`: sending is more exposing than storing, so the stricter setting wins. |

The body carries the member's id, username and a UTC timestamp — deliberately no
email address and no IP. A receiver that needs more should ask the API with its
own credentials, where the request is authenticated and can be refused.

**Delivery is best-effort.** The call happens inside the request that registered
the member, so a slow or dead endpoint must not fail their registration:
failures are logged and dropped. Poll the API instead if an event must not be
missed.

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

Both sources may be present at once, and the environment wins: if it sets
`APP_NAME`, `config/.env` is not read at all; otherwise the file supplies only
the keys the environment has not already defined. Nothing has to be edited to
enable the file — it is read whenever it exists.

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

### Requiring a second factor of staff

Admin → Settings → *Security* → **Require two-factor authentication from**.
Three values, `off` by default so an upgrade changes nothing:

| Value | Who has to enrol |
|---|---|
| `off` | Nobody. Two-factor authentication stays optional for everyone. |
| `mod` | Moderators **and** administrators — an administrator holds the moderator permissions, so requiring it of moderators while exempting the people who can reset them would be the wrong way round. |
| `admin` | Administrators only. |

Ordinary members always keep the choice. The asymmetry is deliberate: the cost
of a compromised member account is one member, the cost of a compromised
administrator account is the forum.

Somebody affected who has not enrolled meets a page asking them to, instead of
whatever they requested — including the admin backend. Three things stay
reachable so that page is never a dead end: setting the second factor up,
logging out, and signing in.

**Turning this on can lock you out**, and that is worth a moment's thought
before you do: if your authenticator app is on a phone in another room, you will
be sent to the enrolment page and you will need the app to leave it. There is a
way back that needs no login at all — see below — but it needs a shell on the
server.

Two more things that surprise people:

- **A promotion takes effect on the promoted member's next request.** Making
  somebody a moderator while this is set to `mod` sends them to the enrolment
  page. Correct, and worth mentioning to them first.
- **Existing sessions are not dropped.** The check runs per request, so the next
  thing anybody does already lands on the page. Invalidating sessions would cost
  the whole forum its login to achieve nothing.

### Resetting a second factor from the console

```shell
bin/cake two_factor_reset <username>
```

Turns two-factor authentication off for one account, clearing the authenticator
secret, the recovery codes, the trusted devices and the passkeys. The account
itself is untouched — it signs in with its password again and can set the second
factor up anew.

This exists for the case the admin screen cannot cover: an administrator locked
out with no second administrator to ask. On a forum run by one person there is
nobody else.

**Use the command, not SQL.** A reset means four tables today and that list has
grown twice; a `DELETE` written from memory clears the credential, restores the
sign-in, and leaves the recovery codes and the passkey standing — a passkey
completes the second step on its own, so the device you were trying to cut off
would still get in. The command knows the list, and it logs who lost their
second factor and when.

**It needs the database configuration, and the console may not have it.** Where
the connection comes from `config/.env`, this just works. Where it is set as an
environment variable on the PHP-FPM pool instead — as the nginx example in
`config/php-fpm/saito.pool.conf.example` does — the web server has it and your
shell does not, and the command stops with a stack of PDO lines that look like
the rescue itself is broken. Pass it through:

```shell
DATABASE_URL="mysql://user:password@localhost/saito?encoding=utf8mb4" \
    bin/cake two_factor_reset <username>
```

Worth trying **before** you need it. This is the one command whose first use is
usually under pressure, and finding out then that it cannot reach the database
is the wrong moment.

### Password hashes nothing can use

```shell
bin/cake clear_unusable_passwords            # count them, change nothing
bin/cake clear_unusable_passwords --clear    # empty those columns
```

A forum grown from mylittleforum carries hashes in formats the sign-in stopped
accepting years ago. Two are accepted and no more: bcrypt, and the salted sha1
mylittleforum 2.x wrote. A plain md5 or sha1 from before that matches neither,
so those accounts cannot sign in and have not been able to for years.

They are also years of reused passwords sitting in a table, and people use the
same password in more than one place — if the database is ever disclosed, the
exposure is not to this forum but to whatever else those members used it for.

Emptying the column costs nothing functionally. The password reset issues a
bcrypt hash without reading the old value, so those members recover by e-mail
exactly as before, and an emptied column authenticates nobody — not even an
empty password. It empties a column rather than deleting accounts, because most
such accounts have written postings.

**Look before you clear.** Without `--clear` the command only counts, and the
count is worth reading: on the macnemo.de installation it was 534 accounts,
none used since 2013, against 287 on bcrypt. A number far from that on your
forum is worth understanding before acting on it.

Same database caveat as the reset above — pass `DATABASE_URL` if the connection
lives on the FPM pool rather than in `config/.env`.

### Which version the database thinks it is

```shell
bin/cake db_version              # what are they, and do they agree?
bin/cake db_version 8.4.14       # record a version
```

An installation carries two version numbers: the code's, from
`src/Lib/version.php`, and `db_version`, a row in `settings`. The updater
compares against the row. After a code-only release they only match because
somebody set the row by hand, and they had quietly drifted apart on two of the
three installations here — invisible from the outside, and the consequence of
the row being ahead is the updater deciding it has nothing to do.

Disagreement is reported as a warning, not an error: that is the normal state
between copying files and setting the row.

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

### Monitoring

| Variable | Default | What it does |
|---|---|---|
| `SAITO_METRICS_TOKEN` | *(empty)* | Bearer token a Prometheus scraper must send to `/metrics`. **Empty means the address answers 404**, so an installation that has not asked for metrics is indistinguishable from one that never had them — and a wrong token gets the same 404, not a 401. |

The exposition is `text/plain; version=0.0.4` at `/metrics`, deliberately outside
`/api/v2`. Counters come from the cache the front page already fills, so a scrape
costs what a page view costs: measured on the reference install, **315 ms cold
and 2–7 ms warm**.

```yaml
scrape_configs:
  - job_name: saito
    metrics_path: /metrics
    authorization:
      credentials_file: /etc/prometheus/saito.token
    static_configs:
      - targets: ['forum.example.org']
```

**Put a second lock in front of it.** The token is all this code can do; the
exposition still tells a stranger how many members the forum has, how busy it is
and which version it runs. One `allow`/`deny` pair at the web server, restricted
to the monitoring network, costs nothing.

The metric worth an alert is `saito_db_version_matches`. When it reads 0 the
schema version and the code version disagree, and Saito routes **every** request
to the updater — the forum is effectively down, and nothing writes an error,
because it is not one. That has happened here, from a deploy that copied
`version.php` without setting `db_version`.

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
