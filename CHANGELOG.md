# Change-Log

- ＋ Added
- ✓ Fixed
- Δ Changed
- − Removed

## [8.4.10] - 2026-08-17 "nachschub"

**No migration.** The JS bundle changed with the Alpine.js update, so the
compiled assets go out with it.

```bash
php bin/cake.php schema_cache clear
```

Two suppliers had stopped producing — one nine years ago, one three — and this
release replaces them. A third was kept, deliberately, because it still delivers
what it is asked for.

- Δ Changed: **the RSS feeds are written with `laminas/laminas-feed`.**
  `suin/php-rss-writer` last published in 2017 and still declares `php >=5.4.0`.
  Nothing was wrong with it; it simply stopped.

  The gate was not that the feed still validates but that **the `guid` must not
  move** — it is what subscribers are keyed on, and changing it makes every
  reader re-announce every posting it has already shown. Compared before and
  after across both feeds, against real postings and not only test data: `guid`,
  `pubDate`, `link` and the posting bodies are byte-identical. Nobody's reader
  will report anything as new.

  Two faults the old library had been swallowing came out in the process, and
  either would have replaced the whole document with an error rather than
  degrading quietly: an empty subject, which replies commonly have, and an empty
  body. Both are now handled.

  Visible differences are equivalent or better: item titles are escaped instead
  of CDATA-wrapped, `atom:link` is absolute where it used to be a bare path a
  reader cannot resolve, and the channel carries a description instead of an
  empty element. `<generator>` names the forum without a version rather than
  announcing the library, and the writer's `slash:comments` — which would have
  told every reader that every posting has no replies — is taken back out.

- Δ Changed: **`config/.env` is read with `vlucas/phpdotenv`.**
  `josegonzalez/dotenv` last published in 2023 and sits on `m1/env`, silent since
  2020 — two dormant layers.

  `symfony/dotenv` was the first choice and was rejected after measuring: it
  interpolates the unbraced `$name` form as well, so a value containing a dollar
  sign — a database password, most plausibly — is silently rewritten, and
  truncated where the name is undefined. `phpdotenv` reads every case tried
  exactly as before.

  **A key defined both in the environment and in `config/.env` no longer
  errors.** The old loader raised "Key already defined" and answered with an
  HTTP 500; now the environment simply wins. If you removed a key from one side
  to work around that, you can put it back.

  One narrower change: `\$` in `config/.env` now resolves to a literal `$`
  rather than staying two characters. A password written that way changes
  meaning. Bare `$name` and `${name}` behave as before. See
  [update.md](docs/update.md).

- ✓ Fixed: **three places said the `.env` loader had to be switched on.** It
  does not — `config/bootstrap.php` reads the file whenever it exists, and has
  for a long time. `docs/deployment-debian.md`, the header of
  `config/.env.default` (which also misspelled `bootstrap.php`) and the comment
  the other two were copied from now describe what actually happens, including
  that the environment wins twice over: `APP_NAME` present skips the file
  entirely, and otherwise only unset keys are filled in.

- Δ Changed: Alpine.js 3.15.12 → 3.16.1, and the build tooling (`cssnano`,
  `eslint`, `globals`, `typescript-eslint`) to current.

## [8.4.9] - 2026-08-16 "altschlüssel"

**No migration.** One PHP file changed — no assets, no schema.

```bash
php bin/cake.php schema_cache clear
```

- ✓ Fixed: **a remember-me cookie that can never work again is now taken back.**
  The token in that cookie has had three parts since 7.0.4 — account, expiry,
  signature. One with two parts is the shape the authentication library used
  before that, and it is refused, because its second part is a password hash and
  it carries no expiry at all. Refusing it was never the problem.

  Nothing *cleared* it. The browser presented the same dead cookie on every
  request that followed, so the member was returned to the login form over and
  over — every fifteen minutes in the case that surfaced this, second factor and
  all — and could do nothing about it, because the cookie is `HttpOnly` and
  therefore invisible and untouchable from their side. Anyone who last ticked
  "stay signed in" before 2026-06-30 can be carrying one.

  Only shapes that cannot work are taken back. A current three-part token that
  fails on its expiry or its signature is left alone: judging that belongs to
  the authenticator, and such a cookie carries a real lifetime and leaves on its
  own.

  The discard is written to `saito-info.log`. The silence is what made this
  expensive to find: when the cookie is refused no authenticator succeeds, so
  what reaches the application is the *form* authenticator complaining that some
  unrelated address "did not match `/login`" — a message pointing away from the
  cause. An evening went into session timeouts, the reverse proxy and the
  two-factor tables before the cookie itself came into view.

  **What an operator will see:** a member in this state is asked to log in one
  last time, and from then on "stay signed in" holds. Nobody who was working
  normally is affected — a valid cookie is never touched.

## [8.4.8] - 2026-08-16 "weissblende"

**No migration.** One stylesheet per macnemo preset changed; nothing else.

```bash
php bin/cake.php schema_cache clear
```

- ✓ Fixed: **the primary button reads white again.** Bootstrap 5 works out a
  button's label colour from its background rather than always using white, and
  on macnemo's orange it picked black — so a button that had looked one way for
  years changed with 8.4.7 and looked wrong.

  Only the label is set back. The orange is untouched: it carries white at
  3.21:1, above the 3:1 the accessibility guidelines ask of large text, which a
  bold button label is. Bootstrap was applying the stricter body-text threshold,
  which is a defensible choice rather than a bug — just not the right one for a
  button.

## [8.4.7] - 2026-08-16 "unterbau"

**No migration.** The stylesheets and the JS bundle changed, so those go out
with it.

```bash
php bin/cake.php schema_cache clear
```

Nothing here is meant to be seen. The rooms look the same; what carries them has
been replaced — Bootstrap, the web fonts, the admin area's form rendering. The
one visible change is a number on a phone.

- Δ Changed: **the themes run on Bootstrap 5.3.8.** 4.6.2 had had no
  maintenance since January 2023, and its JavaScript was never loaded here, so
  this was a maintenance question rather than a security one — but an
  unmaintained dependency is exactly what this line of releases is for.

  A major version does move things: spacing, corner radii and form controls all
  shift a little. Nothing was held to a pixel comparison, deliberately — that
  gate is what turns a migration into a reimplementation.

  Bootstrap 5.3 also brings a colour-mode implementation built on custom
  properties, which the hand-maintained night presets can build on later.

- Δ Changed: **the stylesheets have a spacing and text scale of their own.**
  Seventy-three `@extend` onto Bootstrap's utility classes became real
  declarations against `$space-xs` … `$space-xl`, `$text-quiet` and friends.
  `@extend .mb-3` said "whatever Bootstrap means by three"; a theme could
  neither reason about that nor override it. Documented in
  [customizing.md](docs/customizing.md).

- Δ Changed: **the theme fonts come from `@fontsource`.** The `typeface-*`
  packages stopped publishing in 2022. Cabin and Fenix are unchanged — same
  faces, same licence, a maintained package.

- − Removed: **the BootstrapUI dependency.** It had not been doing anything
  since the CakePHP 5 upgrade: it supplied its helpers through
  `Controller::$helpers`, a property CakePHP 5 removed, so the admin area had
  been rendering with the framework's own FormHelper for some time without
  anyone noticing. Removing it changes nothing that is rendered — verified
  byte-for-byte across thirteen admin pages.

- ✓ Fixed: **the number of members online now shows on a phone.** It is
  anchored to the widget's icon, and that icon is hidden on narrow screens — so
  the count went with it, and the folded state, the one where the figure is
  worth having, was the one state it never appeared in.

## [8.4.6] - 2026-08-15 "dienstschlüssel"

**One migration runs, and it rewrites `entries`.** Not additive like the five
before it: nine columns change type, so the table is rebuilt row by row.
Measured between 70 seconds and four and a half minutes for 680,000 postings,
depending on the server. On MariaDB 11.2 or later the forum stays writable while
it happens; everywhere else the table is readable but not writable until it
finishes. **Run it from the command line** — PHP's execution limit will cut a
rewrite short through the web updater.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

The key that comes with the office. A forum can now require a second factor of
the people who can change it — and, because a lock without a key is how an
operator loses their own forum, three ways back out.

- ＋ Added: **a forum can require two-factor authentication of its moderators
  and administrators.** `off` by default, so upgrading changes nothing for
  anybody; an operator sets it to `mod` or `admin` in the admin area. Ordinary
  members are never caught by it — the cost of a compromised member account is
  one member, the cost of a compromised administrator account is the forum.

  Every exemption in it is load-bearing. Somebody who switches the setting on
  while their authenticator app is on a phone in another room must still be able
  to set the second factor up, and to log out; both stay reachable from behind
  the gate, and both are tested one by one. For the case that has no interface
  left at all there is `bin/cake two_factor_reset <username>`, which clears the
  second factor, the recovery codes, the trusted devices and the passkeys of one
  named account without touching the account itself.

- ＋ Added: **the columns postings are written to now outlive 2038.** `timestamp`
  cannot hold an instant after 2038-01-19; nine columns move to `datetime`,
  which has no such limit, and `useronline.time` — a Unix timestamp in a signed
  `INT`, the same date by another road — becomes `BIGINT`.

  Eleven years off, and worth doing now rather than under pressure: the
  conversion has to rebuild the tables either way, and the two ways it can go
  wrong quietly are both handled. The session's timezone decides what a
  `timestamp` becomes when it turns into a `datetime`, so the migration pins it
  to UTC rather than trusting the host's clock; and a `0000-00-00` left over
  from an older MySQL would abort the whole chain under that server's default
  `sql_mode`, so those values are let through unchanged instead.

- ✓ Fixed: **the new setting showed its own translation key** where its name and
  explanation belong. The admin area reads those from the `nondynamic` catalogue
  and the strings had been added to `default`, so the screen offered
  `2fa_required_from_role_exp` as if it were a sentence. A test now renders every
  settings label and fails on any key that reaches the page untranslated.

- ✓ Fixed: **the passkey is mentioned where people look for it.** It could only
  be found by opening the second-factor screen and reading to the end, which is
  not where somebody wonders whether their fingerprint would work here.

- Δ Changed: the upgrade and update documents describe what this migration costs
  and which releases need the command line, and the bot-filter section explains
  the exemption static files need — a challenged stylesheet arrives as
  `Content-Type: text/html` and the forum renders unstyled, with nothing in the
  log to find.

## [8.4.5] - 2026-08-07 "handschlag"

**Two migrations run.** One adds `webauthn_credentials`; the other deletes
credentials belonging to accounts that no longer exist, and touches nothing that
belongs to a live one. Neither has a `down()`. The stylesheets and the JS bundle
both changed, so those go out with it.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

A handshake instead of six digits: the device you are holding confirms the login
itself. And the release the account deletion should always have been — asking to
be erased now erases everything.

- ＋ Added: **passkeys as a second factor.** Touch ID, Face ID, Windows Hello or
  a hardware key instead of typing the code. The operating system checks the
  fingerprint or the face **on the device** and hands the forum a signature; no
  biometric reaches the server, and none can.

  An addition, never a replacement, in three ways that all matter. A passkey can
  only be registered once the code is already on, because it lives in one
  machine's secure enclave and the recovery codes that come with the code are
  the way back from a lost device. The code field stays exactly where it was, so
  a browser without JavaScript or a device without a sensor reaches the same
  second step. And the button stays hidden until the browser confirms an
  authenticator exists, because one that cannot work is worse than none.

  Two things surprise people once and then never again, so they are in the help:
  a passkey belongs to the device it was made on — register each one you use —
  and it works for one address only, which is exactly why a fake site cannot
  harvest it.

- ✓ Fixed: **deleting an account now deletes what it could be signed in with.**
  It did not. Measured before the fix, a deleted member left behind an encrypted
  second-factor secret, ten hashed recovery codes, a trusted-device token and a
  passkey — thirteen rows for an account that no longer existed, plus any
  outstanding password-reset token.

  The five tables arrived one per release through the 8.4 line, each built and
  tested on its own, and none of those test runs asked what happens when the
  account goes. It matters most against the reason the data export already
  exists: this forum implements the GDPR's right of access as self-service, and
  leaving credentials behind after an erasure request does not sit beside that.

  The migration clears what has already accumulated. A fix that only applied
  from today would leave exactly the people who already exercised that right
  still on file.

- Δ Changed: **the API scope is decided by one test instead of two that could
  disagree.** `/api/v2` chooses JWT-only authentication, and the check for it was
  an unanchored match: any path merely *containing* the string picked it, so
  `/entries/view/1/api/v2` would have shown a signed-in member as logged out.
  Stricter rather than looser, so never an escalation — but the CSRF exemption
  beside it has always been an anchored prefix, and a loose half of a pair
  invites harmonising them in the wrong direction. Both now use the same test.

## [8.4.4] - 2026-08-07 "stammgast"

**One migration runs.** It adds `two_factor_trusted_devices`; there is no
`down()`. **The stylesheets changed** — Bota, Nova and Macnemo, all three — so
those have to go out with it. The JS bundles did not.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

A regular is somebody the house recognises on the way in. Turning on the second
factor in 8.4.2 quietly took "stay signed in" away, and this puts it back for
devices that have actually proved themselves.

- ✓ Fixed: **"stay signed in" works again with a second factor.** 8.4.2 shipped
  it broken in two ways at once, and the note in that release — that a
  remember-me cookie is refused outright for an enrolled account — described the
  second of them as if it were the whole design. It was half a design.

  The checkbox sits on the password form, but the cookie it asks for can only be
  minted a step later, once the code is in, and nothing carried the answer
  across: no remember-me cookie was ever written for an enrolled account. Even
  had one been, it was turned away at the door. The result was that switching on
  2FA meant signing in again and again — most visibly on a phone, where the
  browser drops sessions freely.

  The reasoning behind the refusal still holds: a remember-me cookie is
  stateless, validating against a username and a password hash, so a cookie made
  *before* an account enrolled cannot be told from a later one and no server can
  revoke either. What was missing was somewhere to write down which is which.

  `two_factor_trusted_devices` is that place. A row is written only after a
  second factor has actually been proved, and its token travels in a companion
  cookie; a remember-me cookie is honoured for an enrolled account only when a
  matching, unexpired row sits behind it. Cookies from before enrolment have no
  row and are still refused, so nothing that was closed has been reopened.

  Because the trust is a row now rather than a signature, it can be taken back.
  Signing out drops the device doing it and leaves the others alone; switching
  the second factor off, changing the password, or an administrator resetting it
  drops every one. The token is stored as a SHA-256, so reading the table yields
  nothing that could be put in a cookie, and the cookie itself carries the same
  flags as the remember-me cookie it travels with.

- ✓ Fixed: **code was unreadable inside an alert in every dark theme.** The base
  stylesheet gives `code` the page's text colour, which is near-white in a dark
  preset; Bootstrap's contextual alerts keep their light backgrounds there,
  deliberately. Neither is wrong alone — the base rule reaching inside the alert
  is. Measured, the two-factor recovery codes came out at about 1.2:1 on pale
  green, which is not "hard to read" but absent, and they are shown exactly once
  with no second chance. Inside an alert, code now takes the alert's own colour.

  The recovery codes also had a class with no styles behind it, and now read as
  something meant to be copied off a screen: monospace, tracked, with room
  between the lines.

- Δ Changed: **`league/commonmark` is updated to 2.9.0.** Six advisories were
  published against it an hour before this release was built — five denial of
  service, one bypassing the `AttributesExtension` filter on `href` and `src` —
  and the release refused to package over them.

  None of them is reachable from the forum: markdown is parsed only for the help
  pages and the guided tour, which are files shipped with the release, while
  postings go through Saito's own parser. So this is hygiene rather than an
  incident. It still blocks a release, because knowingly shipping a dependency
  an hour after it was declared vulnerable is not a thing to do.

## [8.4.2] - 2026-08-06 "zweitschlüssel"

**One migration runs.** It adds `two_factor_credentials` and
`two_factor_recovery_codes`; there is no `down()`. Neither the stylesheets nor
the JS bundles changed, so those can stay as they are.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

A second key to the same door. Members can now protect their account with a
code from an authenticator app on top of the password — off by default, and
entirely their own choice.

Nothing changes for anybody who does not switch it on.

- ＋ Added: **two-factor authentication (TOTP).** Set it up in your profile: the
  forum shows a QR code, your app produces a six-digit code, and only once you
  have proved you can produce one does the second factor go live. Ten single-use
  recovery codes come with it, shown once, for the day the phone is gone.

  From then on the login has two steps. The second one happens inside the login
  overlay rather than on a page of its own, because it is a second *step*, not a
  second destination.

  The part that matters and cannot be seen: **the password alone no longer
  signs anybody in.** It is verified, and then nothing happens — no identity, no
  session, and above all no "remember me" cookie, which is minted at exactly the
  moment 2FA has not reached yet. A remember-me cookie made *before* an account
  enrolled is refused outright: it validates against a username and a password
  hash, so no server can revoke it, and the only way to stop it walking past the
  second factor is to turn it away at the door.

  Secrets are encrypted at rest, recovery codes are hashed like passwords, and
  neither table is mass-assignable. Turning the second factor off, or minting
  fresh recovery codes, asks for the password again — a borrowed session should
  not be able to do either quietly.

  Lost the phone *and* the codes? An administrator can clear the second factor
  from the user list. It asks for the administrator's own password and is logged
  either way, successful or not: removing somebody's second factor is exactly
  the step an attacker who reached an admin session would take, so it leaves a
  trace that can be read afterwards.

- ✓ Fixed: **the whole-forum export was written world-readable.** It holds every
  member's e-mail address, and their IP addresses where the forum stores them,
  and the default umask made it `0644` — on a shared host, the membership list
  for any local account. It is `0600` in a `0700` directory now, and the command
  refuses to write rather than write unprotected.

- Δ Changed: **the nightly security job also checks that the security headers
  still arrive.** Advisories were the only thing it watched, but server
  configuration drifts too and far more quietly: one installation was serving
  every static file and every upload without `X-Content-Type-Options`, and
  another had no HSTS at all. Nothing fails when that happens — pages render,
  uploads load — so it took a scheduled check to notice. Three probes per
  installation, because nginx applies headers per location and locations drift
  apart independently.

- Δ Changed: a command-injection advisory in `squizlabs/php_codesniffer` is
  patched (dev-only, never runs on a server), and the one third-party GitHub
  Action is pinned to a commit rather than a tag its owner could repoint.

## [8.4.1] - 2026-08-06 "hausordnung"

**One migration runs.** It adds `users.tos_accepted_version`; there is no
`down()`. The stylesheets changed too (Nova and Macnemo); the JS bundles did
not, so they can stay as they are.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

A point release by number and a house-rules release by content: the forum now
ships terms of service, links them where they belong, and can ask members to
agree again when they change. Named for what § 2 of those terms calls the
operator's Hausrecht.

Nothing here changes what a member sees day to day unless the operator raises
`tos_version` — the new machinery is dormant until then, deliberately, so
upgrading does not greet everybody with a consent form.

- ＋ Added: **terms of service that actually exist.** `/pages/tos` renders them,
  the registration form and the footer link to them, and the retired
  `/pages/de/tos` is gone. An installation that writes its own puts trusted HTML
  in `Saito.tos`; **left empty, a German default ships** with the forum's name
  filled in and the operator taken from the imprint, so a fresh forum is not
  left with a dead link. `docs/terms-of-service-template.md` is the same text to
  start from.

- ＋ Added: **members are asked to agree again when the terms change.** The terms
  carry a version (`tos_version`, in Admin → Settings) and each account records
  the version it agreed to. Raise the setting after a material change and every
  account behind it meets the new terms and an accept button instead of the
  forum — § 7 of the shipped terms, implemented.

  Sessions are deliberately *not* invalidated: the check runs on every request,
  so the next thing a member does already lands there, and forcing a re-login
  would cost everyone their session to no purpose. Four ways out stay open, or
  somebody who does not want to agree would be trapped in a forum they cannot
  leave: the terms themselves, the imprint and privacy policy, logging out, and
  the GDPR data export.

- ＋ Added: **the whole forum as a file.** `bin/cake export_forum` streams
  members, categories, postings and upload metadata as JSON Lines — for a move,
  or a backup you can still read in ten years. It pages every table and yields a
  record at a time, so the reference forum's 680,000 postings cost a few
  megabytes of memory rather than the 66 the text weighs. Credentials and the
  upload files stay out on purpose; both belong to the SQL dump and the file
  backup that this sits beside.

- ✓ Fixed: **read-only fields were unreadable on the dark theme.** The personal
  RSS-feed addresses in the settings sat on Bootstrap's fixed light-grey
  `[readonly]` fill under light text. They follow the theme's own colours now.

- ✓ Fixed: **the forum export was written world-readable.** It holds every
  member's e-mail address, and their IP addresses where the forum stores them,
  and the default umask made it `0644` — on a shared host, the membership list
  for any local account. It is `0600` in a `0700` directory now, and the command
  refuses to write at all if it cannot set that. Found in the security audit of
  this release; it was never reachable over the web and never carried
  credentials, so this was local exposure, not a remote hole.

- Δ Changed: **the release build lost grunt.** The chain is `package.json`
  scripts now — `yarn build:release` runs Sass, PostCSS, the purge, the three
  Vite bundles and the asset copy. grunt and its five packages leave the tree,
  and with them the last `minimatch@3`, so the build-time advisory pin goes too.
  The gate was byte-identical output: the seven stylesheets, three bundles and
  twenty copied assets all `cmp`-equal to what `grunt release` produced.

- Δ Changed: **a LICENSE file at last.** `composer.json` had said MIT for years
  with no file to carry the notice. It also names the third-party code a
  distribution actually contains — Bootstrap, Alpine, htmx, Font Awesome, and
  the OFL-licensed fonts.

- − Removed: **823 dead translation entries**, already marked obsolete by
  gettext and never read by CakePHP — its parser skips `#~` lines entirely.
  Verified rather than assumed: the catalogue was dumped through that parser
  before and after and the two are byte-identical. `default.po` drops from 76K
  to 52K (de) and 56K to 48K (en).

- Δ Changed: `todo.md` is retired. What it held is now issues #65–#77, each
  carrying its measurements; the file is a pointer so nobody looks for the list
  where it used to be.

## [8.4.0] - 2026-08-05 "schlosswechsel"

**One migration runs.** It adds `password_reset_tokens`; there is no `down()`.
The JS bundle and the stylesheets changed too, so this is a full deploy, not a
code-only one.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

Named for what the release does to a lock: a member who resets their password
sets a new one, and every old key stops turning. Two long-standing gaps close
with it — there was no way back into a forgotten account without an
administrator, and a password reset did not end the sessions it was meant to
end.

