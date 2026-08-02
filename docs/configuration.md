# Configuration

Every setting an operator can hand Saito from the environment, in one place.

Saito reads these through CakePHP's `env()`, so each one can come from the
web-server environment (an FPM pool's `env[…]`, a systemd unit) or from
`config/.env`, which `config/.env.default` is the template for. Anything not set
falls back to the default in the table.

**This file lists the environment. It is not the whole configuration.** Two other
layers sit above it and are edited elsewhere:

- `config/saito_config.php` — installation-specific choices that are not secrets
  (theme, imprint, the unread rail, upload limits). It is deployed *per install*
  and must be merged rather than overwritten on an update.
- The **admin area** — everything a forum owner changes while the forum runs:
  the forum's timezone, subject length, registration policy, categories.

A rule that has cost time before: the forum's timezone is an admin setting, not
`APP_DEFAULT_TIMEZONE`. See below.

## Application

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

## Database

| Variable | Default | What it does |
|---|---|---|
| `DATABASE_URL` | — | `mysql://user:password@host/database?encoding=utf8mb4`. Required. |
| `DATABASE_TEST_URL` | — | The same for the test suite. **Point it at its own database** — the suite truncates tables. |

## Security

| Variable | Default | What it does |
|---|---|---|
| `SECURITY_SALT` | — | At least 32 random characters. Hashing and encryption key. Required. |
| `SECURITY_COOKIE_SALT` | — | The same, for cookie encryption. |
| `SECURITY_JWT_SALT` | *(falls back to the cookie salt)* | Signs the API tokens issued by the JWT path. |
| `SAITO_TRUST_PROXY` | `false` | Trust `X-Forwarded-*` for the client's real IP and the https flag. **Only with a trusted proxy in front.** On a directly reachable install a client can then forge its own IP, which defeats every throttle that counts per address. |

## Email

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

## Beta and staging installs

| Variable | Default | What it does |
|---|---|---|
| `SAITO_BETA` | `false` | Marks the install as a beta: a banner, and **outgoing mail off by default** — a beta usually runs on a clone of the live database with real addresses, and registration, notifications and password resets must not reach those people. |
| `SAITO_DEBUG_EMAIL` | *(follows `SAITO_BETA`)* | Overrides that: `true` lets a beta send, `false` silences a live install. |
| `SAITO_NOINDEX` | `false` | Emits `robots: noindex`, so a staging copy does not compete with the live forum in search results. Worth pairing with an `X-Robots-Tag` header at the web server, since a header cannot be missed by a crawler that ignores the meta tag. |

## Caches and logs

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

## Not settable

`HTTP_HOST`, `HTTPS`, `REMOTE_ADDR`, `SERVER_NAME`, `SCRIPT_NAME`,
`HTTP_USER_AGENT`, `HTTP_X_MOZ`, `HTTP_X_PURPOSE` also appear in the source.
Those come from the web server per request; they are listed here only so that a
grep for `env(` does not leave anyone wondering.