- ＋ Added: **self-service password reset (#63).** Until now the only way back in
  for a member who had lost their password was an administrator setting a new one
  by hand. "Forgot password" now sends a one-time link: the member sets a new
  password themselves and is taken to the login. The link carries a token whose
  SHA-256 is all that is stored — a read of the new `password_reset_tokens` table
  hands an attacker nothing usable — and it is single-use, expires after 60
  minutes, and a fresh request clears the member's earlier links first. The
  request form answers the same whether or not the address is on file, so it
  cannot be used to tell which addresses are registered, and it is throttled per
  client. It is an island page (htmx), so it works without the SPA and without
  JavaScript.

- ＋ Added: **see where an upload is embedded before deleting it (#64).** On the
  upload-management grid each file now has a search control that lists the
  postings it appears in, and deleting a file that is still embedded asks for
  confirmation first instead of silently leaving a dangling `[img]` in old
  postings. The lookup matches the upload's filename in the posting text, scoped
  to the owner and riding the existing `user_id` index; the popover is a native
  `<details>` toggle, so it stays within the content-security policy with no new
  script.

- Δ Changed: **a password change now logs out the account's other sessions.** A
  session is stamped at login with a fingerprint of the account password and
  dropped on its next request once that fingerprint no longer matches — so a
  reset (or a change, or an administrator setting a new password) actually ends
  the other devices the account was signed in on, which is the whole point of
  resetting a password you fear was captured. Stateful logins only: a feed token
  or JWT re-presents its credential each request and holds no session to
  fingerprint. Sessions that predate the mechanism adopt the current fingerprint
  rather than being kicked, so upgrading does not log everyone out at once.

- ✓ Fixed: **a posting opened inline no longer repeats its own subject.** In the
  thread tree, clicking a subject line opened the posting right beneath it — and
  the posting printed the same subject again as its heading, one row down. The
  heading is dropped for the inline view (the line above already carries it) and
  kept on the posting's own page, where it is the page title. A forum member had
  asked for exactly this.

- ✓ Fixed: **the thread-collapse arrow closes inline-opened postings again.** On
  a phone, once a posting in a multi-reply thread had been opened inline, the
  collapse arrow could no longer fold the thread shut. Collapsing now closes any
  inline postings it holds first.

- Δ Changed: internal only — the upload-delete confirm branch returns a proper
  `Response` (it returned `null`, which failed static analysis), and the
  pixel-diff test harness matches a `<script>` end tag by shape rather than by
  exact spelling. Neither reaches a reader.

## [8.3.17] - 2026-08-04 "blindgänger"

No migration. Only the stylesheet changed; the JS bundle and the PHP are
untouched, so this one is a CSS deploy.

Named for what most of it turned out to be: findings that looked live and were
inert. Eight dependency alerts that never reach a browser, five reported XSS
that cannot fire. The one real defect was on a phone.

- ✓ Fixed: **the subject counter no longer collides with the subject.** On an
  iPhone the remaining-characters count and a long subject rendered on top of
  each other. iOS Safari scrolls an overflowing text input's content *under* its
  own right padding, so the padding that held the number clear did nothing there
  — Blink and Gecko stop the text short, which is why it only showed on the
  phone. There is no position inside the field that survives both (masking the
  number with an opaque chip just moved the problem onto the text at the caret),
  so the number sits beside the field now: nothing can overlap it and it can
  hide nothing. The field gives up about 3rem of width for it.

- Δ Changed: **the build tooling lost `grunt-contrib-watch`.** Eight Dependabot
  advisories — a vulnerable `lodash`, `async`, `minimatch` and `brace-expansion`
  — all sat in `yarn.lock`, all build-time only, none of them in the bundle a
  reader downloads. Three of the four came in solely through that dev-only
  plugin, which `grunt release` does not use and `yarn dev`/`yarn css` already
  cover, so it was removed rather than pinned; `globule` hard-pins its
  `minimatch` and a resolution could not have lifted it anyway. The fourth,
  `brace-expansion`, is pinned scoped so the eslint line is untouched. Nothing
  about the release build changes: the same chain produces byte-identical
  assets.

- Δ Changed: **the CodeQL findings were triaged, not swept.** Five of six were
  false positives and are dismissed with reasons — an inert `DOMParser` parse,
  two stylesheet `href`s from server-rendered theme paths, a same-origin delete
  navigation, and the `[spoiler]` reveal, whose content is escaped *twice*
  server-side so the `innerHTML` lands on entity text rather than an element.
  That last one is now pinned by a test, and its comment says why it must not be
  "fixed" into a break. The sixth was real but dev-only: the pixel-diff harness
  stripped `<script>` case-sensitively.

## [8.3.16] - 2026-08-04 "türsteher"

No migration. Both the stylesheets and the JS bundle changed, so deploy them
with the rest.

Named for what these have in common: who gets let in and who gets turned away.

- ✓ Fixed: **`[email]` no longer runs on `.html()`, and needs no jQuery.** The
  tag emitted an inline jQuery script that reassembled the address with
  `.html()`. On 8.3 that was already visibly broken — no jQuery since 8.1.0, so
  the script threw and the link rendered empty — and on an older install with
  jQuery and no strict CSP it was a stored-XSS path, because `.html()` turns the
  decoded attribute back into live markup. The reassembly is in the island
  bundle now and uses `textContent`; the scheme is hard-coded to `mailto:` so a
  posting cannot smuggle `javascript:`, and the helper escapes its own
  attributes rather than trusting its caller. Verified in a real browser: a
  hostile address renders as text, nothing executes.

- ＋ Added: **posting is rate-limited.** Login, registration and the contact
  form were throttled; posting was the one write path left open, and the
  cheapest to abuse — one confirmed account, a posting per request, each write
  dropping the thread cache. Ten per five minutes per member, keyed on the
  member id (members share connections; a script does not need to), with
  moderators and above exempt through a new `saito.core.posting.unthrottled`
  permission.

- Δ Changed: **the bot filter must not challenge two htmx endpoints.** The
  island polls `/entries/htmx-new-count` and `/entries/htmx-widgets` in the
  background; a proof-of-work challenge cannot be answered from an XHR, so the
  challenge page came back and htmx swapped it into the running forum. Documented
  in `docs/configuration.md` — the `ALLOW` rule and the `SameSite=Lax` cookie
  setting — because it meets any Saito 8 behind Anubis, Cloudflare or similar.

- Δ Changed: Vite 8.1.5 → 8.2.0 and typeface-fenix 0.0.72 → 1.1.13. The font is
  byte-identical, the version jump is packaging only.

## [8.3.15] - 2026-08-03 "viertes byte"

**Two migrations run.** No `down()` on either — see below.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

Named for the byte utf8mb4 has and utf8mb3 has not. Both fixes are about it: the
character set that could not be mixed inside one index, and the emoji the
database refused.

- ✓ Fixed: **the upgrade could stop at the eighth of nine migrations.**
  `AlignSchemaWithGrownInstalls` widened `entries.text` with a statement naming
  `utf8mb4`. On a table still in utf8mb3 that converts one column and leaves its
  neighbours behind — and `entries` carries a FULLTEXT index over `subject`,
  `name` and `text`, which may not span two character sets:

      ERROR 1283: Column 'text' cannot be part of FULLTEXT index

  The migration aborted and the four after it never ran. It converts the whole
  table first now, guarded, so an installation already on utf8mb4 pays no
  rebuild. **This only ever affected forums old enough to still have `entries`
  in utf8mb3** — which is exactly why it was not found sooner.

- ✓ Fixed: **the utf8mb4 conversion was never finished**, and on a grown
  installation that shows as a refusal rather than a nuisance. Ten tables stayed
  three-byte, so a bookmark note with an emoji, a category name, a block reason:

      ERROR 1366: Incorrect string value: '\xF0\x9F\x91\x8D ...'

  under MySQL's default strict mode, and truncated without complaint outside it.
  `ConvertRemainingTablesToUtf8mb4` converts what is left, table by table,
  skipping what is done.

  Both faults were invisible to every check this project runs, for one reason:
  the migrations had only ever been verified against databases the migrations
  themselves built. That proves they are self-consistent and nothing else.

- ＋ Added: **`Saito.webhooks.user.legacyContactFields`, deprecated in the same
  release it appears in.** It puts the member's email address and IP back into
  the webhook payload. Off by default, and it should stay off — it exists so a
  forum that already runs a receiver written against an older integration can
  upgrade Saito now and rewrite that receiver afterwards, instead of having to
  do both at once. **Plan on it being removed.** Every send logs a warning while
  it is on.

  It does not loosen the forum's own rules: the IP follows `store_ip` and
  `store_ip_anonymized`, so an installation that has decided not to keep
  addresses does not start posting them, and one that keeps them shortened sends
  them shortened. Sending is more exposing than storing, so the stricter setting
  wins.

- Δ Changed: `docs/upgrade.md` said seven migrations and measured "no
  difference" against a database it had built itself. It says ten now, and what
  the two faults above showed about the difference between self-consistency and
  correctness.

## [8.3.14] - 2026-08-03 "ballast"

No migration. Both the stylesheets and the JS bundle changed, so deploy them
with the rest.

Named for what goes overboard: the shipped CSS loses two thirds of its weight,
and the header bar can fold itself away again.

- Δ Changed: **the release now ships only the CSS the forum uses** — 810 KB down
  to 271, `Nova/theme.css` alone from 132 KB to 41. Bootstrap stays a dependency
  and nothing derived from it lives in this repository; what changed is that
  `grunt release` drops every rule no template or PHP class refers to. The
  compiled themes carry about 1600 classes and the forum uses roughly 150.

  This step is lossy and fails silently, so it comes with `dev/pixel-diff.sh`,
  which renders real pages against both stylesheets and counts differing pixels.
  Twelve comparisons at zero: three themes in both presets, three pages, three
  viewport widths. Three blind spots turned up on the way and none was
  guessable by reading — markup is built in `src/` as well as `templates/`; icon
  names are composed (`$iconLabel('sign-in')` never appears as `fa-sign-in`
  anywhere); and some classes exist only through `@extend`.

  **For installations with their own theme:** rebuild it against this release
  before deploying, and run the pixel diff. A rule your theme relies on that no
  Saito template mentions will be removed.

- ＋ Added: **the header bar folds away again.** The classic Bota theme could
  collapse it to a thin strip and remembered the choice; the island frontend
  never carried it over, and for three releases the CSS for it sat in the
  stylesheet with no markup to match. The state is applied before the first
  paint, so no page load shows the header folding itself, and it keeps the old
  `headerClosed` key — an installation upgrading from the classic theme finds
  its readers' preference intact rather than reset.

- ＋ Added: **`Webhooks`, a plugin that tells an outside system about new
  members.** Registration, activation and account deletion post a small signed
  JSON body to an address the installation configures, under
  `Saito.webhooks.user`. Meant for a companion app or a moderation queue; an
  empty `url` switches the whole thing off, listener included.

  The body carries id, username and a UTC timestamp and deliberately nothing
  else — no email address, no IP. Delivery is best-effort: the call happens
  inside the request that registered the member, so a dead endpoint must not
  fail their registration, and a lost event is the accepted cost. Something that
  must not miss events should poll the API.

- Δ Changed: **the stylesheets compile with 32 deprecation warnings instead of
  148.** `/` for division, `map-get` and `darken()`/`lighten()` are gone; the
  dependencies are silenced properly now, which needed a load path rather than
  the flag alone. Every conversion was output-preserving, so the test was `cmp`
  on the compiled CSS rather than a pixel diff — all seven byte-identical
  through the full release chain. `--fatal-deprecation` keeps the three cleared
  categories from coming back. `@import` is what remains; the official migrator
  refuses this codebase, and its deadline is a compiler version that does not
  exist yet.

## [8.3.13] - 2026-08-02 "funkstille"

No migration. The JS bundle changed, so deploy it with the rest.

Named for what three of these have in common: the forum stops sending something
it should not have been sending.

- ✓ Fixed: **the registration form no longer says whether an address already has
  an account.** "This address is taken" is what a form has to tell the person
  filling it in — and at the same time an oracle for asking, of anybody, whether
  they are a member here. A forum's membership is not public information. The
  answer now goes to the address itself, where only its owner can read it, and
  the form says exactly the same thing either way. The throttle from 8.3.2
  capped how often the question could be asked; this stops it being answered.

  The cost, deliberately accepted: somebody who mistypes their address into one
  that belongs to another member sees "check your mail" and never gets a mail,
  while that member gets one they did not ask for. That mail is written for
  exactly that reader.

- ✓ Fixed: **a refused `/metrics` scrape no longer writes to `error.log`.** The
  guard doing its job is not an application error. It cost nothing while the
  token was right and everything when it was not: a rotated token at a
  60-second interval would write 1,440 entries a day, making the log unreadable
  exactly when somebody needed to read it.

- ＋ Added: **the editor understands PeerTube addresses.** Paste the `/w/…` link
  from the address bar and it becomes the player, the way YouTube already did.
  Recognised by the path rather than the host, because PeerTube is federated and
  there is no domain list to match against. The forum's own
  `video_domains_allowed` still has to permit the instance.

- Δ Changed: **`grunt-dart-sass` is gone**, replaced by the sass CLI through the
  `grunt-shell` task the JS bundle already uses. The package was last released
  in May 2022 and drives the legacy JS API that Dart Sass 2 removes — in the
  path that builds the themes for every release, so the day that lands it would
  have broken the release pipeline rather than somebody's local work. All six
  stylesheets come out byte-identical.

## [8.3.12] - 2026-08-02 "logbuch"

**One migration runs.** It removes two settings rows and there is no way back —
`down()` is empty on purpose.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

Named for what the three new pieces have in common: none of them invents
anything. The rank ladder had been configured in the database for eleven years,
the counters were already cached for the page footer, and the personal data was
always there — all that was missing was a way to read any of it.

- ＋ Added: **a member can download everything the forum holds about them.**
  GDPR Art. 15 and 20; the link sits beside "change password" in their own
  settings. The action takes no parameter — the account comes from the session,
  so there is nothing to substitute and it cannot be pointed at anyone else, not
  even by an administrator. Credentials are absent, and so is anything about
  other people: who ignores this member, which moderator imposed a block, and
  the text of postings they only edited. Streamed through a spilling buffer,
  because assembling it in memory peaked at 174 MB for the busiest account
  against a 128M limit.

- ＋ Added: **`/metrics` for Prometheus**, guarded by `SAITO_METRICS_TOKEN`.
  Empty token means the address answers 404, so this stays absent on any
  installation that has not asked for it. Counters come from the cache the front
  page already fills: 315 ms cold, 2–7 ms warm. `saito_db_version_matches` is
  the one worth an alert — 0 means every request lands on the updater, which is
  a forum that is down while nothing logs an error.

- ＋ Added: **the rank a member has written their way to**, back after eleven
  years, in the profile. A threshold is now the count a rank is *earned* at
  rather than an upper bound, so somebody who has written nothing has no rank
  instead of the bottom one. Both settings are editable in the admin area at
  last — they never were.

- Δ Changed: **the subject field spans the form again.** It was capped to stop
  it promising room the forum would not accept; the character countdown says
  that in words now, so the cap only took away space.

- − Removed: **`api_enabled` and `api_crossdomain`.** Nothing reads either. The
  first is the uncomfortable one: an administrator setting it to 0 had every
  reason to believe the API was off, and it never was. The API itself is
  untouched and working — its routes come from the ImageUploader and Bookmarks
  plugins.

- ＋ Added: `dev/audit-probes.sh`, the comparisons that found the residue above,
  and a CI check that fails when a translation key is used without being
  declared — the defect that put a raw `user.block.t` in front of moderators.

- ✓ Fixed: the release tarball now carries the operator documentation. It
  excluded all of `docs/*.md`, so the shipped README linked to a
  `configuration.md` that was not in the package.

## [8.3.11] - 2026-08-02 "time chaos"

No migration and no data touched. **Times from the other half of the year move
by an hour** — that is the correction below, not a new fault.

Also worth checking on your own install before you update: `APP_DEFAULT_TIMEZONE`
**must be `UTC`**. It is not the forum's timezone — that is a setting in the
admin area. See [configuration.md](docs/configuration.md).

- ✓ Fixed: **postings from the other half of the year were shown with the wrong
  hour.** `TimeHHelper` added the timezone offset to the epoch and then formatted
  with `date()`, which runs under PHP's UTC — two mistakes that cancel, which is
  why the text looked right for years. But the offset was computed once from
  *now* and applied to every posting on the page, so in summer every winter
  posting was an hour late and in winter every summer one an hour early. Measured
  on the reference install: a posting stored at 16:48 UTC in January was served
  as 18:48 instead of 17:48. The helper renders the instant in the forum's
  timezone now, so PHP looks up the offset that applied *at that instant*.

- ✓ Fixed: **the `datetime` attribute carried the wrong offset** — the shifted
  value labelled `+00:00`, so feed readers, search engines and the browser's own
  tooltip were out by the local offset. The RSS `pubDate` was always correct.

- ✓ Fixed: **"today" began at midnight UTC.** `mktime()` follows PHP's timezone,
  so between local midnight and the UTC one a posting was filed under the
  previous day — and that is this forum's busiest hour.

- ＋ Added: **`docs/configuration.md`**, every environment variable an operator
  can set, with defaults and what each one does. Twelve of the thirty-three were
  documented nowhere, among them the whole SMTP block and `SAITO_TRUST_PROXY`,
  which lets clients forge their own IP if it is switched on without a proxy in
  front.

- Δ Changed: the toolchain moved to **Node 24** and **PHPUnit 13**, and
  `cakephp/bake` is gone. None of it reaches a server — no install has Node, and
  the release tarball is built `--no-dev`. Developers need Node >= 22.11 now;
  `.nvmrc` names the version.

## [8.3.10] - 2026-08-01 "hybi"

No migration. The stylesheet and the island bundle both changed; the parser
markup changed with them, so the posting cache has to go too.

```bash
php bin/cake.php cache clear_all
```

- Δ Changed: **a covered picture covers itself again on the next click.** The
  cover was one-way in practice: revealing worked, putting it back meant aiming
  at a small tab in the corner, and a click that missed hit the picture — which
  is wrapped in a link to the full-size file, so it opened full-size in a new
  tab. Now a click anywhere on a revealed picture toggles it: blurred, clear,
  blurred, clear. The trade, deliberately, is that for a marked picture the
  full-size view is no longer one click away. Video, audio and files keep the
  corner tab, because their controls — play, seek, download — sit inside the
  media and a cover over them would take away what the reveal was for.

- ✓ Fixed: **a rejected reply handed the form the subject it had computed**
  rather than the one that was typed, which turns the pale placeholder into a
  value that has to be deleted. Not reachable with today's validation rules, and
  a trap for whoever adds one for the posting text.

## [8.3.9] - 2026-08-01 "handbreit"

Two things that only show up when the forum is used rather than built. No
migration; the stylesheet and the island bundle both changed, so deploy them
together.

- ✓ Fixed: **the submit button of the contact form could not be reached.** Its
  message field is named `text`, the same as the posting editor's, so the
  editor's auto-grow applied to it and the box expanded to 80% of the window
  height. Inside a fixed overlay with no scroll of its own that pushed "send a
  copy to me" and the submit button past the bottom edge of the screen, and
  nothing could scroll to them — the page behind was not what was overflowing.
  `.island-modal` scrolls now, which makes any over-tall dialog reachable rather
  than just this one, and inside a modal the field grows to 40% instead of 80%
  so the form fits without scrolling at all.

- ✓ Fixed: **a block-duration select labelled `user.block.t`.** The key was
  invented when blocking moved to the profile page and never added to a
  catalogue, so the raw key was what moderators saw. Renamed to
  `user.block.duration` — `.t` means "title" everywhere else in Saito and this
  is a field label — and translated in de and en. All 128 dotted keys used in
  templates were checked against the catalogues; this was the only one missing.

## [8.3.8] - 2026-08-01 "flattrfrei"

**Two migrations run.** Both are guarded: they check what is there before
changing it, because the columns they touch are older than these migrations and
exist only on installations that have been running for years.

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

- − Removed: **the last of Flattr.** `entries.flattr` and three settings rows
  configuring a micropayment service that no longer exists, read by no code
  since the Saito 5 rewrite. On a grown forum the column holds marks set between
  2011 and 2018 — 16,104 of them on the macnemo installation. If you want them,
  take them out of your backup first; there is no way back afterwards. This is
  the twin of `entries.nsfw`, which sat in the same state and was answered the
  other way in 8.3.2, because that marking still meant something a reader wants.

- ✓ Fixed: **a fresh installation could not hold what a grown one does.**
  `entries.text`, `drafts.text` and `users.profile` were `TEXT` here and
  `MEDIUMTEXT` on an established forum — 64 KB against 16 MB. The longest
  posting on the macnemo installation is 294,739 characters, so its dump could
  not have been restored into an installation built from these migrations, and
  outside MySQL's strict mode it would have been cut rather than refused.

- ✓ Fixed: **the database now holds the promise the application makes about
  usernames.** `UsersTable` validates them as unique, case-insensitively. A
  fresh installation backed that with a UNIQUE index; a grown one had a plain,
  non-unique one. Nothing had gone wrong — 821 members, 821 distinct names — but
  two simultaneous registrations could both have passed validation and both been
  written.

- − Removed: **`cakephp/bake` as a development dependency.** Its base class
  declares `protected Arguments $args` where `cakephp/migrations` declares the
  same property nullable, and PHP refuses that outright: `bin/cake migrations`
  ended in a fatal error on any installation with development dependencies
  installed — the very command the upgrade documentation tells operators to run.
  Nothing in this project bakes, and no other package asked for it.

- Δ Changed: **`upgrade.md` says seven migrations, and says why going straight
  from 5.7 works.** That claim used to be an assertion; it is now a measurement.
  A database was migrated to the 5.7 level, taken to the current release in one
  run, and compared column by column with a fresh installation: 124 columns on
  both sides, no difference.

## [8.3.7] - 2026-08-01 "daumenkino"

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.6...8.3.7)

No migration runs. **Clear the thumbnail cache after replacing the files** —
`bin/cake.php cache clear uploadsThumbnails`, or delete `tmp/cache/uploads/`.
The cache holds the old full-size images, and without clearing it nothing
changes for anything that has already been looked at once. The first pass
afterwards is slower while every thumbnail is computed again; after that it is
faster for good.

- ✓ Fixed: **thumbnails are thumbnails now.** A `size > 150000` threshold meant
  anything under 150 KB was served at its original resolution and called a
  preview: measured on the running forum, 2961 of 5542 uploads fell under it,
  one of them sending 121,874 bytes for a tile drawn at 84 pixels, and a page of
  sixty came to about 8 MB. The threshold was never a decision about what a
  thumbnail should be — it was an assumption from a time when uploads were
  smaller. The largest file in that archive is 57 MB.

  Every image is scaled to 300×300 now. The condition asks whether the upload is
  an image rather than whether it is large, which also closes something the
  threshold had been hiding by accident: an upload can be a video, an audio file
  or plain text, and those never reached the image library only because they
  were usually small. A file the library cannot read is served as it lies rather
  than leaving a hole in the grid.

- Δ Changed: **three build-tooling dependencies moved up**, Dependabot's first
  three pull requests: `websocket-driver` 0.7.3 → 0.7.5, `qs` 6.9.1 → 6.15.3,
  `braces` 3.0.2 → 3.0.3. All transitive — `package.json` is untouched, only the
  lock file moved. The tree's one critical advisory is gone with them; what
  remains is build tooling that never reaches a browser or a server.

## [8.3.6] - 2026-08-01 "waffel"

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.5...8.3.6)

No migration runs. Replace the files and clear the cache.

Named after a member of the forum it was cut for — the one whose thousand-image
archive made the case for the upload changes in it.

- Δ Changed: **the upload archive loads by itself, sixty at a time.** It came in
  pages of twenty with a "load more" button — a member with 493 uploads pressed
  it twenty-four times to see their own archive, and then asked whether it was
  loading everything at all. It was; the log shows pages 2 to 25, all answered.
  That question is the bug report. Sixty per page turns 25 pages into 9, the
  control now fires when it scrolls into view, and the count is stated once at
  the top so "that is all of them" can be told from "it stopped".

  `intersect once` rather than htmx's `revealed`: the control sits in a grid
  that scrolls on its own, and `revealed` measures against the window — it would
  have fired for a control the reader cannot see and pulled the whole archive in
  one burst. The button stays a button for keyboard use.

- Δ Changed: **the upload archive is the last section of a profile.** A section
  that grows as it is scrolled pushes everything under it out of reach; the RSS
  links used to sit below it.

- − Removed: **three permissions that were declared and never checked.**
  `saito.core.user.email.set`, `…name.set` and `…lock.view` were live in 5.7.1
  and were left behind when the SPA went. Nothing ran unguarded because of them
  — there is no path that changes another member's address or name. The harm was
  to the reader: `config/permissions.php` is where one looks up what an
  administrator may do, and it promised three things that were not there. More
  so since 8.3.3 gave `saito.core.user.password.set` its feature back, leaving
  four siblings of which one worked. Every permission that remains is checked
  somewhere; counted, not assumed.

- Δ Changed: **macnemo's guided tour caught up with the forum.** It described a
  profile whose sections are in a different order now, and said nothing at all
  about marking a posting NSFW.

## [8.3.5] - 2026-07-31

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.4...8.3.5)

**Upgrade from 8.3.2, 8.3.3 or 8.3.4 without waiting.** No migration runs, but
those releases wrote replies without their thread id — see below for how to
find and repair them.

- ✓ Fixed: **replies were saved without their thread.** The mass-assignment
  guard added in 8.3.2 denied `tid` on the posting entity — correctly, so a
  request cannot move a posting between threads — but `createEntry()` needs to
  set it, because it arrives from the parent. It was dropped silently instead.
  A reply was written to the database with `tid` 0: present, and absent from its
  own thread, while the island answered its author with an error. The thread's
  `last_answer` was not bumped either, so it did not rise in the list.

  `tid` is named explicitly now, next to `user_id` and for the same reason: by
  the time `createEntry()` sees them both are set by the application, `tid` from
  the parent in `PostingComponent::prepareChildPosting()`, which overwrites
  anything a request may have sent. A root posting still gets its own id in
  `afterSave()`.

  Four replies on the macnemo installation were affected and have been repaired.
  If you ran 8.3.2 to 8.3.4, look for them:

  ```sql
  SELECT id, pid, tid FROM entries WHERE pid > 0 AND (tid = 0 OR tid IS NULL);
  UPDATE entries k JOIN entries e ON e.id = k.pid SET k.tid = e.tid
    WHERE k.pid > 0 AND (k.tid = 0 OR k.tid IS NULL);
  ```

  Check `last_answer` on the roots afterwards, and note that a reply whose
  parent has since been deleted has no thread left to return to — the delete
  went by `tid` and could not find it.

## [8.3.4] - 2026-07-31

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.3...8.3.4)

No migration runs. A stylesheet change — replace the files and clear the cache.

- Δ Changed: **macnemo's postings read a little wider.** The text column was
  672px inside 1800px of available content — typographically sound and, on a
  wide screen, looking as though the page were not using its room. Its theme now
  sets `$readingWidth: 50em`, about 95 characters a line against 80. Only the
  macnemo theme: Bota and Nova keep 42em, and macfix keeps the 56em it needs to
  match the forum it is imitating.

## [8.3.3] - 2026-07-31

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.2...8.3.3)

No migration runs. Replace the files, clear the cache, done — coming from
8.3.1 or older, take the 8.3.2 notes with you: one migration runs there.

- Δ Changed: **the NSFW marking moved into the editor toolbar, and works on
  replies.** It was a tick box under the text field of the new-thread form only
  — where nobody found it, and where an answer carrying the picture had no way
  to say so at all. It is now a toggle beside bold and uploads, in every editor:
  starting a thread, answering one, and editing. Nothing is inherited; a reply
  is marked on its own merits or not at all. A checkbox and a `:checked` rule,
  so no JavaScript and nothing for the content-security policy to object to.

- − Removed: **the NSFW tick box in the upload overlay.** One place for the
  marking instead of two, and the toolbar is the one people look at. The parser
  still *reads* `nsfw=1` on a tag — postings written while the tick box existed
  keep their cover, and hand-editing the markup is still the finer instrument
  when only one picture out of several needs covering.

## [8.3.2] - 2026-07-31

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.1...8.3.2)

### Upgrading

**One migration runs**, and it needs the command line:

```bash
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear
```

It adds `entries.nsfw` — and only where it is missing. The column predates
these migrations by a decade, so a forum that has been running since Saito 4
already has it and the migration steps aside. The schema-cache clear is what
makes the new column visible to the ORM; skip it and the badge simply never
appears.

Coming from 8.2.9 or older, take the 8.3.1 notes with you: three more
migrations run there.

- ✓ Fixed: **the registration form has a budget now.** It sends a mail to
  whatever address is typed into it, and it was the only unauthenticated form
  without a per-client limit — the honeypot and the five-second minimum are bot
  defences, and both are satisfied by waiting. Five attempts an hour per client,
  the same shape the contact form already used, counted on the way in so a
  rejected attempt still costs.

- ✓ Fixed: **a line break in a contact-form subject could add a mail header.**
  CakePHP passes a plain-ASCII header value through unchanged, so a subject of
  `Hallo\r\nBcc: …` came out of `Message::getHeadersString()` as two header
  lines — the second a real `Bcc`, which would have delivered the message to
  whoever the sender named, from the forum's own domain and with its SPF and
  DKIM behind it. Nothing suggests it was ever used. Every mail the forum sends
  now passes its subject through `SaitoEmailComponent::sanitizeHeaderValue()`,
  so a caller added later cannot forget it, and the contact form rejects the
  break outright rather than silently altering what someone typed.

- Δ Changed: **postings decide for themselves what may be filled from an
  array.** `Entry` had no `$_accessible`, so every column was mass-assignable;
  nothing was exploitable, because all three call sites build their array field
  by field, but that was a convention held up by three call sites rather than a
  property of the entity. Authorship (`user_id`), moderation state (`locked`,
  `fixed`), `tid`, `views`, `ip` and the two Saito 5 residue columns are denied
  now and named only where they are genuinely set — pinning and locking through
  a new `EntriesTable::setPostingState()`, which is reachable only from the
  action that has already checked `saito.core.posting.pinAndLock`.

- ✓ Fixed: **a posting's JSON payload is escaped into its attribute.** The
  `data-entry` attribute is delimited by single quotes and `json_encode()`
  emits an apostrophe unescaped. Safe while the array held only numbers and
  dates; the day a subject or username joined it, the first apostrophe would
  have ended the attribute.

- ＋ Added: **the NSFW badge is back, and it now covers as well as warns.**
  Saito 4 marked a posting not-safe-for-work with a red badge; the feature was
  lost in the Saito 5 rewrite while the column and its data stayed behind — 1928
  postings on the macnemo install are still marked, and have been showing plainly
  for years. Ticking the box on a new thread brings back the badge *and* covers
  every image, video and file in that posting, so the old markings mean something
  again without anyone's text being rewritten. A reply does not inherit it, which
  is the call Saito 4 made too.

  A migration adds the column where it is missing — it predates these migrations
  and exists only on grown installations, so it is added guardedly rather than
  blindly.

- ＋ Added: **an admin can set a member's password again.** It went with the SPA
  and left its permission behind, declared and unused. Since the forum has no
  self-service reset — by design, there is no token to steal — this was the only
  way back in for somebody who has forgotten theirs, and there was nobody who
  could help them. The member's current password is not asked for, which is the
  point; the acting admin re-enters their own, the same as granting a role,
  because setting someone's password outlives the session that did it.

## [8.3.1] - 2026-07-31

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.0...8.3.1)

### Upgrading — which path are you on?

**From 8.3.0:** no migration runs. Replace the files, clear the cache, done.

**From 8.2.9 or older:** you are also taking 8.3.0 with you, and that release
carries three migrations. Run them, and do not skip the schema-cache clear —
without it every page answers 500, because the cached table description still
lists a column the migration dropped.

```bash
php bin/cake.php migrations status     # what is pending
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear    # not optional
```

Then set `db_version` in the `settings` table to `8.3.1`, or the forum routes
every request to the updater.

- ✓ Fixed: **a posting's subject was louder than the theme's own scale.** Bota
  sets `.postingBody-heading` to 1.45em and Nova's modern layer sets `h2` to
  1.25em — a class outranks an element selector, so the scale never reached the
  one heading it was most visible on. Measured on the running forum: 23.2px
  where the scale asks for 20, beside 16px body text and the same subject at
  16px in the thread list above it. Nova now names the heading; Bota, the
  classic theme, is unchanged.

- ✓ Fixed: **the font-size buttons say which size they are.** Four buttons all
  reading "A" told you they were sizes but not which, and nothing marked the
  normal one. The percentage is now in `title` (on hover) and in `aria-label`,
  which a screen reader announces instead of four identical letters.

- ＋ Added: **media can be inserted covered.** A tick box beside *Insert
  selection* in the upload overlay marks what is being inserted as
  not-safe-for-work; in the posting it then arrives blurred under a note, and a
  click uncovers it — another puts it back. Video keeps its play button out of
  reach while covered. There is no JavaScript behind it and no schema change:
  the marker rides in the tag (`[img src=upload nsfw=1]…[/img]`, likewise
  `[video]`, `[audio]`, `[file]`) and the cover is a checkbox with a `:checked`
  rule, so it also works with scripting off. Two consequences worth knowing:
  the marker describes the *insertion*, not the file, so the same upload can be
  covered in one posting and plain in the next — and marking a file afterwards
  cannot reach postings already written. The cover keeps a picture off a screen;
  it is not access control, because the file itself stays reachable at its own
  address.

- ✓ Fixed: **turning off the "new frontend" notice no longer takes the pointer
  for newcomers with it.** Two messages shared one switch: one describes a
  change that happened and stops being true a few weeks later, the other says
  where the help button is and never stops being true. An installation past the
  switch had to keep a stale message or lose both. They have separate settings
  now — `Saito.notice` and `Saito.noticeHelp` — and both keep the convention
  that an absent key means shown.

## [8.3.0] - 2026-07-30

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.9...8.3.0)

### Upgrading — read this part

**Three migrations run, and they need the command line.**

```bash
php bin/cake.php migrations status     # what is pending
php bin/cake.php migrations migrate
php bin/cake.php schema_cache clear    # not optional, see below
```

- **`bin/cake schema_cache clear` is part of the upgrade, not tidying up
  afterwards.** One migration drops six columns from `users`, and CakePHP keeps
  each table's column list on disk. Until that cache is cleared, every request
  asks the database for a column that no longer exists and gets a 500 — on every
  page, for everyone logged in. Nothing is damaged; the forum simply stays down
  until someone works out why. Reload PHP-FPM afterwards.
- **Not through the web updater.** One migration moves the core tables to
  InnoDB, and on an installation whose `entries` is still MyISAM that rewrites
  the table — measured at 5 minutes 31 seconds for 680,000 postings, with the
  table locked throughout. PHP's execution limit cuts that short in a browser:
  the server finishes anyway, but it may not be recorded as applied. Check
  first whether it even applies to you:

  ```sql
  SELECT table_name, engine FROM information_schema.tables
  WHERE table_schema = DATABASE() AND engine = 'MyISAM';
  ```

  An empty result means the expensive part does not concern you.
- The full picture, including what the InnoDB move does to search results, is in
  [docs/upgrade.md](docs/upgrade.md).

### What is in it

Drafts that keep what you are writing · a subject-length counter · pinning and
unpinning working again · uploads deletable where you hit the limit · the search
finding three-letter words · pasted code keeping its shape · thread merging that
is all-or-nothing · no inline script anywhere, so a content-security policy can
forbid it · a lighter stylesheet · a modernised build chain · and a good deal of
residue removed.

The three pre-release entries below carry the reasoning and the measurements
for each of those; this release is `8.3.0-alpha.3` with the version number
changed.

## [8.3.0-alpha.3] - 2026-07-30

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.0-alpha.2...8.3.0-alpha.3)

**Still a pre-release**, same three migrations as alpha.2 and the same upgrade
notes — command line, then `bin/cake schema_cache clear`.

- Δ Changed: **the markup parser is under static analysis again.** The definitions
  that turn `[code]`, `[img]`, `[url]` and the rest into HTML — the code that
  handles what members type — were excluded from PHPStan. Sixteen findings came
  out of that exclusion, and one of them was a type the code was telling about
  itself: the property holding Saito's markup settings was declared an array
  while it has only ever held an object, so every setting lookup in every
  definition read as nonsense. Nothing was broken by it; nothing could be
  checked either.

  Also honest now about two assumptions: an `[img]` asked its parent node for a
  tag name that only some node types have (safe in practice, fatal if the parser
  ever changed), and four fallbacks for embed values that the library declares
  can never be missing are gone rather than left implying otherwise.

  No behaviour changes. Verified by breaking the one branch that had a
  behavioural choice in it and watching eight tests fail, then putting it back.

## [8.3.0-alpha.2] - 2026-07-30

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.3.0-alpha...8.3.0-alpha.2)

**Still a pre-release**, and it carries one migration more than the first alpha:
three in total. The upgrade notes are unchanged otherwise — run
`bin/cake migrations migrate` from the command line and clear the schema cache
afterwards, or the forum answers 500 on every page. See the entry below.

- ✓ Fixed: **an error page is an error page again.** Any address containing
  `api/` anywhere in it — the query string counted — was answered as JSON
  rather than as the error page: asking for something that does not exist with
  `?x=api/` on the end produced `{"errors":[…]}` for a reader. The API itself is
  unaffected and still answers JSON where it should.

- Δ Changed: **CakePHP 5.3.6 → 5.4.1**, along with eight other direct
  dependencies, plus `aura/di` and Symfony's DOM crawler a major version each.
  Nothing that required a change to the application; the two static-analysis
  findings the framework's tightened types produced were in our own
  annotations, which were saying less than the framework already knew.

- − Removed: **`ecaches`, a cache table Saito stopped writing to in 2014.** The
  code that used it went in 4.6.0 and the table stayed, which is what happens to
  a cache nobody reads: on the live forum it still holds a single row, written
  the day the code was removed, 811 KB of serialized postings from 2014. A
  guarded migration drops it — an installation built from the migrations never
  had it, so there it does nothing.

  That makes **three** migrations in 8.3.0 rather than the two the alpha package
  describes.

  Found the same way as the six `users` columns before it: by comparing a grown
  database against the schema the migrations describe. Grepping the source can
  only ever show that nothing *reads* a thing, never that it is residue.

- Δ Changed: **the 8.3.0 upgrade now says to clear the schema cache**, because
  without it the forum does not come back. CakePHP keeps each table's column list
  in `tmp/cache/models`, and the migration that drops six `users` columns has no
  way to tell it. The next request asks for a column that no longer exists and the
  database refuses: `Unknown column 'Users.user_font_size' in 'SELECT'`. Every
  page that reads a user answers 500 — which is every page, for anyone logged in.

  Nothing is damaged and nothing is lost; the forum is simply down until someone
  runs `bin/cake schema_cache clear`. This applies to 8.3.0-alpha as released:
  the step is missing from the package's own notes, and it is now in
  [docs/upgrade.md](docs/upgrade.md) and [docs/update.md](docs/update.md).

  Found by upgrading the beta installation and watching it fall over.

- ✓ Fixed: **thread lines sit in the middle of their boxes again.** They were
  pinned to the top edge with a full spacer of empty room underneath: the box
  inherits padding on all four sides and only the top one had been taken away.
  Invisible while the box had no border, obvious once it grew one. The boxes keep
  their height, so a page of threads is no taller than before.

  Written after 8.3.0-alpha was tagged, so it is not in that package. Released
  for the live forum as 8.2.9 in the meantime.

## [8.3.0-alpha] - 2026-07-30

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.8...8.3.0-alpha)

**A pre-release.** It is meant for a test installation, not a live forum. The
database changes below are the reason: they are not reversible by downgrading,
and they have been exercised on a copy of one production forum, which is not the
same as having been in service.

**Upgrading needs more than a code deploy.** Two new migrations run — an
installation older than 8.2.8 will have others to catch up on as well, listed in
[docs/upgrade.md](docs/upgrade.md). One of the two rewrites the `entries` table
to convert it from MyISAM to InnoDB — five and a
half minutes on a 320 MB table, with the forum unavailable throughout. Run
`bin/cake migrations migrate` **from the command line**, not through the web
updater, which PHP's execution limit will cut short. Read the 8.3.0 notes in
[docs/upgrade.md](docs/upgrade.md) first; the details are under the InnoDB entry
below.

- ＋ Added: **what you are writing is kept.** A few seconds after you stop typing,
  the reply is stored as a draft; open the same reply again — after closing the tab,
  after the browser crashed, tomorrow — and it is waiting for you, with a note
  saying where it came from and a button to throw it away. One draft per posting
  you are answering, cleared the moment you post, and forgotten after thirty days.

  Nothing is said when a draft is saved, on purpose: you did not ask for it, the
  text is in front of you either way, and a message about a background request is
  noise. A submission that came back rejected always wins over a stored draft —
  what you just typed is newer than what was saved seconds before.

  The storage for this has been in Saito since version 5 — the table, the
  validation, the daily clean-up, the removal after a posting is saved — with
  nothing to write to it: the part that did went with the old frontend. Two things
  turned up while connecting it, and both are fixed below.

- ✓ Fixed: **the subject length an administrator sets is the one the forum
  enforces.** It never was: the setting fed the input field's own limit, while the
  server went on validating against a built-in 100. Set it higher and the field
  accepted a subject the server then refused, with nothing said about why. The
  live forum sits at 101, so exactly one character was affected — long enough to
  be baffling, short enough never to be reported.

- ✓ Fixed: **a draft with a subject could not have been saved at all.** Its length
  limit was read from a setting that the removed frontend's controller used to
  provide, so it came out as zero and refused every subject. Nobody had noticed,
  because nothing wrote drafts.

- Δ Changed: **nothing in Saito is an inline script any more**, so a
  content-security policy can forbid them. That is the strongest single measure
  against stored cross-site scripting: with inline script refused, a payload that
  reaches the page still does not run — the hole closed in 8.2.3 would have been
  inert. Four things had to move: the two blocks that pick the theme and the font
  scale before the page is painted, the feed copy button, and the settings
  sidebar's scroll-spy. `[spoiler]` was the fifth and moved in 8.2.8.

  A per-request nonce turned out not to be needed. Those blocks were inline only
  in order to run early, and a plain external script loaded synchronously does
  that just as well — so the policy can stay where it is instead of the
  application taking it over.

  **This does not switch itself on.** The policy is a web-server header, one
  installation at a time; `config/nginx/saito.conf.example` carries it, still
  commented out, because anything you embed has to be added to it first.
  `'unsafe-eval'` has to stay — Alpine evaluates its expressions as strings — but
  that is the far less dangerous of the two, since it does not enable an injected
  event handler.

- ✓ Fixed: **a member at the upload limit can make room.** Reaching the
  per-member ceiling was a dead end: the editor's upload area said the limit was
  reached and offered no way to delete anything, and nothing mentioned that
  deleting lives in the profile. So the member could neither add nor remove — from
  where they were standing, the archive was simply stuck. Every tile in that area
  now carries the same delete control the profile has, and the message says so.

- ✓ Fixed: **pinning and unpinning a thread works again.** It has been dead since
  the frontend rewrite and failed in complete silence: the island posts the
  request with a token in the header and no form behind it, and the form-tampering
  guard — which had this one endpoint outside its exemption list — rejected every
  attempt before it reached the code. Nothing appeared on screen; the only trace
  was a line in the server log.

  The whole test suite passed throughout, because the integration test harness
  attaches a form token to every request it makes. Every test therefore looked
  like a form submission, which a browser's `fetch` is not — the harness was more
  permissive than the thing it stands in for. The regression test switches that
  token off, which is the only way it could have caught this.

- ✓ Fixed: **merging two threads is atomic, and no longer ages the thread it is
  merged into.** Two faults in one operation.

  The last-answer date was taken from the posting that was merged onto, but only
  a thread's root carries a current one — every reply keeps whatever it held when
  it was written. Merging an older thread onto a reply in the middle of an active
  thread therefore overwrote the root with the older date, and the thread sank
  down a front page sorted by exactly that column although it had just been
  answered.

  And the operation is five dependent writes that ran without a transaction. A
  failure part-way through left the merged thread's root attached to its new
  parent while all of its replies still belonged to the old thread — a state that
  could not be repaired, or even retried, from the interface.

- Δ Changed: **the frontend is type-checked, for the first time.**
  `tsconfig.json` has asked for strict checking for years and nothing ever ran it:
  the build uses esbuild, which strips types without looking at them, so the
  setting was enforced by whichever editor happened to be open. TypeScript is on
  5.9 now, the check runs as part of linting, and both pipelines run it — a type
  error stops a release instead of waiting to be noticed.

  The first run found seven errors in code that ships. None of them was a live
  fault, but two were worth having: a delete button was typed as a plain element,
  so the line that disables it during the deletion would have been accepted and
  done nothing; and Alpine's own properties were undeclared, which is why every
  component that reads `this.$el` counted as an error.

- Δ Changed: **the image library that handles uploads is current again**
  (claviska/simpleimage 3.7.2 to 4.4.0). It parses what members upload, so an
  unmaintained line was the wrong place to sit. Every call the forum makes —
  orient, best-fit, thumbnail, convert — was exercised against a real image
  before and after; the results are identical.

- − Removed: **a dependency nothing used.** `mobiledetect/mobiledetectlib` was
  declared but never called: Saito detects mobile clients with its own check,
  because the library was too slow, and nothing else asked for it either.

- Δ Changed: **the build tools are current again.** TypeScript, ESLint,
  typescript-eslint, Vite, autoprefixer and cssnano were all past their support
  windows — the CSS chain dated from 2018 and was still adding vendor prefixes for
  browsers that no longer exist. Together with the Bootstrap change below, the
  theme stylesheet is **36% smaller** than at the start of this release, and the
  island bundle a little smaller too. Bootstrap itself moves to 4.6.2, the last
  of its line, which carries fixes 4.4.1 did not.

- Δ Changed: **the themes stop shipping the Bootstrap they do not use.** The
  theme stylesheet imported all thirty-eight Bootstrap modules; nineteen of them
  styled components the forum does not have — modals, carousels, spinners,
  tooltips, pagination, breadcrumbs and the rest. Bootstrap's JavaScript has not
  been loaded since 8.1.0 and the island brings its own overlay, so those rules
  could not have been doing anything. All six stylesheets are **23% smaller**;
  nothing in any template changes and no derived theme breaks.

  The list was derived rather than guessed: every class used in the frontend was
  collected, each module compiled on its own, and the two compared. Three classes
  looked needed and were not — they come from the administration templates, which
  get their Bootstrap from a separate file this does not touch.

- ✓ Fixed: **a code block shows the characters that were typed.** The posting
  text is HTML-escaped before it is parsed, and the syntax highlighter escaped it
  a second time, so `<button>` reached the reader as the literal `&lt;button&gt;`
  and `a & b` as `a &amp; b`. A code block could not show markup at all — which
  is most of what a code block is for. Escaped once now, and the checks that
  prove nothing became live markup are made against the parsed document rather
  than by searching the text, which finds `onerror=` in the escaped form too and
  reports a hole that is not there.

- ✓ Fixed: **a pasted code block keeps its shape.** Copying code out of a
  documentation page used to deliver it as one unindented line — and because the
  conversion had "added something", the browser's own paste was suppressed in
  order to produce it, so the writer lost the formatting they would have got by
  doing nothing. `<pre><code>…</code></pre>`, which is what those pages almost
  always ship, no longer arrives wrapped twice either.

- ✓ Fixed: **an admin-only help topic stays admin-only in every language.** Which
  topics are for administrators was read from the file being served, so it was a
  property of the translation: a translated topic that omitted the marker would
  have been readable by anyone. Nothing was wrong in practice — both German admin
  topics carry it — and nothing would have caught the day one did not. The
  English topic decides now.

- − Removed: **six columns in `users` that have been dead since 2012.**
  `user_font_size`, `show_about`, `show_donate` and three `flattr_*` — the last
  belonging to a payment service that shut down in 2018. Upstream removed them
  as a manual SQL step printed in a changelog rather than as a migration, so
  nobody ran it and every grown installation still carries them. Dropped rather
  than revived: `user_font_size` holds a Saito 5 scaling *factor*, not the
  percentage the settings page works in, so the stored values no longer mean what
  they say. An installation that never had the columns is left alone.

- Δ Changed: **the core tables are moved to InnoDB.** MyISAM has no transactions
  and does not object to being asked for one; it accepts the request and ignores
  it. Every safeguard that groups several writes together — the thread merge
  above among them — was therefore doing nothing at all on a MyISAM
  installation, with no error and no way to tell from inside the application.

  **What this means for a grown installation.** The live forums are already
  InnoDB and the migration finds almost nothing to do. An installation whose
  `entries` is still MyISAM should read on — the figures below were measured by
  converting a copy of the live table (679,910 postings, 321 MB) back to MyISAM
  and migrating it for real, not estimated:

  - The table is **rewritten in full**, unavoidably: `entries` carries a
    full-text index, and InnoDB needs a hidden column for one that cannot be
    added afterwards. The measured conversion took **5 minutes 31 seconds** and
    holds a lock throughout, so the forum is unavailable for that long. The
    full-text index survives it.
  - **Keep disk space free for a second copy** — the rebuild writes the new table
    beside the old one. The result, though, came out *smaller*: 279 MB against the
    321 MB it occupied as MyISAM. Expect to need roughly double the table size in
    transit and slightly less than before afterwards.
  - **Migrate from the command line** (`bin/cake migrations migrate`). Through
    the web updater, PHP's execution limit will cut a five-minute conversion
    short; the server finishes it regardless, but it may then not be recorded as
    applied.
  - **The search will find more than before**, and the difference is larger than
    it sounds. MyISAM ignores words shorter than four characters and carries some
    500 stopwords; InnoDB's limits are three characters and 36. On the measured
    copy, the three-letter search `mac` went from **0 hits to 16,384**, while a
    longer term returned exactly the same count as before. Nothing is lost —
    Saito searches in boolean mode, so MyISAM's "ignore words in over half the
    rows" rule never applied — but a forum whose members search for short words
    will notice, and it is better expected than discovered.

  Index lengths are not a concern: `users.username` has been capped at 191
  characters for exactly this reason since the schema was written.

- ＋ Added: **the subject field says how many characters are left.** The limit is
  an admin setting and has always been on the field, so it could not be
  exceeded — the field simply stopped accepting input once it was reached, with
  nothing to say the end was near. The count sits at the right-hand edge of the
  field and turns red for the last stretch.

  Deployed to the live forum ahead of this release, as two static files (the
  island bundle and its stylesheet); it carries no server-side change.

## [8.2.9] - 2026-07-30

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.8...8.2.9)

No migration and no new configuration; upgrading from 8.2.8 is a code deploy.

Two of the four below have been running on the macnemo forum as hand-applied
patches since they were written. This release is what makes them a version you
can name — an installation patched by hand is one nobody can reproduce.

- ✓ Fixed: **pinning and unpinning a thread works again.** It has been dead since
  the frontend rewrite and failed in complete silence: the island posts the
  request with a token in the header and no form behind it, and the
  form-tampering guard — which had this one endpoint outside its exemption
  list — rejected every attempt before it reached the code. Nothing appeared on
  screen; the only trace was a line in the server log.

  The whole test suite passed throughout, because the integration test harness
  attaches a form token to every request it makes. Every test therefore looked
  like a form submission, which a browser's `fetch` is not — the harness was more
  permissive than the thing it stands in for. The regression test switches that
  token off, which is the only way it could have caught this. A second test now
  walks the island's write endpoints and fails if any of them is left out of the
  exemption list, so the next one cannot be added silently.

- ✓ Fixed: **a member at the upload limit can make room.** Reaching the
  per-member ceiling was a dead end: the editor's upload area said the limit was
  reached and offered no way to delete anything, and nothing mentioned that
  deleting lives in the profile. So the member could neither add nor remove — from
  where they were standing, the archive was simply stuck. Every tile in that area
  now carries the same delete control the profile has, and the message says so.

- ✓ Fixed: **thread lines sit in the middle of their boxes again.** They were
  pinned to the top edge with a full spacer of empty room underneath: the box
  inherits padding on all four sides and only the top one had been taken away.
  Invisible while the box had no border, obvious once it grew one. The boxes keep
  their height, so a page of threads is no taller than before.

- ＋ Added: **the subject field says how many characters are left.** The limit is
  an admin setting and has always been on the field, so it could not be
  exceeded — the field simply stopped accepting input once it was reached, with
  nothing to say the end was near. The count sits at the right-hand edge of the
  field and turns red for the last stretch.

## [8.2.8] - 2026-07-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.7...8.2.8)

No migration and no new configuration; upgrading from 8.2.7 is a code deploy.

This release comes out of one question — *what else does the backend still do
that the frontend no longer uses?* — asked after the link previews in 8.2.7
turned out to have been working on the server for months with nothing to show
them. The answer was eight more cases. Two of them were taking members' input
and quietly throwing it away.

### Changes

- ✓ Fixed: **thread colours work again.** A member could set their own colours
  for unread, read and current thread lines; the form saved them and the page
  ignored them. The one line joining the two ends was lost when the old layout
  went, and nothing noticed, because everything on either side of it kept
  working. The colours are now applied through the stylesheet rather than an
  inline `<style>` block, which also means they survive the tightening of the
  content-security policy planned for 8.3.
- − Removed: **the "reload the forum every N minutes" setting.** It was offered
  in the profile, validated and stored — and read by nothing. What used to read
  it was removed as unreachable even before the frontend rewrite. A switch that
  does nothing is worse than no switch; the database column stays, so nothing
  needs migrating.
- ＋ Added: **list and spoiler buttons in the editor.** Both markups have always
  worked and always rendered; neither had a button, so the only way to find them
  was to read the source. `[spoiler]` in particular hid its content behind an
  inline click handler — it would have broken silently under a stricter security
  policy, with nobody able to connect the two.
- ＋ Added: **formatting help in the editor.** A question mark in the toolbar
  opens a guide to writing postings, which links on to the complete markup
  reference. That reference was written years ago and has been in the help all
  along, with nothing pointing at it.
- ＋ Added: **notes on bookmarks can be edited again.** The bookmarks page showed
  them, but the only thing able to write one was the interface the old frontend
  used. Anyone who had annotated a bookmark could read the note and never change
  it, and no new one could be made.
- ＋ Added: **administrators can see a member's uploads again.** The permission
  has been granted the whole time; the page serving it was fixed to the current
  user, so acting on it meant leaving the forum. A member's profile now shows
  their archive to an administrator, with the same delete control.
- − Removed: **the token cookie the old frontend read.** Saito minted a
  deliberately script-readable authentication token on every signed-in request,
  for a client that no longer exists — and the server never accepted it in the
  first place. Existing cookies are cleared on the next visit. Two API addresses
  that pointed at code that was never written now answer as not-found instead of
  erroring.
- − Removed: a status endpoint, an orphaned page duplicating the profile, nine
  unused helper methods, the editor's obsolete second button definition, and
  seven settings that nothing reads. All verified unreachable by reading, not by
  a failed search.

### Notes

Three further findings were deliberately left alone rather than guessed at, and
draft autosave — whose storage layer is intact but whose feature is gone —
needs a release of its own. The reasoning for each is in `todo.md`.

## [8.2.7] - 2026-07-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.6...8.2.7)

No migration and no new configuration; upgrading from 8.2.6 is a code deploy.
Mostly editor work, plus one feature that turns out to have been half-built all
along.

### Changes

- ＋ Added: **link previews**. Pasting an article address can now show a card —
  title, teaser and picture from the linked page — instead of a bare URL. Tick
  *insert as preview card* in the insert dialogue; images and videos are not
  offered it, being already visible as themselves.

  The server side of this has existed and worked the whole time: `[embed]`
  fetched the page and read its data, and then handed the result to a frontend
  that had been removed. The forum has been fetching previews and showing nobody
  anything. What the far end supplies as ready-made markup is deliberately still
  ignored — the card is built from the individual fields, so a link in a posting
  cannot become script on the forum.
- Δ Change: **one insert button instead of two.** *Link* and *Media* were the
  same button twice — same dialogue, same behaviour, different icon. What gets
  inserted has never depended on which was pressed but on the address given:
  a YouTube link becomes a frame, an image address an image, anything else a
  link. The one that remains says *Link/Embed*.
- ＋ Added: **the insert dialogue starts from the selection.** Select a word,
  press the button, and it is already in place — an address as the address,
  anything else as the link's label. The selected text is replaced by the
  result rather than left standing beside it.
- ＋ Added: **replies offer the thread's subject.** It stands pale in the field
  with *Re:* in front, is used as it is when nothing is typed, and gets out of
  the way as soon as you write. The prefix does not stack, and it is
  translatable for installations that write it differently.

### Under the hood

- The paste-to-BBCode converter was one function of cyclomatic complexity 55,
  rated critical — the project's own note says to act at that point, so it was
  taken apart into four. Verified against the previous version over eighteen
  paste cases with identical output. Expect the *count* of complexity findings
  to rise rather than fall: splitting one function into four turns one
  occurrence into four.
- Two queries that deliberately return arrays rather than entities now say so,
  which retires fifteen suppressed findings that were reporting correct code.

## [8.2.6] - 2026-07-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.5...8.2.6)

One stylesheet line. Upgrading from 8.2.5 is a code deploy; browsers pick the
change up by themselves, the stylesheet is served with a cache-busting stamp.

### Changes

- ✓ Fix: **the subject field was still wider than its own limit.** 8.2.5 capped
  it at one `ch` per allowed character, but `ch` is the width of a zero and
  prose is narrower — a hundred characters of ordinary text measure 713px where
  `100ch` is 891px, so a fifth of the box stayed empty and it still invited more
  than it takes. Now sized by the measured ratio: 899px before, 751px after, for
  733px of text and padding.

## [8.2.5] - 2026-07-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.4...8.2.5)

Fixes only; no migration and no new configuration. Upgrading from 8.2.4 is a
code deploy.

One thing to check before deploying, if your installation sets upload sizes as
text: a value the parser cannot read is now refused instead of being silently
replaced by the built-in default (see below). `'8MB'` and `'650kB'` are fine;
`'8 Megabytes'` is not, and would now stop the application at start-up rather
than quietly meaning 2 MB.

### Changes

- ✓ Fix: **bookmarks were saved past their own validation.** `UsersTable` and
  `EntriesTable` declared the association without the plugin prefix, so CakePHP
  quietly bound a generic table to the same rows — without the duplicate check,
  the "posting exists" and "user exists" checks, or the timestamps. Only the
  deletion path went through it, which is why nothing had noticed.
- ✓ Fix: **editing a smiley without an id answered with a server error**
  instead of "Invalid smiley." The guard combined two conditions with `and`
  where it needed `or`; the deletion path had it right.
- ✓ Fix: **a mistyped upload size silently became the default.** `'8 Megabytes'`
  in `config/saito_config.php` meant 2 MB and said nothing. It is now refused,
  naming the value.
- ✓ Fix: **the who's-online list could go permanently empty with nothing in the
  log.** A database error during the online-ping was caught and discarded — the
  suppression was meant for one specific race and applied to everything.
  Anything else is raised again.
- ✓ Fix: an installation whose settings table is empty got an empty
  configuration rather than the intended error, and that empty configuration was
  then cached.
- ✓ Fix: deleting a smiley code showed its success message in the error styling.
- Δ Change: **the subject field is no longer wider than the subject may be
  long.** It spanned the whole form, so it invited a headline several times what
  the forum accepts. The width follows the admin setting for subject length.
  Editing a posting also had no length limit at all, while adding and replying
  did — so that was the one place where the limit was only discovered on saving.
- − Removed: the Spectrum colour picker. Nothing had called it since the profile
  moved to the browser's own colour input, and it could not have worked anyway —
  it needs jQuery, which left with the old frontend.

### Under the hood

- Static analysis: eight suppressed findings turned out to be real defects (six
  of them above). The suppression list is down from 52 entries to 36, and the
  rule that catches a redirect whose result is dropped — the shape behind two of
  this week's bugs — is no longer switched off for the files that need it.
- A release whose CHANGELOG section is missing is now refused before it is
  built, rather than published with its version number as the only note.

## [8.2.4] - 2026-07-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.3...8.2.4)

Fixes only; no migration and no new configuration. **One change is visible to
admins:** changing a member's role now asks for your own password.

### Changes

- Δ Change: **granting a role asks for the acting admin's password.** A role
  outlives the session that handed it out, which made it the one action worth
  hijacking a session for — and a session is exactly what a cross-site scripting
  flaw gives away. Everything else an admin can do can be undone by an admin who
  still has access; this could not, so it is the only place that gained a
  confirmation rather than the backend gaining a re-authentication step.
- ✓ Fix: **pinning or locking a posting appeared to do nothing.** The posting was
  reloaded with two synthetic clicks, but that only hid and re-showed the version
  from before the change — so a moderator saw no effect, clicked again, and set
  the flag back on the server.
- ✓ Fix: **undo works after the toolbar and after inserting an upload.** Both
  still wrote into the text box in a way that discards the browser's undo
  history, which the smiley button had already been fixed for. The upload path
  additionally never told the editor to grow to fit what it had inserted.
- ✓ Fix: saving a setting from the console or the updater left the old values in
  place — the settings cache was cleared under a key it is never stored under,
  and only a side effect of the surrounding cache clear covered that up during a
  web request.
- ✓ Fix: a refused "mark as solution" was reported as a malformed request
  instead of as forbidden, and the underlying cause was discarded.
- ✓ Fix: a failed save of the widget arrangement was still written into the
  session, so it looked like it had worked until the next sign-in put the old
  arrangement back.
- Δ Change: pinning and locking travels by POST like its neighbours. It was
  reachable by GET, kept out of reach cross-site only by a header requirement
  that happens to be hard to forge rather than by a token.
- − Removed: two retired action names left in the form-protection list, and a
  debug timer that was started but never stopped when a visitor has no readable
  category.

## [8.2.3] - 2026-07-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.2...8.2.3)

**Security release. Upgrading is strongly recommended for every installation.**
No migration and no new configuration; upgrading from 8.2.2 is a code deploy.

One change needs attention on an installation that has never set
`SECURITY_SALT`: see the first entry below.

### Changes

- ✓ Fix: **stored cross-site scripting through a member's profile homepage
  address.** The address was written into the link without being escaped, so
  content placed there ran in the browser of anyone opening that profile —
  including a moderator reviewing a reported account. Any signed-in member could
  set it. Present since 2018 and in every release up to 8.2.2.
- ✓ Fix: **a thread could be moved into any category at all.** Editing a thread's
  first posting accepted whatever category the request named; the permission
  check ran against the category the thread was already in and never looked at
  the destination. Since moving the first posting moves the whole thread, this
  could carry other people's replies out of a closed category — or hide a thread
  in one nobody can read.
- ✓ Fix: **`config/app.php` shipped a real-looking default for `SECURITY_SALT`**
  instead of the `__SALT__` placeholder, in 8.1.0 through 8.2.2. This repository
  is public, so an installation that never set the value encrypted its login
  cookie and signed its API tokens with a key anyone could read — and was told it
  was configured correctly, because both the installer's key generation and its
  own check look for exactly that placeholder. **If your installation runs 8.1.0
  – 8.2.2 and has no `SECURITY_SALT` in `config/.env`, set one now.** Doing so
  signs everybody out; that is the intended effect. Installations on 8.0.x or
  earlier, and any that set the value themselves, are unaffected.
- ✓ Fix: **"forum closed" did not close the forum.** The notice was rendered but
  the request carried on, so postings were still served to visitors and members
  could still write, delete and upload while the forum was supposedly switched
  off.
- ✓ Fix: **the contact form could send mail to any address.** Ticking "send me a
  copy" delivered to whatever address the sender had typed in, which for an
  anonymous visitor is not their address in any verified sense — so the form
  could be used to send arbitrary text to arbitrary recipients from the forum's
  own domain. The copy now goes only to a signed-in member, whose address comes
  from their account. A per-address limit of five messages an hour joins it.
- ✓ Fix: `[iframe]`, `[video]` and `[audio]` accepted URL schemes that `[img]`
  and `[url]` had been rejecting for a while. For frames this mattered on an
  installation that had widened `video_domains_allowed` to `*`.
- Δ Change: deleting a smiley or a smiley code, emptying the caches and lifting a
  block now require a POST. Over GET they sat outside the cross-site request
  protection entirely, so a lured admin's browser could trigger them by loading
  an image. Their confirmation dialogs start working as a side effect — the
  previous markup passed the confirmation text in a position that was ignored.
- ✓ Fix: lifting a block reported success even when it had failed, and answered a
  second click with a server error instead of a message.

## [8.2.2] - 2026-07-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.1...8.2.2)

No migration. One new optional key in `config/saito_config.php`; leaving it out
keeps 8.2.1's behaviour.

### Changes

- ＋ Added: **pasting from a web page keeps its links and emphasis.** A copied
  passage arrives as BBCode — links, bold, italic, strikethrough, lists, quotes
  and images — instead of losing all of it to plain text. Word's and Google
  Docs' habit of saying the same thing twice, in a tag and again in a style, is
  handled; anything unrecognised contributes its text and nothing else. When the
  conversion would add nothing, the browser's own paste is left alone.
- ＋ Added: `Saito.unreadRail`. Unread thread lines carry the accent colour, bold
  weight and a short vertical bar; `false` drops the bar and keeps the other two.
  Meant for forums whose readers have always gone by colour, where the bar reads
  as clutter. The space it occupied stays transparent, so switching it does not
  shift the thread list sideways.
- ✓ Fix: **undo stopped working after inserting a smiley, a link or a pasted
  passage.** Text was written into the box in a way that discards the browser's
  undo history — so Ctrl-Z did nothing, and could not recover what had been typed
  before it either.
- ＋ Added: the two admin help topics that existed only in English are now
  translated, so `docs/help/de` and `docs/help/en` hold the same eleven topics.
- Δ Changed: `docs/license.md` names the web fonts and the frontend libraries
  that ship with the forum, and the font licence travels next to the fonts it
  covers, as its terms require.
- ＋ Added: a `.mailmap`, so contribution counts show people rather than
  addresses. The README credits **Gert Dietrich** and **kt007** alongside
  Schlaefer.
- Δ Changed: static-analysis cleanups, and CI takes Node from the distribution
  and pins its container image.

## [8.2.1] - 2026-07-28

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.2.0...8.2.1)

Fixes only; no migration and no new configuration. Upgrading from 8.2.0 is a code deploy.

### Changes

- ✓ Fix: **Command-click on a posting stopped opening a new tab.** It had not worked since the island frontend arrived: the thread list intercepted every click and navigated by hand, doing what the browser does anyway while removing everything else it can do with a link. Ctrl-, Shift-, Alt- and middle-click were lost the same way. The mix button had the same flaw.
- ✓ Fix: **the editor preview appeared between the toolbar and the text box** instead of above the form, and showed only the body. It is now a panel of its own above the editor, shaped like the posting it previews — heading, then category, author (linked to their profile), place, time and views, then the text. A subject with no text previews as "… n/t", which is what the forum will render.
- ✓ Fix: **the text box no longer stays small.** Four rows is right for a one-line answer and wrong from the first paragraph on; it grows with what is written, capped so the buttons underneath stay reachable.
- ✓ Fix: the CHANGELOG lost the 8.0.12 and 8.0.13 sections when the release line branched off, so 8.1.0's "go to 8.0.12 first — [8.0.13](#8013---2026-07-27) explains why" pointed at an anchor that did not exist. The fixes themselves had all arrived; only their record was missing.
- ✓ Fix: `htmxWidgets()` documented itself as returning nothing while returning a response, which the static analysis caught only once it reached the mainline.
- Δ Static analysis: two dead test helpers and an unused import removed, and a deliberately un-awaited request now catches its own rejection instead of leaving one for the console.

## [8.2.0] - 2026-07-28

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.1.0...8.2.0)

Nothing in the database changes and no migration runs; upgrading from 8.1.0 is a code deploy. Three new keys appear in `config/saito_config.php` — all optional, and leaving them out keeps 8.1.0's behaviour. See [docs/customizing.md](docs/customizing.md).

### Changes

- ＋ The widget rail can be arranged again: drag a widget by the handle beside its heading, or move it with the arrow keys once the handle has focus. Saito 5 could do this and the island frontend could not. The order goes back into `users.slidetab_order` — the column that held it all along — so nothing in the database changes.
- ＋ `Saito.bannerHtml` places operator-supplied markup between the header bar and the page, in a `div.ads_top` — the slot forums have traditionally used for a banner. Empty renders no container at all.
- ＋ `Saito.widgetsForGuests` makes the widget rail a members-only feature. Enforced in the controller, not only in the markup: with it off the fragment endpoint answers a guest with nothing, so the online list cannot be read by requesting it directly.
- ＋ `Saito.notice` switches off the "modernised frontend" bar, which earns its place in the weeks around a switch and not for ever after.
- ✓ Fix: the header hard-coded `forum_logo.svg`, so a theme whose wordmark existed only as a bitmap got a broken image and no explanation. It now takes whichever of `svg`, `png`, `webp` or `jpg` the theme ships, preferring the vector.
- ✓ Fix: `docs/customizing.md` told theme authors to register their plugin in `src/Application.php`. Nothing there has to know about a theme — setting `Saito.themes.default` is enough. The help overlay also promised "a coloured bar marks unread", which a theme is free to leave out; it now leads with the colour, which always holds.
- Δ TypeScript's standard library followed `target: es6`, so `Array.prototype.includes` type-checked as an error in island code that has always shipped. The build never noticed — esbuild strips types without checking them.

## [8.1.0] - 2026-07-28

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.13...8.1.0)

**The Backbone/Marionette frontend is gone.** Pages are rendered by the server and enhanced with small htmx/Alpine islands. Nothing in the database changes and no content is touched — it is a different way of drawing the same forum. The administration area came along: it no longer loads jQuery, DataTables or Bootstrap's JavaScript, and looks the same.

Upgrading from 5.7 or from 8.0.x: see [docs/upgrade.md](docs/upgrade.md). Go to 8.0.12 or later first if you are on an earlier 8.0.x — [8.0.13](#8013---2026-07-27) explains why.

### Changes

- − Removes the single-page application: 28 controller actions, ~34 templates, the whole `frontend/src` tree apart from the islands, the webpack and karma builds, and 52 npm dependencies.
- − Removes what the retired frontend left behind: jQuery UI's stylesheet, which was still served on every page; per-theme Marionette scripts no layout loaded; the Stopwatch chart, whose only method emitted jQuery; four classes with no callers; and templates nothing rendered. 47 files, 47,000 lines.
- ＋ The help overlay's tour is Markdown now, in German and English, and a forum can supply its own — from `config/help/` (which survives updates) or from its theme. `docs/help` finally ships: it was excluded from the release tarball, so `/help` said "no help pages are available" on every installation.
- ＋ Four new help topics — uploads, bookmarks, widgets and RSS feeds — and the BBCode reference is listed at last; it existed for years in the BbcodeParser plugin, where nothing ever looked.
- Δ `/help` opens a topic under its heading instead of navigating away, and the overlay fetches its content when first opened rather than being rendered into every page.
- ✓ Fix: the administration area discarded every message it produced. The flash elements only fill a store the retired frontend read, and the backend layout never emptied it — all 35 of them went unseen.
- ✓ Fix: image smilies and the smiley icon font were broken under Nova, the default theme.
- ✓ Fix: the CSRF meta tag was rendered by nine templates individually and missing from the ones written later, so on those pages every scripted write was answered with 403 — the editor preview, uploads and the widget state, each failing silently.
- ✓ Fix: the profile shows again who you ignore and how many members ignore you. The help had described both for years; the island profile stopped rendering them.
- ✓ Fix: the advanced search was reachable only from a profile or a posting list. All three search views link to it now.
- ✓ Fix: the category delete overlay in the backend could not be opened, and the settings sidebar stopped marking the section in view — both left behind by dropping Bootstrap's JavaScript.
- Δ `composer test-all` works and no longer rewrites the source tree while testing it. PHPUnit reports no deprecations.
- Δ Five public actions have functional tests for the first time, and the authorization tripwire now has a meta-test — it silently stopped guarding once before.

## [8.1.0-alpha.4] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.1.0-alpha.3...8.1.0-alpha.4)

Carries everything from 8.0.12 and 8.0.13, plus the island bundle rearranged into one file per feature.

### Changes

- ✓ Fix: the utf8mb4 migration restated `users.user_category_custom` as `VARCHAR(512)`, undoing the widening to 1024 that Saito 5.0.0 made. The column holds a serialized list of chosen categories — 512 characters run out at roughly 40 of them, and outside MySQL's strict mode the value is cut silently, which does not shorten such a list but destroys it. A second migration widens the column again where the narrowing version already ran.
- ✓ Fix: image smilies were broken on Nova, the default theme. `/img/smilies/<name>.svg` resolves against the active theme and then falls back to the application webroot, but that directory did not exist and Nova carries no images of its own. The 23 pictures now ship there as the base every theme falls back to.
- ✓ Fix: the CSRF meta tag was rendered by nine templates individually and missing from the ones written later, so on those pages every scripted write was answered with 403 — the editor preview, uploads and the widget state, each failing silently. The island layout emits it now.
- ✓ Fix: the category delete overlay in the backend could not be opened at all. `x-show` reveals an element by clearing the inline display it set, which handed the dialog back to Bootstrap's `.modal { display: none }`.
- ✓ Fix: the settings sidebar stopped marking the section in view — it configured Bootstrap's ScrollSpy, which left with Bootstrap's JavaScript.
- Δ The island bundle is one file per feature instead of one file of 1360 lines: threads, postings, editor, uploads, smartInsert, widgets, categoryFilter, headerActions, modals, flash, appearance. `runtime` publishes htmx and Alpine, `lib/dom` holds what several features share.
- Δ The five overlays are described as a table rather than five near-identical branches of one click handler.
- Δ CI takes Node from Debian and pins the container image, after an unpinned `php:8.4-cli` moved to Debian 13 mid-release and NodeSource — which publishes nothing for it — left the build without npm.

## [8.1.0-alpha.3] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.1.0-alpha.2...8.1.0-alpha.3)

Two controls in the backend that the previous release left dead. Neither raised an error — they simply stopped doing anything.

### Changes

- ✓ Fix: the category delete overlay could not be opened at all. `x-show` reveals an element by clearing the inline `display` it set, which handed the dialog back to Bootstrap's `.modal { display: none }` — the stylesheet we deliberately kept. Visibility now rides on a bound class.
- ✓ Fix: the settings sidebar stopped highlighting the section in view. It configured Bootstrap's ScrollSpy, which left with Bootstrap's JavaScript; a small scroll handler took over.
- ✓ Fix: the delete overlay's close button still carried Bootstrap's `data-dismiss`. Escape, the backdrop and Cancel had always gone through Alpine; only the X was affected.

## [8.1.0-alpha.2] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.1.0-alpha...8.1.0-alpha.2)

### Changes

- − The administration backend drops jQuery, DataTables and Bootstrap's JavaScript. Its four behaviours — a sortable and filterable member table, the confirmation overlay, and the navigation's two menus — are rebuilt on the Alpine the forum already ships. Bootstrap's stylesheet stays, so the backend looks unchanged; its bundle is 48 kB where the old one was 193 kB. Everything degrades: without script the tables are tables, the overlay a page section, the menus links.
- ✓ Fix: `/login` threw a ReferenceError on every visit. Its template carried an inline script calling `SaitoApp` and jQuery, both of which went with the retired frontend, and its "back" link relied on a header subnav the island layout does not render. `login()` now serves the same page as `/users/htmx-login`.
- ✓ Fix: the island login form had its own copy of the fields and had quietly dropped the `autocomplete` hints password managers rely on, `required`, and the tab order. Both logins use the shared form element again.
- ✓ Fix: theme fonts were fetched twice, once from a path that 404s. Bota declared its @font-face rules relative to its own stylesheet — correct for Bota, wrong for every theme importing its partials, where they resolved under that theme's webroot. Now absolute, in one place.
- Δ The member table's initial sort order is honoured. `AdminHelper::jqueryTable()` took a sort argument and never passed it to DataTables, so the order both call sites asked for had been decorative all along.

## [8.1.0-alpha] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.11...8.1.0-alpha)

**The Backbone/Marionette frontend is gone.** Pages are rendered on the server and enhanced with small htmx/Alpine islands. Nothing in the database changes and no content is touched — it is a different way of drawing the same forum. Pre-release: for a test installation, not for a live forum yet.

### Changes

- − Removes the single-page application: 28 controller actions, ~34 templates, the whole `frontend/src` tree apart from the islands, the webpack and karma builds, and 52 npm dependencies. 23,800 lines.
- − Removes the `Saito.frontend` switch and `SAITO_FRONTEND`. There is one frontend, so there is nothing to choose.
- ＋ Published URLs of the retired frontend redirect permanently to their island equivalents — `/entries/view/<id>`, `/users/view/<id>`, `/entries/mix/<tid>`, the two indexes and the registration form. Two decades of search-engine entries, bookmarks and links from other sites keep landing in the right place.
- Δ `@name` mentions and `#123` tags in posting text now point at endpoints that survive. These are substituted into posting *text* at render time, so they decide where every mention in twenty years of content leads; a guard test parses each through the router and asserts the action exists.
- ＋ Moderation the island frontend had left unreachable is back: role changes and account deletion in the administration backend, blocking on the member's profile page where moderators can reach it.
- ✓ Fix: XML and RSS responses were being wrapped in the theme's HTML layout — wrong all along, and harmless only because nothing validated the output.
- Δ The administration backend is unchanged and still loads its own jQuery/Bootstrap globals; it was never part of the SPA.

### Upgrading

Members with a page open across the upgrade should reload once — a tab keeps running the JavaScript it loaded. 8.0.x remains a working intermediate stop for anyone who would rather move the platform and the interface on different days; see `docs/upgrade.md`.

## [8.0.13] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.12...8.0.13)

Three things that failed quietly: broken smiley images on the default theme, and two ways a page could silently lose its ability to send anything.

### Changes

- ✓ Fix: image smilies were broken on Nova, the default theme. The forum emits `/img/smilies/<name>.svg`, which CakePHP resolves against the active theme and then falls back to the application webroot — but that directory did not exist, and Nova carries no images of its own. Members saw broken images. The 23 pictures now ship in `webroot/img/smilies/` as the base every theme falls back to. A theme keeps its own copy only if it wants different ones; Macnemo's is identical, so nothing changes visually there.
- ✓ Fix: the CSRF meta tag was rendered by nine templates individually, and the ones written later did not have it. On those pages every scripted write request was answered with 403 — the editor preview, uploads and the widget state, each failing without a word. It is emitted by the island layout now, where a new page cannot forget it.
- ✓ Fix: reading a member's minimised widgets suppressed errors with `@`, which hides more than the one warning it meant to. Narrowed to a handler scoped to that single call.
- Δ `docs/upgrade.md` names both migrations and says plainly to upgrade to 8.0.12 or later, with the query that shows whether an earlier 8.0.x truncated anything.

## [8.0.12] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.11...8.0.12)

**Recommended for anyone still to upgrade from Saito 5.x.** A migration shipped since 8.0.0 narrowed a column instead of only converting its character set, which can destroy data on forums with many categories.

### Changes

- ✓ Fix: the utf8mb4 migration restated `users.user_category_custom` as `VARCHAR(512)`, undoing the widening to 1024 that Saito 5.0.0 made — while its description promised a character-set conversion and nothing else. The column holds a serialized map of the categories a member chose to see: 512 characters run out at roughly 40 categories, 1024 at roughly 75. Outside MySQL's strict mode the value is cut silently, and a cut serialized array cannot be read back at all.
- ✓ Fix: a second migration widens the column again on installations that already ran the narrowing version — Migrations never replays a recorded version, so they cannot be repaired any other way. Widening never truncates and is safe to run at any current width.

## [8.0.11] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.10...8.0.11)

### Changes

- ＋ The front page can be filtered by several categories at once again, as the retired chooser allowed — a button showing the selection opens a list of checkboxes. Almost nothing had to be built: the query layer has always taken a list of categories and intersects it with what the member may read; only the controller read a single value.
- ✓ Fix: sending a message from the contact overlay left the overlay standing with no confirmation. The mail went out and the flash message then turned up on whatever page the sender opened next. `_contact()` builds a response — an `HX-Redirect` for the overlay, a 302 otherwise — and all four call sites dropped it. The standalone contact page was affected too: it re-rendered its own empty form instead of redirecting.
- ✓ Fix: on a phone, tapping a widget heading did nothing at all. The minimise-to-icon behaviour added in 8.0.10 disabled itself on narrow screens — correctly, an icon wins no width there — but took the folding away with it. The same tap now folds the content on a phone and shrinks to an icon on a wide screen.
- ✓ Fix: the posting tool menu was cut off mid-list on a phone. Below the `md` breakpoint the theme gives the thread box `overflow-x: auto` so a deeply indented tree can be scrolled sideways, and CSS then forces `overflow-y` to `auto` as well — there is no way to scroll one axis and overflow the other. The menu now stands in the flow there instead of floating.
- Δ The help overlay describes the category filter and the widget rail as they now behave.

## [8.0.10] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.9...8.0.10)

### Changes

- ＋ Moderation the island frontend had left unreachable is back. Changing a member's role and deleting an account moved into the administration backend; blocking a member sits on their profile page, where moderators can reach it — the backend is admin-only, and the permission has always granted blocking to moderators. Since the cutover none of the three had a door at all: they hung off the retired profile page, so nothing errored, the buttons were simply on a page nobody sees any more.
- ＋ The front page's right-rail widgets can be minimised to icons docked at the right edge, as the old slidetabs were. With every widget minimised the rail narrows and the thread list takes the width back. The online icon carries the number of signed-in members; guests and bots stay inside the open widget. The arrangement is stored on the member's account, so it follows them to another device.
- Δ Deleting an account is now admins and the owner only. Previously the permission also granted it to moderators while the backend did not let them in — two places disagreeing about who may do something is how a later refactor quietly hands the right back.
- ✓ Fix: unblocking from the administration backend was broken. The link built `/admin/users/unlock/<id>`, an action that does not exist there — `'admin' => false` is a CakePHP 2/3 idiom that stopped resetting the route.
- ✓ Fix: `@name` mentions and `#123` tags in posting text dropped the reader back into the retired interface on an island installation. Both now follow the active frontend.
- ✓ Fix: the mix icon sat six pixels off-centre in its button. The toolbar tightening removed the button's left padding entirely; the padding is now split evenly, so the button keeps its width and the icon is centred.
- ✓ Fix: replying inside a thread on the front page unfolded every posting in that thread. The refresh now returns the subject lines the reader had.
- ✓ Fix: a block duration is validated against what the controls actually offer, instead of being taken as sent — a hand-made request could set a block of any length.
- Δ Internal: the macnemo theme was missing from the Sass build, so changes to the shared partials stopped at Nova and never reached the theme macnemo.de runs.

## [8.0.9] - 2026-07-27

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.8...8.0.9)

### Changes

- ＋ Postings without a text are possible again: leave the field empty and the subject is shown with ` n/t` appended, as Saito has always rendered them. The model never required a text and neither did the new-thread form — only the island's reply form carried a `required`, which is why the "n/t" in older subjects had to be typed by hand. The placeholder now says the field may stay empty; without that the feature exists but nobody finds it.
- ＋ A single posting has an island page again: `/entries/htmx-posting/<id>` shows the posting in full with the thread's tree below it — the counterpart to the SPA's `entries/view`, which was the one of the original three thread views without an island equivalent.
- ✓ Fix: after replying inside a thread the new posting did not appear. The reply only swapped the form for a confirmation, so the author saw "saved" and then no posting — and reasonably assumed it was lost. The thread is now reloaded around the confirmation.
- ✓ Fix: a too-long subject produced "Please check your entry." because the form discarded the validator's messages and only checked whether the list was empty. It now says what is actually wrong, and the field carries a `maxlength` taken from the *Subject length* admin setting, so the limit cannot be exceeded in the first place.
- ✓ Fix: widget headings and their icons were near-black on a near-black card in the dark theme. The heading is a `<button>`, and a button does not inherit `color` — without an explicit one it falls back to the browser default, which ignores the page theme.
- ✓ Fix: several links still led into the SPA on an island installation — a posting's subject, "show thread" after replying, and the redirects after deleting or merging.
- Δ Privacy: exceptions no longer carry a full client IP into the log; the host part is masked as the `store_ip_anonymized` setting does for postings. Scanner probes (`/config/…`, `/wp-includes/…`) are no longer written to error.log at all but to `logs/probe.log` — told apart from a genuine routing bug by whether the referer points at this installation, so a dead link of our own is still logged as an error.
- Δ `docs/deployment-debian.md` documents anonymised nginx access logs, and the shipped `saito.conf.example` carries the `map`/`log_format` for it.

## [8.0.8] - 2026-07-26

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.7...8.0.8)

### Changes

- ✓ Fix: two beta safeguards hung off `Saito.frontend === 'island'` and would therefore have followed a live forum the moment it switched to the island frontend — a corner ribbon reading "Beta", and, far worse, the default *not to send email at all*: registration confirmations, password resets and thread subscriptions would have gone silently to the log. Both now hang off a separate `Saito.beta` flag that is off by default, so a live installation is clean without anyone having to remember an environment variable at switch-over time. Set `SAITO_BETA=true` on a test deployment.
- Δ The notice banner stays on a live installation after the switch, because that is exactly when people arrive with a stale browser cache and an interface they have never seen: it now leads with the cache hint (including the reload shortcuts) and points at the help below it. On a beta the beta notice takes the lead instead. CSS classes renamed `beta-*` → `island-*` accordingly.

## [8.0.7] - 2026-07-26

- [Full commit-log](https://github.com/Panxatony/Saito/compare/8.0.6-alpha...8.0.7)

### Changes

- ＋ Themes: **Nova** is the new default — a modern take on Bota with rounded shapes, a warmer neutral palette and its own design tokens. It ships a Saito logo (a thread and two replies, wordmark in the theme's own Cabin cut converted to outlines) so a fresh installation no longer shows a broken image. The macnemo identity was rebuilt on top of Nova and renamed from `Local` to **`Macnemo`**; its bubble artwork now sits as a height-limited masthead motif over a warm wash instead of growing into a full-page mural on wide screens.
- ＋ Profile settings: pick which categories to show as a checkbox list, reset each colour to the default with a tick box, and change the password in an overlay instead of on a separate page.
- ＋ Search: results load in pages via "load more" instead of stopping silently at the first twenty, in the advanced search as well as the header widget.
- ＋ Member list: reachable from the word "Benutzer" in the online widget and paged with "load more".
- ＋ Thread list: the mix button expands the whole thread in place when "expand posting on click" is enabled — one request for every posting at once, a second click folds it back.
- ＋ Privacy policy page at `/pages/privacy`, fed from `Saito.privacy` like the imprint and linked in the footer. `docs/privacy-policy-template.md` inventories what the software actually processes.
- ✓ Fix: the advanced search's time filter never worked after the CakePHP 3 migration. The controller read the old `month[month]`/`year[year]` shape while the widgets submitted flat values, so the search was silently capped at the last twelve months regardless of what was selected; the year list offered future years because `minYear`/`maxYear` are not CakePHP 4+ option names. The two controls are now one month field that the controller actually reads.
- ✓ Fix: the member list showed a hundred members and pretended that was all of them — `limit => 400` is silently capped by CakePHP's `maxLimit`, and the island offered no page navigation at all.
- ✓ Fix: every username in the forum linked to the SPA profile page on island installations, as did the statistics line in the footer, the edit, merge and mix actions on a posting. All of them now follow the active frontend.
- ✓ Fix: "Letzte Beiträge {0}" showed the placeholder instead of the member's name.
- ✓ Fix: widgets stayed light in dark mode; the sort-order radio group ran together; colour pickers showed black for an unset colour.
- ✓ Fix: the profile's upload list had the wrong heading and its multi-selection did nothing — several uploads can now be deleted together.
- Δ Privacy: CakePHP appends the full client IP to every logged exception with no way to switch it off, which quietly undid an installation's decision not to store IP addresses. It is now masked the same way the `store_ip_anonymized` setting masks it for postings.
- Δ Privacy: the maintenance page pulled a Google webfont over plain http — a third-party request and, on an https site, mixed content. No template makes an external request any more.
- Δ Standalone island pages carry a "back to the forum" link; contacting a member opens an overlay like contacting the owner does.
- Δ `fullBaseUrl` can be set from `SAITO_FULL_BASE_URL`. Behind a TLS-terminating proxy the application only sees plain http, which made upload thumbnails trigger mixed-content warnings and put insecure links into outgoing mail.

## [7.5.2] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.5.1...7.5.2)

### Changes

- ✓ Fix: `[embed]` content failed to render on hosts whose system CA store is a directory of certificates rather than a single bundle file (e.g. `/etc/ssl/certs` on FreeBSD). The SSRF-guarded embed client passed that path to curl's `CURLOPT_CAINFO`, which expects a file, so every fetch failed with `CURLE_SSL_CACERT_BADFILE` and fell back to a bare link. The client now uses `CURLOPT_CAPATH` for a directory and `CURLOPT_CAINFO` for a file.

## [7.5.1] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.5.0...7.5.1)

### Changes

- Δ Maintenance: restored PHPStan static analysis, which 7.5.0 had broken — an auto-formatted `@property` docblock in `UploadsController` pointed at a non-existent `\ImageUploader\Controller\UploadsTable`; it now names the real `\ImageUploader\Model\Table\UploadsTable`. Also dropped a stale PHPStan baseline entry left over from the 7.2.8 `login()` refactor.
- Δ Maintenance: cleared three DeepSource JavaScript findings in the upload views (function-expression instead of declaration, `Boolean()` instead of `!!`, optional catch binding). No behaviour change.

## [7.5.0] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.4.0...7.5.0)

### Changes

- ＋ Uploads: several images can now be uploaded at once. The file picker accepts multiple files and drag & drop takes the whole dropped set; the labels are pluralised so it is clear more than one file is allowed. All selected files are sent in one request and stored independently — a file that fails (unsupported type, duplicate, too large) is reported without aborting the rest of the batch. `UploadsController::add()` returns the stored files as a `data` array (per-file failures in `errors`).
- ＋ Uploads: several images can be embedded into one posting at once. Each upload card carries a selection checkbox; an "Insert selected" action bar drops every ticked upload into the posting together.

## [7.4.0] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.3.1...7.4.0)

### Changes

- Δ Maintenance: upgraded `embed/embed` from the legacy 3.x to 4.x (4.4.19), whose HTTP layer is PSR-18/PSR-17 based. The SSRF guard introduced in 7.3.0 is ported from the removed v3 dispatcher interface to a PSR-18 client (`SsrfGuardedClient`) injected via the embed Crawler, so every fetch — the page and each preview image — passes through the same per-hop host validation and IP-pinning. Same protection, same link fallback on a refused target. Adds transitive dependencies `ml/iri`, `ml/json-ld`, `oscarotero/html-parser`. **Deploying this release requires updating the vendor tree (`composer install --no-dev`), not only copying changed files.**
- ＋ Tests: an authorization-coverage tripwire (`AuthorizationCoverageTest`) enumerates every controller action and classifies how it is authorized (admin route / API / `allowUnauthenticated` / `authorizeAction` / inline permission check). Actions left open to any logged-in member must be listed explicitly; a new, unclassified member-open action fails the test, catching a forgotten authorization gate at review time.

## [7.3.1] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.3.0...7.3.1)

### Changes

- Δ Maintenance: `SsrfGuard` no longer silences `dns_get_record()`'s unresolvable-host warning with the `@` operator (DeepSource PHP-W1078); a scoped `set_error_handler()`/`restore_error_handler()` pair swallows exactly that warning instead. No behaviour change.

## [7.3.0] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.8...7.3.0)

### Changes

- ✓ Security (hardening): closed the server-side request forgery (SSRF) surface of the `[embed]` tag. The embed handler fetches the posted URL server-side; the previous guard validated only the initial host, so the fetch could still be steered at an internal target via DNS-rebinding or a public URL that 302-redirects to `http://169.254.169.254/` (cloud metadata) or an intranet host. Fetching now runs through an SSRF-guarded HTTP dispatcher that follows redirects manually, re-validates every hop, and pins the connection to the validated public IP (`CURLOPT_RESOLVE`), restricted to http/https. A refused target falls back to a plain link.
- ＋ Embeds: optional provider allowlist. Set `Saito.embedAllowedHosts` (app config) to a list of host names and only those hosts and their sub-domains are embedded; everything else falls back to a link. Empty (the default) keeps the previous behaviour — every public host is embeddable, with the guarded dispatcher as the SSRF control.

## [7.2.8] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.7...7.2.8)

### Changes

- Δ Maintenance: refactored `UsersController::login()` to cut its cyclomatic complexity (DeepSource PHP-R1006). The post-login redirect resolution (incl. the open-redirect guard) and the failed-login message logic are extracted into `_loginRedirectTarget()` and `_failedLoginMessage()`. No behaviour change.

## [7.2.7] - 2026-07-24

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.6...7.2.7)

### Changes

- ✓ Hardening: the `User` entity relied on the framework's fully-open mass-assignment default (`_accessible = ['*' => true]`), so any `patchEntity($user, $requestData)` that ever omitted a `fields` whitelist would let a user set any column on themselves — including their own role. All current call sites are safe, but the safety depended on every author remembering to whitelist. The privilege- and security-relevant columns (`user_type`, `activate_code`, `user_lock`, `id`) are now denied by default; the single permission-gated role change opts the field back in explicitly. An accidental bulk assignment can no longer escalate.
- ✓ Hardening: the login throttle and online-list counter read the client IP via `clientIp()`, which returns `REMOTE_ADDR` unless the request is told which reverse proxies to trust. Behind a proxy chain that made every request look like the proxy. Deployments that cannot rewrite `REMOTE_ADDR` at the web server (nginx `real_ip`) can now list their proxy IPs in `Configure` `Saito.trustedProxies`, and the real client is resolved from `X-Forwarded-For`. Default is empty, so behaviour is unchanged and no proxy is trusted unless explicitly configured.
- Δ Maintenance: bumped the `composer/composer` dev dependency 2.9.8 → 2.10.2 (clearing CVE-2026-59946/-59947/-59948) and the transitive Symfony components it pulls. Dev-only tooling; not shipped in the production tarball.

## [7.2.6] - 2026-07-23

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.5...7.2.6)

### Changes

- ✓ Performance: the RSS feeds took seconds to render on large forums. `find('feed')` sorted by `last_answer DESC, id ASC`; the mixed sort direction cannot be read off an index (InnoDB appends the primary key to every secondary index, so the `last_answer` index is physically `(last_answer, id)` and serves both keys only in the same direction). The database therefore filesorted every candidate row before applying `LIMIT 10`. The tie-break is now `id DESC`, which the index serves directly. Measured on a 680k-posting forum: `threads.rss` 4.5s → 0.01s (118292 rows sorted → 56 rows examined), `new.rss` 2.2s → 0.01s. Only the relative order of postings sharing the very same `last_answer` second changes.
- Δ Hardening: the `[code]` glyph-strip introduced in 7.2.5 now falls back to an empty string if `preg_replace` returns `null` on invalid UTF-8, instead of raising a `TypeError`. Input reaches the parser `h()`-sanitized, so this is defence in depth rather than a live path. Covered by an added positive-control test asserting that a normal code block with real HTML metacharacters still renders escaped and is not eaten by the strip.

## [7.2.5] - 2026-07-17

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.4...7.2.5)

### Changes

- ✓ Security (critical): `[code]` blocks could be used for stored XSS. The syntax highlighter (`tempest/highlight`, in use since 7.0.10) does not escape via `htmlspecialchars`; its `Escape::html()` reverse-maps the private-use placeholder glyphs `U+2776`–`U+277F` back into raw `<`, `>`, `"`, `&` *after* escaping, so a member who typed those glyphs inside `[code]` could smuggle live HTML (e.g. `<img onerror=…>` / `<script>`) into every viewer's page — and into the RSS feed. The parser now strips that glyph range from code content before highlighting. Affected 7.0.10–7.2.4.
- ✓ Security: the personalized RSS feed authenticator only matched the token substring anywhere in the path and then returned the user's full identity, so the read-only guarantee depended solely on the route table. It is now bound to exactly the two feed endpoints (`/feeds/f/<token>/postings/new|threads`), so a leaked token can never authenticate any other request.
- ✓ Security: admin-only help topics (marked `<!-- admin -->`) were hidden from the overview for non-admins but still readable by a direct id in `SaitoHelpsController::view()`. `view()` now enforces the admin check too.
- Δ Security/docs: the reference nginx config (`config/nginx/saito.conf.example`) now repeats the security headers (`nosniff`, `Referrer-Policy`, HSTS) inside the static-asset and `/useruploads/` location blocks — an nginx location with its own `add_header` does not inherit the server-level ones, so uploads (served straight from disk, and attacker-controlled) were missing `nosniff`. README documents the headers and an HSTS caveat.

## [7.2.4] - 2026-07-09

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.3...7.2.4)

### Changes

- ＋ Added a `Saito.headHtml` config option that injects operator-configured, trusted HTML into every page's `<head>` (e.g. a privacy-friendly analytics snippet). Set it per installation in `saito_config.php`, like `Saito.imprint`; empty by default.
- ✓ Security: dropped `image/svg+xml` from the uploader's allowed-types config. SVG was already rejected server-side by the upload controller, so this closes the latent config-level hole (a future upload path relying on the config alone would have accepted it).

## [7.2.3] - 2026-07-09

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.2...7.2.3)

### Changes

- ✓ Security: the raw-source posting view (`/entries/source/<id>`) emitted the stored subject/text through `HtmlHelper::tag()` without escaping — a stored-XSS sink for any registered user. It now escapes. Also stopped `PostingsController::add()` from accepting client-supplied `edited` / `edited_by` (forged edit attribution/time), matching the earlier `edit()` hardening.
- ✓ Security: bumped bundled jQuery 3.4 → 3.7.1 (fixes the DOM-manipulation XSS CVE-2020-11022 / CVE-2020-11023).
- ✓ Security: added `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin` response headers (alongside the existing `X-Frame-Options`).

## [7.2.2] - 2026-07-07

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.1...7.2.2)

### Changes

- ✓ Fixed a regression from the theme CSS purge: the PurgeCSS content globs missed the frontend `.html` templates, so classes used only there (e.g. the uploader's `imageUploader-add-veil` file-input overlay) were stripped, breaking the upload button. Added `frontend/src/**/*.html` to the scanned content.
- ＋ The central help overview lists admin-only topics (marked with an `<!-- admin -->` comment in the help file) only for admins; regular users no longer see them.
- Δ Docs: added the missing `docs/dev-setup.md`, refreshed `contributing.md` for the current toolchain (Cake 5 / PHP 8.4, Composer, Yarn/Grunt), and pointed the links at the Panxatony repo.
- Δ Frontend: resolved the DeepSource JS-0356 (unused variable) findings — dropped genuinely unused imports, trailing callback/override params and catch bindings (ES2019 optional catch), and marked the deliberate side-effect view instantiations with `skipcq`.
- Δ Frontend linting migrated from the deprecated TSLint to ESLint (`@typescript-eslint` 5, compatible with the TypeScript 3.x toolchain), keeping the previous rules (single quotes, max-classes-per-file), and typed away the remaining explicit `any`s ESLint flagged (JQuery/Marionette/event types; only the JSON:API deserialization boundary stays `any`, with a rule exception). Removed a stray 590&nbsp;KB `pipeline-failed.log` that had been committed by accident and gitignored `*.log`.
- Δ Housekeeping: removed obsolete files (the Travis CI config now that CI runs on GitHub Actions + GitLab, an unused Siege config, a stray CakePHP skeleton icon, an orphaned dev doc), refreshed the `bin/cake` console stubs to the Cake 5 skeleton, and fixed stale documentation links (CakePHP 3.x/4.x → 5.x doc links, upstream Schlaefer → Panxatony repo).

## [7.2.1] - 2026-07-06

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.2.0...7.2.1)

### Changes

- ✓ The help pages had been broken since the Cake 5 upgrade. Routes still used Cake 3 `:id` / `:lang` placeholders (treated as literal path segments in Cake 5), so `/help/…` returned a *missing controller* error; and `SaitoHelpHelper` passed the Cake 3 boolean full-base argument to `UrlHelper::build()`, so once routing was fixed the pages threw a 500. Both are ported to the Cake 5 APIs.
- ＋ Added a central help overview page (`/help`) that lists all topics, linked from the footer's resources section.
- ＋ Added a German translation of the BBCode help (previously English-only, so German users fell back to the English page).
- Δ Roughly halved the Local theme's stylesheets by purging unused (mostly Bootstrap) selectors (`theme.css`/`night.css` 163&nbsp;KB → 82&nbsp;KB), optimized the background SVGs, and dropped the legacy `woff` web-font fallbacks in favor of `woff2`. Removed a stray, served-but-unreferenced SCSS source map.
- Δ README: added DeepSource and GitHub Actions status badges.

## [7.2.0] - 2026-07-06

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.1.0...7.2.0)

### Changes

- Δ Live status updates now use server-sent events (SSE) where the browser supports them, falling back to polling otherwise. The status endpoint's content negotiation was broken (it tested for `text/event-streams` and so never matched the real `text/event-stream` Accept header, always returning 400 to an `EventSource`); it now serves a proper event-stream and disables proxy buffering (`X-Accel-Buffering: no`) so nginx flushes each update.
- ✓ Fixed several latent Cake 5 API regressions surfaced by framework-aware static analysis: the installer's database-connection check, the debug-mode mail transport override, the locale-specific template lookup, and the serialize/avatar database type hints.
- ✓ Sitemap: use `date()` instead of a miscased `Date()` call.
- Δ Developer tooling and frontend hygiene: added a framework-aware PHPStan setup with a CI static-analysis step; reduced explicit `any` in the frontend TypeScript from ~100 to ~25, converted string concatenations to template literals, and removed dead imports/variables and other anti-patterns flagged by static analysis.

## [7.1.0] - 2026-07-05

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.10...7.1.0)

### Changes

- ＋ Personalized RSS feeds: a logged-in user gets signed feed URLs (both the postings and the threads feed) that include the non-public categories they may read. The signature is derived from the app salt and the user's password hash, so it needs no schema change, exposes no password, is read-only, and stops working when the password changes. Feed readers authenticate by the URL alone (they cannot run the SPA login); anonymous feeds keep showing only public postings. The personal URLs are shown on the RSS-feeds page and in the user's own profile, each with a copy button and a one-click "subscribe" link (`feed:` scheme) for the installed reader. Feed readers are classified as bots for the online count, but a valid feed token still authenticates them, so a reader receives its personalized (non-public) content instead of only the public feed.

## [7.0.10] - 2026-07-05

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.9...7.0.10)

### Changes

- ✓ Fixes the user list ignoring the chosen sort column: sorting by type, online, registered or lock had no effect because the paginated finder forced `username` as the primary order, which always won. The finder no longer imposes an order, so the selected column sorts correctly (the default view stays username A–Z).
- ✓ Fixes the registration and confirmation pages always showing English: the "thanks for registering", confirmation-email-sent and "registration finished/failed" texts were hard-coded and bypassed translation. They now use i18n message keys (German translations included), so they follow the forum language.
- ✓ Fixes a forum message being delivered to the sender instead of the recipient when the "send me a copy" option was on (the default): the copy was a shallow clone of the mailer sharing its message object, so addressing the copy to the sender also re-addressed the main mail. The copy now uses its own message.

## [7.0.9] - 2026-07-04

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.8...7.0.9)

### Changes

- Δ Deleting a posting now goes through the JWT API (`DELETE /api/v2/postings/<id>`) instead of a GET redirect, so a single confirmation deletes cleanly

#### Security

- ✓ Adds the `DELETE /api/v2/postings/<id>` endpoint with the same two-layer authorization as the web delete (general posting-delete permission + per-category permission), and switches the SPA to it — completing the CSRF hardening of posting deletion begun in 7.0.8

## [7.0.8] - 2026-07-04

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.7...7.0.8)

### Changes

- Δ Replaces the abandoned GeSHi syntax highlighter with the maintained `tempest/highlight`; `[code]` blocks are highlighted server-side with self-contained inline styles (no external stylesheet)
- ✓ Fixes the garbled subject of the contact "copy of your message" mail (a non-ASCII subject was double-encoded into a raw `=?UTF-8?…?=`)
- ✓ Feed-reader autodiscovery variants (e.g. `…/new.rss/feed`) now return 404 instead of redirecting to the login page
- ＋ Recognises the Reeder app and CFNetwork clients (and a broader set of feed readers / HTTP libraries) as bots/crawlers in the online list

#### Security

- ✓ Fixes a stored XSS in the posting-edit API: the server-managed `edited_by`/`edited` fields were client-settable and rendered unescaped, allowing an account/forum takeover; they are now server-set and the output is escaped
- ✓ Requires a POST and a confirmation to delete a posting: deletion was reachable via a plain `GET /entries/delete/<id>` with no CSRF token, so a lured moderator could have a thread destroyed cross-site
- ✓ Narrows the API CSRF-exemption to the `/api/v2/` prefix (it matched the substring `/api/` anywhere in the path, which a fallback route could exploit)

## [7.0.7] - 2026-07-04

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.6...7.0.7)

### Changes

- ＋ The online-users list now shows bots/crawlers separately from human guests ("… N guests, M bots/crawlers")
- Δ Counts anonymous guests per client IP instead of per session, so cookieless clients (bots, crawlers, feed readers) that open a new session on every request no longer inflate the guest count
- ＋ Broadens the built-in bot user-agent list (AI crawlers, HTTP client libraries, feed readers, link-preview fetchers, uptime monitors, headless automation) and makes it extensible per installation via the `Saito.bots` config
- ✓ Fixes bot detection in the online tracker: it relied on a request detector that is not reliably registered at that point and returned false even for obvious crawlers, so bots were miscounted as guests
- ✓ Fixes site-relative URLs (smilies, internal links, relative images) in the RSS feed body not being made absolute for feed readers

## [7.0.6] - 2026-07-02

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.5...7.0.6)

### Changes

- ✓ Fixes uploaded images not showing in RSS readers: the feed body was rendered in text mode, which collapsed an uploaded `[img]` to its bare filename; it is now rendered as HTML with a full-base `/useruploads/` URL
- ✓ Fixes contact-form mail not arriving: the mail was sent `From` the sender's own address (a member's or visitor's external mailbox), which failed SPF/DMARC and was silently spam-filtered; it is now sent `From` the forum address with the sender in `Reply-To`

#### Security

- ✓ No longer accepts the API JWT via a `?token=` query parameter (bearer tokens in URLs leak into logs, history and `Referer` headers)
- ✓ Throttles failed logins per client (brute-force / credential-stuffing protection)
- ✓ Enforces the user-list sort allow-list (the `sortWhitelist` paginate option was renamed to `sortableFields` in CakePHP 5 and had silently become a no-op)
- ✓ Hardens the CommonMark renderer (escape raw HTML, drop unsafe link schemes)
- ✓ Validates the field name in `AppTable::increment()` against the table's real columns before it is used in raw SQL

#### CI

- ＋ Adds a tag-driven GitHub Actions release pipeline (test → build tarball → publish a GitHub Release with the tarball + checksum)

## [7.0.5] - 2026-07-02

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.4...7.0.5)

### Changes

#### Security

- ✓ Fixes arbitrary file read via a forged upload `tmp_name`: image and avatar uploads accepted a client-supplied file path, letting an authenticated member copy any server-readable file into the public uploads directory (and retrieve it) — only genuine PSR-7 uploads are now accepted
- ✓ Fixes an image "decompression bomb" denial-of-service: uploads are rejected above a pixel-resolution cap (default 40 MP) using a header-only check, before the image is ever decoded — mirroring the existing avatar limit
- ✓ Fixes stored XSS via `[img]javascript:…`: a top-level image wrapped its URL in a link whose `href` was not scheme-validated
- ✓ Fixes the `javascript:`/`data:` URL blocklist being bypassable by hiding a tab or newline inside the scheme (`jav<TAB>ascript:`), which browsers strip before executing the URL
- ✓ Hardens the `Saito-JWT` bearer-token cookie (and the `lastRefresh`/`Saito-Read` cookies) with the `Secure` and `SameSite=Lax` flags
- ✓ Hardens the URL and e-mail autolink patterns against regular-expression denial-of-service (ReDoS)

## [7.0.4] - 2026-06-30

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.2...7.0.4)

### Changes

- ✓ Fixes the "remember me" login not surviving a browser restart: under CakePHP 5 the persistent auth-cookie was emitted without an expiry (a session cookie), logging users out daily. The cookie is now persistent again and carries the `HttpOnly`, `Secure` and `SameSite=Lax` flags.

## [7.0.3] - 2026-06-29

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.2...7.0.3)

Post-cutover fixes shipped after the production upgrade to Saito 7 (these went out under the 7.0.2 version string; grouped here as 7.0.3).

### Changes

- ✓ Fixes new postings not appearing live in the threaded view — the lowercase `entries/threadline` URL now resolves to the `threadLine` action (CakePHP 5 matches action names case-sensitively)
- ✓ Fixes the online-users widget crashing the page when a guest has an `useronline` row (rows without a user are skipped)
- ✓ Fixes missing smilies — ships the smilies icon-font in the Local theme webroot and loads the Bota base-theme plugin so its webroot assets are served
- ✓ Fixes RSS feeds to emit `application/rss+xml` with absolute item URLs
- ✓ Fixes moderators being unable to pin/lock threads they may not edit
- ✓ Fixes the posting-actions dropdown being clipped on short threaded postings
- ＋ Adds a modern favicon/PWA head to the Local theme (SVG icon, apple-touch-icon, web manifest)

## [7.0.2] - 2026-06-28

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.1...7.0.2)

### Changes

#### Security

- ✓ Fixes stored XSS via `[iframe]` BBCode attribute injection (only an allow-list of iframe attributes is rendered; event-handler attributes are dropped)
- ✓ Fixes server-side request forgery (SSRF) via `[embed]` BBCode: server-side URL fetches are restricted to public http(s) hosts and can no longer reach loopback/private/cloud-metadata addresses
- ✓ Fixes stored XSS via uploaded SVG images (SVG removed from the upload allow-list)
- ✓ Fixes open-redirect after login (redirect target restricted to local paths)
- ✓ Hardens activation-code entropy (cryptographically secure over the full integer range)
- ✓ Hardens legacy password-hash comparison against timing and type-juggling attacks (`hash_equals`)
- ✓ Hardens the help-page route against path traversal
- ✓ Hardens admin forms against primary-key mass-assignment and stored data against object injection (`unserialize` allow-list)
- Δ Updates cakephp/authentication to 3.3.6 (CVE-2026-55590, open-redirect via backslash bypass)
- Δ Updates firebase/php-jwt to 7.1.0
- − Removes the vulnerable GeSHi `contrib/cssgen.php` (CVE-2025-2123) on every composer install/update

#### Other

- Δ Converts the remaining legacy utf8mb3 tables to utf8mb4
- ＋ Serves the imprint ("Impressum") as a Saito page with per-environment config content

## [7.0.1] - 2026-06-04

- [Full commit-log](https://github.com/Panxatony/Saito/compare/7.0.0...7.0.1)

### Changes

- Δ Declares PHP >= 8.4 in composer to match the resolved dependencies
- Δ Updates firebase/php-jwt to ^7 (CVE-2025-45769)
- ✓ Fixes several admin pages 500-ing under CakePHP 5 (smilies and smiley-codes pagination/sort)
- ✓ Fixes JWT-cookie / 401 handling by restoring the component shutdown callbacks under CakePHP 5
- ✓ Fixes the profile "Joined" date format
- Δ Serves the admin DataTables assets from a node_modules-free path
- Δ Trims whitespace from e-mail-address settings on save
- Δ Silences PHP 8.4 engine deprecations in the production log
- ＋ Adds CI dependency- and static-security scanning

## [7.0.0] - 2026-05-28

- [Full commit-log](https://github.com/Panxatony/Saito/compare/6.0.0...7.0.0)

The "Saito 7" release line. Existing 5.7.x installations can upgrade straight to 7.0.0.

### Changes

- Δ Upgrades the framework from CakePHP 3.10 to **CakePHP 5** and requires **PHP 8.4**
- Δ Migrates from `SecurityComponent` to `FormProtectionComponent`
- Δ Renames plugins to the CakePHP 5.3 `<Name>Plugin` convention
- ✓ Resolves framework and PHP deprecations throughout (events, behaviors, finders, result sets, entities)
- ✓ Ports the JSON:API exception renderer to CakePHP 5
- ✓ Fixes admin pages 500-ing from the renamed cache config under CakePHP 5
- Internal: full test-suite green on CakePHP 5 (test-DB auto-migration, `Email` → `Mailer` port, `Shell` → `Command` port)

## [6.0.0] - 2026-05-24

- [Full commit-log](https://github.com/Schlaefer/Saito/compare/5.7.1...6.0.0)
- [Download release-zip](https://github.com/Schlaefer/Saito/releases/download/6.0.0/saito-release-master-6.0.0.zip)

### Changes

- ＋ Adds honeypot and timing protection against bots during registration
- Δ Stricter content hiding for ignored users
- Δ Updates library for embedding 3rd-party content (embed/embed 3.x)
- Δ Updates league/commonmark from 1.x to 2.x
- Δ Raises minimum PHP requirement from 7.2 to 8.0
- − Removes davidyell/proffer image upload library (replaced with built-in AvatarBehavior)
- − Removes siezi/cakephp-simple-captcha
- Internal code changes:
  - Δ Updates CakePHP from 3.8 to 3.10
  - Δ Updates PHPUnit from 6 to 9
  - Δ Updates PHPStan from 0.11 to 1.x
  - Δ PHP 8.x compatibility fixes throughout the codebase

## [5.7.1] - 2020-08-07

- [Full commit-log](https://github.com/Schlaefer/Saito/compare/5.7.0...5.7.1)
- [Download release-zip](https://github.com/Schlaefer/Saito/releases/download/5.7.1/saito-release-master-5.7.1.zip)

### Changes

- ＋ Adds .webp images to allowed upload mime-types #372
- ＋ Adds required PHP extensions to readme.md
- ✓ Fixes Thread Collpase user setting not working in 5.x #371
- ✓ Fixes URL with paranthesis pair omits closing on autolink #373
- ✓ Fixes delete category and move existing posts
- ✓ Fixes missing l10n de merge thread
- ✓ Fixes text-field to submit-button alignment on simple search
- ✓ Fixes spoiler tags are not revealed reliably
- ✓ Fixes image compression is to aggressive #374

## [5.7.0] - 2020-03-25

- [Full commit-log](https://github.com/Schlaefer/Saito/compare/5.6.0...5.7.0)
- [Download release-zip](https://github.com/Schlaefer/Saito/releases/download/5.7.0/saito-release-master-5.7.0.zip)

### Changes

- ＋ Adds permission `saito.core.user.lastLogin.view` to see a user's last login (defaults to admin)
- ＋ Emit event `saito.core.user.activate.after` after user activation
- ＋ Emit event `saito.core.user.register.after` after user registration
- ＋ Adds plugin "Local" for local customization
- ✓ Improves wrapping of long words and links in posting #365
- ✓ Fixes localization in advanced search #364
- ✓ Missing navigation links in search head
- ✓ Internal error viewing posting where the thread starter was deleted
- ✓ Fixes user-blocking not working
- Δ Set default period for advanced search to the last 12 months #354
- Layout
  - ＋ Adds navigation to Mix-view on entries/view thread overview #370
  - Δ Change thread-tool menu on entries/index #367
  - Δ Switches Bota-theme night/day button icon #366
  - Δ Change avatar presentation entries/view
- Uploader
  - ＋ Default target-size for resizing images is configurable
  - Δ Default target-size for resizing images is reduced from 820 kB to 450 kB
- Internal code changes
  - ＋ Tests PHP 7.4 on travis-ci
  - ＋ Run phpcbf and phpcs with multiple threads
  - ＋ Improve error display before settings are loaded
  - ✓ Fixes phpstan deprecated warnings
  - Δ Improves scanning of JS localizaton strings
  - Δ Updates core JS-, CSS- and PHP-libraries
  - Δ Updates travis-ci environment from trusty to bionic
  - Δ Consolidates PHP event names updates documentation

### Update Notes

Plugins subscribing to events may have to update event-names. See *docs/dev-hooks.md* for available events.

The plugin Local in "plugins/local" allows extending the forum in a CakePHP fashion without running composer.

## [5.6.0] - 2020-01-03

- [Full commit-log](https://github.com/Schlaefer/Saito/compare/5.5.0...5.6.0)
- [Download release-zip](https://github.com/Schlaefer/Saito/releases/download/5.6.0/saito-release-master-5.6.0.zip)

### Changes

- ＋ Adds permission `saito.core.posting.solves.set` for marking a posting as solution/helpful (defaults to thread creator).
- ＋ Improves compatibility with PHP 7.3
- ＋ Improves browser detection for changes in the Bota theme CSS
- ＋ Improves logging of unauthorized access
- ✓ Deleting a bookmark creates an empty area above the bookmarks
- ✓ User roles with ID greater than 3 can't be assigned to category access control
- ✓ Fixes link to default favicon if installed in subdirectory
- Δ Adds "Saito" prefix to CSRF-cookie name
- Δ Moves layout for viewing a posting and answering from center to the left
- Δ Updates Saito default favicon
- − Removes visiblity description for category in category-title hover
- Search:
  - ✓ Internal error on simple search when results are sorted by rank
  - ✓ Internal error if search term contains multiple whitespaces
- Improves dark theme:
  - ✓ Drop down menus aren't styled
  - ✓ Code inserts aren't styled
  - Δ Exchanges dark and light distinction between background and form areas
  - Δ Darkens border and dividiers
- Uploader:
  - ＋ Adds filter options
  - ＋ Performance improvements for users with many (100+) uploads
  - ＋ Adds permission `saito.plugin.uploader.view` for viewing uploads (defaults to upload owner and group `admin`).
  - ＋ Adds permission `saito.plugin.uploader.add` for uploading new files (defaults to profile owner).
  - ＋ Adds permission `saito.plugin.uploader.delete` for deleting uploads (defaults to upload owner and group `admin`).
  - ＋ Adds "audio/ogg" and "audio/opus" to default allowed mime-types
  - ✓ Wrong error message is shown if no file was received on the server
  - Δ Layout improvements
- Internal code changes:
  - ＋ Minor changes for improved theming support
  - Δ Refactors creation, update and validation of postings
  - Δ Updates PHP and Javascript libraries
  - Δ Entries::Table throws RecordNotFoundException instead of returning null
  - Δ Update Apcu version in docker container to 5.1.18
  - Δ Drafts for new threads are stored with a `pid` of `0` instead of `NULL`
  - − Removes SaitoValidationProvider::validateAssoc with CakePHP build-in facility
  - − Removes abandonded Selenium test files

## [5.5.0] - 2019-11-16

- [Full commit-log](https://github.com/Schlaefer/Saito/compare/5.4.1...5.5.0)
- [Download release-zip](https://github.com/Schlaefer/Saito/releases/download/5.5.0/saito-release-master-5.5.0.zip)


### Changes

- ＋ Adds `CHANGELOG.md` to keep track of changes
- ＋ Rewritten and expanded permission system:
  - ＋ New, more fine grained permissions
  - ＋ Permissions are configurable
  - ＋ New role "Owner"
- Uploader:
  - ＋ Shows progress-bar when uploading a file
  - ＋ Shows speed, time remaining and file size when uploading a file
  - ＋ Adds button for canceling the current file-upload
  - ＋ Cancel a running upload if the upload-dialog is closed
  - ＋ Checks that file with same name isn't uploaded before upload starts
  - ＋ Improved responsive layout
- ✓ Fixes user's can't log-out if forum is installed in a subdirectory
- ✓ Fixes login redirect issues if forum is installed in a subdirecotry
- Δ Improves performance of background task runner
- Internal code changes:
  - Δ Increases phpstan static code analysis from level 3 to 4
  - Δ Changes passing of current-user throughout the app
  - Δ Updates aura/di from 2.x to 4.x

### Update Notes

#### Extended Permission System

Saito 5.0.0 introduced a new permission system which was rewritten and considerably extended in this release.

##### Configuration

The configuration is exposed at `config/permissions.php` now.

Want to allow moderators to contact a user no matter the user's contact-settings? You can do that. Want to disable new registrations? You can do that. Want to allow users to change their email-address? You can do that. And a lot more.

Permissions are intended to offer flexibility by tweaking the exiting forum behavior to your needs. While possible it is not recommended to start a brand new permission-configuration from scratch.

If you make changes in `config/permissions.php` don't forget to carry them over if you update to new releases in the future.

##### The Owner Account

This update introduces a new user-role *Owner*. The following changes apply to the default configuration:

- On new installations the first account created is an Owner instead of an Administrator
- The Owner lives "above" the Administrator inheriting all their rights
- The "lower" roles are not allowed to change the role, block or delete an Owner
- Only an Owner can promote (or demote) a user to Administrator or Owner

The update is not going to change accounts on existing installations and, because this is the whole point, it isn't possible to promote an account to Owner from an Administrator account. To promote an user on an existing installation execute manually in the database:

```SQL
UPDATE users SET user_type='owner' WHERE username='TheUserName';
```

##### "Lock User" Setting

The setting for enabling user-locking is removed from the admin-backend and controlled by permissions now. The default behavior is unchanged: moderators may lock, locking status is visible to every user.

## [5.4.1] - 2019-10-20

### Noteworthy Changes

- ✓ Changing a user name isn't reflected in search results or "edited by" information
- ✓ Improves reliability of executing background maintenance tasks
- ✓ Fixes internal error caused by read-postings garbage collection for registered users
- Δ Improved performance of read-postings garbage collection for registered users

### Update Notes

Don't miss to add:

- [the new "long" cache configuration](https://github.com/Schlaefer/Saito/blob/7d085ea43598cd3220438d7ca6a5169cae2eaf6c/config/app.php#L170) in `config/app.php`
- [the new "logInfo" configuration](https://github.com/Schlaefer/Saito/blob/7d085ea43598cd3220438d7ca6a5169cae2eaf6c/config/saito_config.php#L95) in `config/saito_config.php`

[Full change-log](https://github.com/Schlaefer/Saito/compare/5.4.0...5.4.1)

## [5.4.0] - 2019-10-12

### Noteworthy Changes

- ＋ Inserts an additional whitespace after closing BBCode tag #360
- ＋ Improves mime-type detection in Uploader
  - ＋ Workaround for [issue with .mp3 files on Chromium-derivates](https://bugs.chromium.org/p/chromium/issues/detail?id=227004)
  - ＋ Workaround for .mp4 videos identifying as `application/octet-stream`
- ✓ Fixes issues where errors-messages were displayed without theme
- ✓ Fixes issues where an API-error didn't result in a proper error-response
- Code improvement:
  - ＋ Increases TypeScript check to "strict" #355
  - Δ Migrates more Javascript code to TypeScript fixing some minor bugs on the way.
  - Δ Migrates user-authentication from [AuthComponent](https://book.cakephp.org/3.0/en/controllers/components/authentication.html) (deprecated in CakePHP 4) to [newer and future-proof Authenticaton-plugin](https://github.com/cakephp/authentication) #361

### Update Notes

[Full change-log](https://github.com/Schlaefer/Saito/compare/5.3.3...5.4.0)

## [5.3.3] - 2019-09-21

### Noteworthy Changes

- ✓ Fixes issues that prevent editing a posting as moderator

### Update Notes

[Full change-log](https://github.com/Schlaefer/Saito/compare/5.3.2...5.3.3)

## [5.3.2] - 2019-09-06

### Noteworthy Changes

- Δ Smiley menu is placed below menu buttons in posting form #349

### Update Notes

[Full change-log](https://github.com/Schlaefer/Saito/compare/5.3.1...5.3.2)

## [5.3.1] - 2019-09-01

### Noteworthy Changes

- ✓ Category order of select input in posting form is wrong #345
- ✓ Force browser to load an updated language .json file #346
- ✓ 5.3 updater fails on pre 5.2 installations if uploads without title exist #347
- ✓ Editing a posting doesn't trigger an autoresize on the textarea #348

### Update Notes

[Full change-log](https://github.com/Schlaefer/Saito/compare/5.3.0...5.3.1)

## [5.3.0] - 2019-08-30

### Noteworthy Changes

#### From the Changelog

- ＋ Send posting before moving on from posting form #338
- ＋ Save drafts while composing a new posting
- ＋ Browser warns the user before navigating away from a posting form with input
- ＋ Favicon-indicator shows number of unread postings on background tabs with autoreload #95
- ＋ New setting `answeringAutoSelectCategory` to control category-selection in posting-form
- − Removes support for embedding new Flash videos (`<object>...`) #326
- ✓ Uploading PNG images allows double-uploads #343
- ✓ Fixes several bugs causing Internal Error issues
- ✓ Don't autolink file:// URIs #341
- ✓ Internal posting-hashtag in parenthesis isn't linked #337
- Δ Changes the default DB engine for the `entries` table from MyISAM to InnoDB #322
- Δ Keeping track of online users is more accurate while requiring less resources
- Δ Font files for default theme are served locally instead from Google (everything is served locally now)
- Δ Disables Security component on login #339
- Δ PHP code maintenance
  - Δ Improves code quality so it passes phpstan static code analysis on level 3 (was 1)
  - Δ Declares all `src/` and `plugins/` PHP files as strict
  - Δ Refactors handling of current user's state
- Δ Core library updates (CakePHP 3.8, TypeScript 3)

#### Never Lose A Posting Again

5.3.0 refactors and improves a lot of code including keeping track of the current user and posting a new entry. Both touches important functionality and our oldest code paths (reaching back even before the `git init` of this repository). They accumulated a lot of cruft over the years.

This was also the occasion to introduce exciting new features:

In the past sending the posting-form was mainly a simple HTTP POST request. If something went wrong the content was gone. The browser's back button wasn't much of a help. From now on a posting is sent in the background before leaving the posting-form. If there's a server error or a connection problem the user is notified and won't lose the posting staring at a blank page.

While composing a new posting the content is continuously saved as a draft in the background. On the chance that something is going wrong while composing a posting the draft is restored when the user opens the posting form again.

### Update Notes

#### New Setting `answeringAutoSelectCategory`

There's a new setting `answeringAutoSelectCategory` in `config/saito_config.php`.  It allows to select a default category for new postings.

If `true` the first available category (by category-order and accessibility according to user rights) is preselected as default category in the posting form. If `false` the user is forced to select a category.

Default: `false` (same behavior as in previous versions).

#### Changing Entries Table from MyISAM to InnoDB #332

This update changes the last and biggest table - containing all postings - from MyISAM to the modern InnoDB database-engine. According to my benchmarks this switch shouldn't impose a major performance impact anymore.

The updater is going to convert the table automatically, but be aware that your PHP-script runtime is limited on a shared-hoster. The conversion may take several minutes depending on the number of postings and exceed that period. So you might end up sitting in front of a blank page wondering what happened. If your forum contains more than 100.000 postings I recommend converting the table manually before starting the updater. Execute e.g. in phpMyAdmin:

```sql
ALTER TABLE entries ENGINE=InnoDB;
```

As always: Backup your database before performing an update.

[Full change-log](https://github.com/Schlaefer/Saito/compare/5.2.1...5.3.0)

## [5.2.1] - 2019-07-01

### Noteworthy Changes

- ✓ Deleting an user doesn't properly clean-up the user's postings and leaves the entries table in a dirty state

### Update Notes

*An update to 5.2.1 is highly recommended.* - Don't delete an user on version 5.0.0+ before updating to 5.2.1. A manual DB fix is possible and not very complicated, open an issue if you ran into this issue and require assistance.

For a quick in-place upgrade just update `src/Model/Table/EntriesTable.php`  and ` src/Lib/version.php`.

[Full change-log](https://github.com/Schlaefer/Saito/compare/5.2.0...5.2.1)

## [5.2.0] - 2019-07-13

### Noteworthy Changes

5.2 is a feature update with a considerably enhanced uploader and quality of life improvements for user management.

- ＋ Image uploader is extended to a general purpose uploader #325
- ＋ Privileged users may see the user-account activation status in user-profile and user-list
- ＋ Privileged users may contact normal users even if the user has messaging disabled #336
- ＋ Privileged users may directly set a user's password #108
- ＋ Domain info after link takes Public Suffix List into account
- ✓ RSS feed item doesn't show username
- ✓ RSS feed item doesn't show correct date
- ✓ Bootstrap toasts are themed bright in night-theme
- ✓ Bootstrap toasts are placed beneath modal dialogs
- ✓ Domain info after link breaks on URLs with special chars
- ✓ i18n for deleting categories including German l10n
- Δ Increases font size of the default theme
- Δ Updates marionette.js to version 4

### Update Notes

#### Uploader

Upload-settings in the admin panel have been removed. Write down your settings (max number of uploads per user) before updating. The Uploader is configured in [`config/saito_config.php`](https://github.com/Schlaefer/Saito/blob/5.2.0/config/saito_config.php#L95) now. Individual file-types and file-size per type are configurable. The default settings allow uploading of common Internet media formats (images, audio , video and text-files).

#### Access-control

- ＋ `saito.core.user.activate` - See activation status (default-groups: admin)
- ＋ `saito.core.user.password.set` - Change user password (default-groups: admin)
- Δ  `saito.core.user.view.contact` becomes `saito.core.user.contact` - Allows viewing contact data and messaging via contact-form (default-groups: admin)

I forgot to mention the access-control permissions in the 5.0.0 release notes, didn't I? As I said: version 5 was a big update and most of it happened many moons ago. You'll find the meat [here](https://github.com/Schlaefer/Saito/blob/5.2.0/src/Lib/Saito/User/Permission.php). While the forum is still shipping and tested with the default administrator, moderator, user, and anonymous groups, it is possible to configure those groups - or create your own if you feel adventurous.


[Full change-log](https://github.com/Schlaefer/Saito/compare/5.1.0...5.2.0)

## [5.1.0] - 2019-06-13

### Noteworthy Changes

5.1.0 is a bugfix and maintenance release.

- ＋ bumps minimum required PHP Version from 7.1 to 7.2+
- ✓ Creating bookmarks not working on new 5.0.0 installation #334
- Δ Updating removes database compatibility to Saito 4.10 #323 #324
- Δ Default database charset and collation for new installation changes from utf8 to utf8mb4 #333
- Δ Rewritten installer #335
- Δ Refactored user-blocking internals
- Δ Updates libraries (esp. CakePHP 3.7 and Bootstrap 4.3)

### Update Notes

Utf8mb4 is required for full Emoji-support. Existing installation have to update the table and columns to utf8mb4 manually. If you're fine without Emoji support just set `encoding => 'utf8'` as connection parameter for the dabase in `config/app.php` and everything is going to work as before.

See [full change-log](https://github.com/Schlaefer/Saito/compare/5.0.0...5.1.0) or the [milestone](https://github.com/Schlaefer/Saito/issues?utf8=%E2%9C%93&q=milestone%3A5.1.0+)

## [5.0.0] - 2019-06-10

### What's new

Hello!

Saito 5 is big rewrite of major parts of the forum. 90% of the work took place in late 2015, but I burned out at the end, so it didn't make it out of the door. The remaining 9.9% were done in the first half of 2018. 2019 sees the release – finally.

On the backend the update from CakePHP 2.x to CakePHP 3.x is the most noteworthy, which was a considerable effort.

The frontend-stack moved from bower, RequireJS, Marionette 1.x and Javascript over to yarn, webpack, Marionette 3.x with parts starting to migrate to Typescript. The UI is based on Bootstrap now, which should offer a more accessible theming-environment.

Overall there's a stronger separation between frontend and backend. The major theme is that the PHP-backend is going to provide a new JSON based API with JWT authentication which is accessed by a independent frontend JS application. The rewritten image-uploader and bookmarks features being the first incarnations of this transition.

Future-proving the code-base was the main goal, but there are also feature changes.

Users are able to set an avatar image now. The layout is better optimised for mobile devices. Category-access-rights are more fine grained. The image-uploader is rewritten and improved (auto-rotate images by EXIF-metadata, remove medata, compress images, thumbnails on index page). The posting form is a custom implementation, which allows more flexibility (sub-paragraph citations, better dialogs for inserting content and esp. for smilies). Embedding of rich 3rd-party content doesn't rely on an external provider anymore.

On the other hand less popular features didn't made the transition: Shoutbox, community-map, separate mobile-version, email-notifications on answers, admin-stats, …

### Update

#### Migrating from 4.x

Saito 5.0.0 requires PHP 7.1 but is able to run on the same DB as Saito 4.10 (meaning that the DB updates for Saito 5 don't break 4.10). This allows you to move from PHP 5 to 7 with 4.10 and gently switch to Saito 5.

Saito 5 includes an automated database updater, so no more manually updating the DB with raw SQL commands. *Yeah!* **But** ... I can only hope that you applied the manual steps of the past by the letter. I also assume that your database structure is in the same state as a vanilla 4.10 installation. The automated updater may fail if it isn't ...

***Please do a database-backup before updating!*** – *This is not a drill!*

[The database connection](https://book.cakephp.org/3.0/en/orm/database-basics.html#configuration)  is set in `config/app.php`. Enter your existing security salt there too.

There's no support for table prefixes anymore. If prefixes were used in the past rename the tables to an unprefixed version.

#### Theming

The new default theme "Bota" replaces "Paz". It is implemented as a [CakePHP 3 theme plugin](https://book.cakephp.org/3.0/en/views/themes.html) and lives in `plugins/Bota`. The UI is implemented as [Bootstrap 4](https://getbootstrap.com/docs/4.1/getting-started/introduction/) theme.

To start your own theme I recommend using SASS and referencing and customizing the default theme.

```
// e.g. in "plugins/YourTheme/webroot/css/src/theme.scss"
// set YourTheme in config/saito_config.php

//// Change Bootstrap variables

$body-color: #222;
...

//// Include the main theme which will pick up the Bootstrap variable values

@import "../../../../../plugins/Bota/webroot/css/src/theme";

//// Additional customizations tweaking the default theme

@import "_your_customizations.scss";

body {
  // more customizations
}
```

Otherwise you have to bring your own Bootstrap-theme and layout additional forum properties from scratch.

## [4.10.1] - 2019-06-10

### What's new

- ✓ Fixes incorrect table setup by the installer

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.10.0...4.10.1)

## [4.10.0] - 2019-05-09

### What's new
- ＋ PHP 7.1 compatibility
- ＋ Prepares update to Saito 5
- ＋ adds new API-endpoint `users/online`
- ✓ fixes smaller issues on MariaDB
- ✓ [mobile] fixes smilies und BBCode in Shoutbox
- ✓ [mobile] fixes issues on login and logout
- ✓ [mobile] fixes bugs when editing a posting starting a thread
- ✓ [mobile] fixes issues when trying to view non-existing threads
- Δ if embed.ly is disabled existing `[embed]`-tags will present a HTML-link

```sql
ALTER TABLE `user_blocks`CHANGE `by` `blocked_by_user_id` int(11) unsigned NULL DEFAULT NULL;
RENAME TABLE `user_read` TO `user_reads`;

ALTER TABLE `settings` ADD `id` INT(11)  NOT NULL  AUTO_INCREMENT  PRIMARY KEY FIRST;
INSERT INTO `settings` (`name`, `value`) VALUES ('db_version', '4.10.0');

ALTER TABLE `users` CHANGE `user_signatures_images_hide` `user_signatures_images_hide` TINYINT(1)  NOT NULL  DEFAULT '0';
```

It is possible your settings table already has an "id"-column. In that case make sure it's auto-increment and add a "db_version" key with value "4.10.0" manually.

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.8.0...4.10.0)

## [4.9.0]

Unreleased and rolled into 4.10.0.

## [4.8.0] - 2015-11-18

- \+ show remaining chars for subject #312
- \+ set default format for youtube video fallback from 4:3 to 16:9 #316
- \+ add [quote] BBCode tag #317
- \+ show PHP-info in admin panel
- ✓ [mobile] improved reliability when starting the mobile app
- ✓ [mobile] app data isn't updated on Internet Explorer
- ✓ improve [float] BBCode-tag
- ✓ improve embed.ly embedding
- Δ relax CSRF protection when creating new postings
- Δ update CakePHP from 2.6.7 to 2.6.12

## [4.7.5] - 2015-11-15

### What's new
- ✓ caches were not cleared out on certain operations
- ✓ hide other users signature images not working #315
- ✓ accession check on categories not always applied
- ✓ improved localization
- Δ update CakePHP from 2.6.3 to 2.6.7

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.7.4...4.7.5)

## [4.7.4] - 2015-03-21

### What's new
- ✓ don't include complete web pages with embedly #314
- ✓ posting in mobile app not working #313
- Δ update to CakePHP 2.6.3

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.7.3...4.7.4)

## [4.7.3] - 2015-02-28

### What's new
- ＋ log user-agent in `saito-<*>.log` files
- ✓ maps where not working because of API change on mapquest.com
- ✓ user is not shown in userlist-slidetab #307
- ✓ don't show ?mar in URL for non-aMAR users
- ✓ improves german localisation
- Δ updates CakePHP from 2.6.0 to 2.6.2
- Δ minor refactoring

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.7.2...4.7.3)

## [4.7.2] - 2015-01-10

### What's new
- ✓ HTML-entities created by BBCode-parser followed by a parenthesis trigger wink smiley #311

Minor code refactoring.

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.7.1...4.7.2)

## [4.7.1] - 2015-01-04

### What's new
- ✓ cite button in answering form doesn't insert text #308 (was bug in flattr-plugin)
- Δ Update CakePHP to 2.6.0 #309
- Δ Update jQuery to 2.1.3 #310

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.7.0...4.7.1)

## [4.7.0] - 2014-12-13

### What's new
- ＋ Set sort order for non-logged-in users to last-answer #304
- ＋ add drop shadow to simley-popup in entries/add #303
- ✓ fix bullet CSS in bookmark index #298
- ✓ fix badges (via plugin) margin #301
- ✓ fix default citation mark in bbcode doc #302
- ✓ fix timing in test case #305
- Δ rename table column Smilies.order to Smilies.sort #300
- Δ rename table column Entry.category to Entry.categories_id #299

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.6.0...4.7.0)

### Migration Notes

<span class="label label-warning">_Note:_</span> If you use a table prefix you have to prepend it to the table name.

``` mysql
ALTER TABLE `entries` CHANGE `category` `category_id` INT(11)  NOT NULL  DEFAULT '0';
ALTER TABLE `smilies` CHANGE `order` `sort` INT(4)  NOT NULL  DEFAULT '0';
```

## [4.5.0] - 2014-11-08

### What's new
- ✓ fixes an issue when composer wasn't able to find the pear CakePHP package
- ✓ fixes path issue when installing on MS Windows
- ✓ fixes PostgreSQL support
- Δ refactors BBCode-renderer into a plugin (included and activated by default)
  - ✓ fixes @Username is not linked before linebreak
  - \- removes [u] underline BBCode tag
  - \- removes `.c-bbcode-<#>` CSS-classes
- Δ CSS class `.staticPage` was renamed to `.richtext`
- Δ composer root is now in `app/`
- \- removes plugins Flattr, NsfwBadge and Userranks (see Migration Notes)

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.4.0...4.5.0)

### Migration Notes

#### Set Parser

Set the parser in your `saito_config.php`. Default is:

``` php
Configure::write('Saito.Settings.ParserPlugin', 'Bbcode');
```

which points to `app/Plugin/<Bbcode>Parser`.

#### Plugin Source

The removed plugins have their own repositories now:
- https://github.com/Schlaefer/saito-flattr (composer: schlaefer/saito-flattr)
- https://github.com/Schlaefer/saito-nsfwbadge (composer: schlaefer/saito-nsfwbadge)
- https://github.com/Schlaefer/saito-userranks (composer: schlaefer/saito-userranks)

Download them manually and put them into `app/Plugin` or install them via composer.

## [4.4.0] - 2014-10-26

### What's new
- ＋ adds hooks for extending the core (see `docs/dev-hooks.md`)
- ✓ quote symbol set in admin-settings is ignored
- Δ refactors user-ranks
  - \- removes user-ranks from core (still available as example plugin, see `app/Plugins/Userranks`)
- Δ refactors flattr support
  - \- removes flattr from core (still available as plugin, see `app/Plugins/Flattr`)
  - ✓ no flattr button on user-profile
- Δ refactors "Not Safe For Work"-badge
  - \- removes NSFW-badge from core (still available as plugin, see `app/Plugins/NsfwBadge`)
- Δ refactors user-blocking
  - ＋ automatically unblock blocked users after a specified time
  - ＋ moderators and admins see blocking history in user-profile
  - ＋ admins see global blocking history in admin-area
- Δ refactors smiley handling
  - ＋ introduces new HDPI-ready smiley icons in default theme
  - ＋ allows localization of smiley-titles
  - Δ changes default smiley-set
  - Δ allows usage of pixel or font based smilies
- Δ changes quote symbol for new installations from `»` to `>`

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.3.5...4.4.0)

### Migration Notes

#### DB Changes

<span class="label label-warning">_Note:_</span> If you use a table prefix you have to prepend it to the table name.

``` sql
CREATE TABLE `user_blocks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `by` int(11) unsigned DEFAULT NULL,
  `ends` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  `hash` char(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ends` (`ends`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
```

#### Remove Userranks

If you don't activate Userranks again remove its the DB entries:

``` mysql
DELETE FROM `settings` WHERE `name` IN ('userranks_show');
DELETE FROM `settings` WHERE `name` IN ('userranks_ranks');
```

#### Remove Flattr

Remove the old flattr config, its now set in in the Flattr plugin `config.php`:

``` mysql
DELETE FROM `settings` WHERE `name` IN ('flattr_category','flattr_enabled','flattr_language');
```

If you don't activate Flattr again you should remove its existing DB entries:

``` mysql
ALTER TABLE `users` DROP `flattr_allow_posting`;
ALTER TABLE `users` DROP `flattr_allow_user`;
ALTER TABLE `users` DROP `flattr_uid`;

ALTER TABLE `entries` DROP `flattr`;
```

#### Remove "Not Safe For Work"-badge

If you don't activate the "Not Safe For Work"-badge again you should remove its existing DB entries:

``` mysql
ALTER TABLE `entries` DROP `nsfw`;
```

#### New Smiley-Set

The easiest way to get the new smiley set is to drop the existing smiley-configuration database tables and recreated them (empty the cache in the admin-area afterwards):

``` mysql
DROP TABLE IF EXISTS `smilies`;

CREATE TABLE `smilies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order` int(4) NOT NULL DEFAULT '0',
  `icon` varchar(100) CHARACTER SET utf8 DEFAULT NULL,
  `image` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT INTO `smilies` (`id`, `order`, `icon`, `image`, `title`)
VALUES
    (1, 1, 'happy', NULL, 'smilies.t.smile'),
    (2, 2, 'grin', '', 'smilies.t.grin'),
    (3, 3, 'wink', '', 'smilies.t.wink'),
    (4, 4, 'saint', '', 'smilies.t.saint'),
    (5, 5, 'squint', '', 'smilies.t.sleep'),
    (6, 6, 'sunglasses', '', 'smilies.t.cool'),
    (7, 7, 'heart-empty-1', '', 'smilies.t.kiss'),
    (8, 8, 'thumbsup', '', 'smilies.t.thumbsup'),
    (9, 9, 'coffee', NULL, 'smilies.t.coffee'),
    (10, 10, 'tongue', '', 'smilies.t.tongue'),
    (11, 11, 'devil', NULL, 'smilies.t.evil'),
    (12, 12, 'sleep', '', 'smilies.t.blush'),
    (13, 13, 'surprised', NULL, 'smilies.t.gasp'),
    (14, 14, 'displeased', '', 'smilies.t.embarrassed'),
    (15, 15, 'unhappy', '', 'smilies.t.unhappy'),
    (16, 16, 'cry', '', 'smilies.t.cry'),
    (17, 17, 'angry', '', 'smilies.t.angry');


DROP TABLE IF EXISTS `smiley_codes`;

CREATE TABLE `smiley_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `smiley_id` int(11) NOT NULL DEFAULT '0',
  `code` varchar(32) CHARACTER SET utf8 DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT INTO `smiley_codes` (`id`, `smiley_id`, `code`)
VALUES
    (1, 1, ':-)'),
    (2, 1, ':)'),
    (3, 2, ':-D'),
    (4, 2, ':D'),
    (5, 3, ';-)'),
    (6, 3, ';)'),
    (7, 4, 'O:]'),
    (8, 5, '(-.-)zzZ'),
    (9, 6, 'B-)'),
    (10, 7, ':-*'),
    (11, 8, ':grinw:'),
    (12, 9, '[_]P'),
    (13, 9, ':coffee:'),
    (14, 10, ':P'),
    (15, 10, ':-P'),
    (16, 11, ':evil:'),
    (17, 12, ':blush:'),
    (18, 13, ':-O'),
    (19, 14, ':emba:'),
    (20, 14, ':oops:'),
    (21, 15, ':-('),
    (22, 15, ':('),
    (23, 16, ':cry:'),
    (24, 16, ':\'('),
    (25, 17, ':angry:'),
    (26, 17, ':shout:');
```

Otherwise you have to make the changes in the admin area.

If you want to stick with the old icons: don't change anything and copy over the smilies theme folder from the previous version.

## [4.3.5] - 2014-10-21

### What's new
- ✓ fixes broken entries/edit form on validation error

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.3.4...4.3.5)

## [4.3.4] - 2014-10-10

### What's new
- ✓ fixes slidetab reordering is not stored on the server
- ✓ fixes some caches are not persistently cleared out
- ✓ fixes a performance regression caused by erroneously cleared caches when adding/editing a posting
- Δ only show small notice if search words are too short

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.3.3...4.3.4)

## [4.3.3] - 2014-10-09

### What's new
- ✓ fixes showing wrong category in posting tree

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.3.2...4.3.3)

## [4.3.2] - 2014-10-09

### What's new
- ＋autofocus first text field in search
- ✓ fixes no recent postings on profile page of ignored users
- ✓ fixes ignored postings are shown in mix view
- ✓ fixes auto-link in [url] BBCode-tag
- ✓ fixes no admin edit of user profile page because of similar name already exists
- Δ shows ignored postings as invisible but clickable placeholders
- Δ update to CakePHP 2.5.5, jQuery 2.1.1 and latest require.js
- Δ code refactoring

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.3.1...4.3.2)

## [4.3.1] - 2014-09-28

### What's new
- ✓ fixes issues when posting an answer with Safari Mobile

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.3.0...4.3.1)

## [4.3.0] - 2014-09-27

### What's new
- ＋ make ignored postings more flexible by using a CSS `.ignored` class #287
- ＋ improves detection for password autofill
- ＋ prevents iframe embedding by setting `X-Frame-Options` header
- ＋ help pages open in new window
- ✓ improves blackholed behavior and documentation #286
- Δ move "Advanced Search"/"Simple Search" navigation to navbar #288
- Δ refactors thread-tree and mix-tree rendering

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.2.1...4.3.0)

## [4.2.1] - 2014-09-14

### What's new
- ＋ show postings in profile of ignored user #280
- ✓ default search order is not applied in users/index #282
- ✓ "Neu Antwort" in german l10n email notification #279
- ✓ deleting category in admin backend fails #285
- ✓ creating new category in admin panel doesn't empty category cache #284
- Δ update to CakePHP 2.5.4+ #283

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.2.0...4.2.1)

## [4.2.0] - 2014-09-01

### What's new
- ＋ ignore users #276
- ＋ performance improvements in mix view
- ✓ i10n in contacts/<*> headers #277
- ✓ adds missing back-links in contact form
- ✓ cache prefix not set for default cache #278
- Δ switch thread cache from whole threads to thread-lines #275

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.1.0...4.2.0)

### Migration Notes

#### DB Changes

<span class="label label-warning">_Note:_</span> If you use a table prefix you have to prepend it to the table name.

``` mysql
CREATE TABLE `user_ignores` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `blocked_user_id` int(11) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `blocked_user_id` (`blocked_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

ALTER TABLE `users` ADD `ignore_count` int(10) unsigned NOT NULL DEFAULT '0';
```

## [4.1.0] - 2014-08-08

### What's new

This release improves user experience for non-logged-in users by providing a MAR. This may increase server load.
- ＋ Mark As Read for anonymous users #274
- ＋ link to help-page source in help-page footer
- ＋ requests (view, mix) of non-public posting asks for login to access that posting instead of redirecting to homepage
- ＋ on registration a new username must at least two characters off to any existing username to be available
- ✓ fixes dummy_data shell
- Δ refactors contact code
  - Δ URL to contact admin changes from `/users/contact/0` to `/contacts/owner/`
  - Δ URL to contact users changes from `/users/contact/<id>` to `/contacts/user/<id>`

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.0.5...4.1.0)

## [4.0.5] - 2014-07-27

### What's new
- ✓ fixes [e] BBCode-tag bleeds into following content #270
- ✓ ongoing CSRF blackholing in 4.0.4 #269
- Δ optimizes composer autoload performance in release build #272
- Δ updates jBBCode to 1.3 #271
- Δ updates CakePHP to 2.5.3
- Δ code refactoring

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.0.4...4.0.5)

## [4.0.4] - 2014-07-05

### What's new
- ＋ switches BBCode parser to jBBCode
  - Δ changes languages selection in [code]-tag from `[code <lang>]` to `[code=<lang>]` (no backwards compatibility/breaks existing BBCode)
- ＋ less strict security settings to prevent overly eager CSRF-blackholing
- ＋ adds vine to to allowed video domains
- ＋ makes simple search available for non-logged in users
- ＋ performance improvements
- ✓ fixes new threads don't show up in recent entries s(l)idebar
- ✓ fixes orphaned entries in `user_read` table
- ✓ fixes no eng. l10n for markitup link-popup
- ✓ breaks long words in slidetab to next line
- Δ updates CakePHP to 2.5.2

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.0.3...4.0.4)

## [4.0.3] - 2014-06-17

### What's new
- ✓ improves word-length-detection in simple-search

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.0.2...4.0.3)

## [4.0.2] - 2014-06-10

### What's new
- ✓ blank page when changing password #266
- ✓ tab behavior in register and login form broken #267
- ✓ log blackholed requestes #268

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.0.1...4.0.2)

## [4.0.1] - 2014-06-08

### What's new
- ＋ clear localStorage on logout #262
- ✓ includes jasmine js test in cli test runner #242
- ✓ internal error if categories are activated on user profile for the first time #263
- ✓ layout Category popup gobbeld #265
- ✓ improves Category popup positioning
- ✓ Sending Category form is blackholed #264

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.0.0...4.0.1)

## [4.0.0] - 2014-05-29

### What's new

All changes of 4.0.0-RC - 4.0.0-RC3 and
- ✓ Inline Answering not working with SecurityComponent enabled #261
- ✓ blob → mediumblob conversion for ecaches table was not applied in installer
- Δ changes default cookie encryption to AES
- Δ deactivates form autofill in login and registration form

[Full change-log](https://github.com/Schlaefer/Saito/compare/4.0.0-RC3...4.0.0)

## [4.0.0-RC3] - 2014-05-18

### What's new
- ✓ fixes logout not working
- ✓ fixes non collapsing back links in responsive design
- Δ Updates CakePHP to 2.5.1

[See full change-log.](https://github.com/Schlaefer/Saito/compare/4.0.0-RC2...4.0.0-RC3)

## [4.0.0-RC2] - 2014-05-16

### What's new
- ✓ thread cache isn't checked appropriately and reads/saves wrong output
- ✓ skip not implemented and failing pgsql simple search test case
- Δ code refactoring

### Links
- [Full Changelog](https://github.com/Schlaefer/Saito/compare/4.0.0-RC...4.0.0-RC2)

## [4.0.0-RC1] - 2014-04-29

### What's new
- ＋ Don't update view counter on search engine robots #243
- ＋ extended crawler/robots detection
- ＋ improves autolinking of URLs next to punctuation marks
- ＋ shows used cache engines in system info admin panel
- ＋ add doc link and "where to edit" info to /users/map #247
- ＋ sort threads by last answer is default now
- ＋ /users/index is always sorted alphabetically after primary sort parameter
- ＋ PostgreSQL support #259 (except for simple search)
- ✓ show date on older shoutbox entries #251
- ✓ absolute date in mobile view is gobbled #170
- ✓ limit map boundaries and minimum zoom-level
- ✓ [bbcode] urls are not parsed in lists #256
- ✓ theme error on /users/edit on validation error #244
- ✓ deleting last bookmark should show "no bookmarks" message #75
- ✓ installation creates `BLOB` instead of `MEDIUMBLOB` field in `ecaches` table
- ✓ global help button is not activated on answering form
- ✓ i18n decimal divider in generation time
- ✓ fixes no pointer cursor on .btn-strip hover
- ✓ Double entries in UserOnline slidebar #157
- ✓ accession 1 entries-url should not be in sitemap
- Δ rewritten user login #254
  - ＋ shows info if user account is not activated yet
  - ＋ autofill username on failing login in login-form
  - ＋ autofocus/select username in login-form
  - ＋ log failing logins
- Δ rewritten user registration #253
  - ＋ shows info if sending of confirmation email failed
  - ＋ log if sending of confirmation email failed
  - ＋ shows info if confirmation link failed
  - ＋ shows info if account was already activated
  - ＋ adds navigation back-links to registration views
  - Δ l10n changes
  - ！confirmation-URL in activation-email changed
- Δ rewritten user change password #255
  - ＋ log change attempts for non-existing users
  - ＋ log change attempts by non-authorized users
- Δ refactors bookmark edit
  - ＋ log edit attempts by non-authorized users
- Δ refactors contact messaging
  - ＋ advanced email address configuration #223
  - ＋ show disclaimer on global contact form
  - ＋ adds navigation back-link
  - ＋ logs if sending of contact email failed
- ＋ CakePHP `dummy_data` shell to generate artificial content for development
- Δ changes disclaimer l10n strings
- Δ Update to CakePHP 2.5.0 #246
- Δ replaces underscore.js with lo-dash
- Δ activate CakePHP's SecurityComponent by default
- Δ renames log file `auth.log` to `saito-auth.log`
- Δ add staticPage layout for all `pages` esp. TOS #257
- Δ consolidates database field types
- Δ consolidates database index names
- Δ removes unused database fields

Other bugfixes and improvements. This updates includes important security enhancements.

### Migration Notes

#### DB Changes

<span class="label label-warning">_Note:_</span> If you use a table prefix you have to prepend it to the table name.

<span class="label label-warning">_Note:_</span> Depending on DB-size these may run some time. Make a DB backup and apply separately.

``` mysql
DROP TABLE IF EXISTS `useronline`;

CREATE TABLE `useronline` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(32) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `user_id` int(11) DEFAULT NULL,
  `logged_in` tinyint(1) NOT NULL,
  `time` int(14) NOT NULL DEFAULT '0',
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `useronline_uuid` (`uuid`),
  KEY `useronline_userId` (`user_id`),
  KEY `useronline_loggedIn` (`logged_in`)
) ENGINE=MEMORY AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `bookmarks` DROP INDEX `entry_id-user_id`;
ALTER TABLE `bookmarks` ADD INDEX `bookmarks_entryId_userId` (`entry_id`, `user_id`);

ALTER TABLE `bookmarks` DROP INDEX `user_id`;
ALTER TABLE `bookmarks` ADD INDEX `bookmarks_userId` (`user_id`);

ALTER TABLE `entries` DROP INDEX `user_id`;
ALTER TABLE `entries` ADD INDEX `entries_userId` (`user_id`);

ALTER TABLE `entries` DROP INDEX `user_id-time`;
ALTER TABLE `entries` ADD INDEX `entries_userId_time` (`time`, `user_id`);

ALTER TABLE `entries` CHANGE `last_answer` `last_answer` TIMESTAMP  NULL  DEFAULT NULL;
UPDATE `entries` SET last_answer=NULL WHERE last_answer='0000-00-00 00:00:00';

ALTER TABLE `entries` CHANGE `edited` `edited` TIMESTAMP  NULL  DEFAULT NULL;
UPDATE `entries` SET edited=NULL WHERE edited='0000-00-00 00:00:00';

ALTER TABLE `users` CHANGE `last_login` `last_login` TIMESTAMP  NULL  DEFAULT NULL;
UPDATE `users` SET last_login=NULL WHERE last_login='0000-00-00 00:00:00';

ALTER TABLE `users` CHANGE `registered` `registered` TIMESTAMP  NULL DEFAULT NULL;

ALTER TABLE `users` CHANGE `last_refresh` `last_refresh` TIMESTAMP  NULL DEFAULT NULL;
UPDATE `users` SET last_refresh=NULL WHERE last_refresh='0000-00-00 00:00:00';

ALTER TABLE `users` CHANGE `last_refresh_tmp` `last_refresh_tmp` TIMESTAMP  NULL DEFAULT NULL;
UPDATE `users` SET last_refresh_tmp=NULL WHERE last_refresh_tmp='0000-00-00 00:00:00';

ALTER TABLE `users` CHANGE `personal_messages` `personal_messages` TINYINT(1)  NOT NULL  DEFAULT '1';
ALTER TABLE `users` CHANGE `user_lock` `user_lock` TINYINT(1)  NOT NULL  DEFAULT '0';
ALTER TABLE `users` CHANGE `user_signatures_hide` `user_signatures_hide` TINYINT(1)  NOT NULL  DEFAULT '0';
ALTER TABLE `users` CHANGE `user_automaticaly_mark_as_read` `user_automaticaly_mark_as_read` TINYINT(1)  NOT NULL  DEFAULT '1';
ALTER TABLE `users` CHANGE `user_sort_last_answer` `user_sort_last_answer` TINYINT(1)  NOT NULL  DEFAULT '1';
ALTER TABLE `users` CHANGE `show_recententries` `show_recententries` TINYINT(1)  NOT NULL  DEFAULT '0';
ALTER TABLE `users` CHANGE `user_category_custom` `user_category_custom` VARCHAR(512)  CHARACTER SET utf8  COLLATE utf8_unicode_ci  NULL  DEFAULT NULL;

ALTER TABLE `users` CHANGE `activate_code` `activate_code` INT(7)  NOT NULL  DEFAULT '0';

ALTER TABLE `entries` DROP `uniqid`;
ALTER TABLE `users` DROP `last_logout`;
ALTER TABLE `users` DROP `hide_email`;
ALTER TABLE `users` DROP `user_show_own_signature`;

INSERT INTO `settings` (`name`, `value`) VALUES ('email_contact', NULL);
INSERT INTO `settings` (`name`, `value`) VALUES ('email_register', NULL);
INSERT INTO `settings` (`name`, `value`) VALUES ('email_system', NULL);
```

_If_ you're using MySQL and the field `value` in the `ecaches` table is of type `BLOB` change it to `MEDIUMBLOB`:

``` mysql
ALTER TABLE `ecaches` CHANGE `value` `value` MEDIUMBLOB  NOT NULL;
```

### Links
- [Full Changelog](https://github.com/Schlaefer/Saito/compare/3.5.1...4.0.0-RC)



## Older Changes

See  [CHANGELOG_OLD.md](docs/CHANGELOG_OLD.md) for older changes.
