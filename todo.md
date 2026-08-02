# TODO

Work that is known, understood, and deliberately not done yet — nothing else.
Items are filed under the release they are meant for; anything without a release
is waiting on a decision rather than on time.

Two things this file is not: a record of what was decided against, and a record
of what has been finished. Both belong in the commit that made the decision.
Anything here that turns out to be done gets deleted rather than ticked off.

---

## Deployment, not code

### The strict content-security policy on the other installation

Done everywhere we control: test and beta on 2026-07-30, **prod on 2026-07-31**
together with 8.3.1. `script-src` has no `'unsafe-inline'` on any of the three.

What that took, beyond the header, is worth knowing before the next
installation follows: the only inline script prod still emitted was Plausible's
init stub, and it moved to `shared/app/webroot/plausible-init.js` (symlinked
into `webroot/`, so a code deploy cannot remove it). Anubis needed nothing — its
inline blocks are `application/json` and `type="ignore"`, which a browser never
executes.

**macfix is the one still open**, and not for the same reason. Its ad tag is an
external script from its own origin and passes `'self'`; its **Matomo snippet is
inline** and would be blocked. It needs the same treatment as Plausible — the
snippet in a file — before the header goes on there. kt007 runs 5.7.1, so this
waits on that upgrade anyway.

Also left: `config/nginx/saito.conf.example` still carries the strict policy
commented out. Now that three installations run it, the comment could come off
— with a line saying what an installation must check first.

### Dependencies still a major version behind

Everything that fits inside the constraints already set was taken on
2026-07-30, CakePHP 5.3.6 → 5.4.1 among them, and on the same day `aura/di`
4 → 5 and `symfony/dom-crawler`/`css-selector` 6.4 → 8 went through with the
suite green. What is left needs a decision or some work, and each line below
was measured, not guessed:

| | |
|---|---|
| `cakephp/authentication` 3 → 4 | **Tried. 250 tests fail.** Version 4 changed the shape of the identifier configuration — "Identifier configuration must specify a class name" — so `src/Auth/AuthenticationServiceFactory.php` has to be rewritten, not re-pinned. Reverted. Security-relevant, so worth doing properly. |
| `cakephp/migrations` 4 → 5 | **Tried. 25 errors.** Under 5 the `Initial` migration no longer creates the tables, so the next migration runs against a `users` that is not there. Our migration files need work, not a new constraint. Reverted. |
| `squizlabs/php_codesniffer` 3 → 4 | **Not ours to move.** `cakephp/cakephp-codesniffer` and `slevomat/coding-standard` both still require `^3`. Wait for them. |

All four remaining were attempted one at a time with the suite as the gate,
which is the only reason the notes above say anything useful. Two went in, two
came back out.

### The stylesheets compile with 300 deprecation warnings

Surfaced on 2026-08-01 by the Node 24 upgrade. `grunt-dart-sass` declares `sass`
as a *peer* dependency, which yarn 1 never installs; the old lockfile happened
to carry sass 1.24.4 from an older resolution. Declaring it properly brought
1.102, and with it warnings the 2020 compiler never printed:

| Warning | Deadline |
|---|---|
| `@import` is deprecated | removed in Dart Sass **3.0** |
| `lighten()` / `darken()` are deprecated | use `color.adjust()` / `color.scale()` |
| `/` for division outside `calc()` | removed in Dart Sass **2.0** |
| global built-ins (`abs()`, `if()`) | removed in Dart Sass **3.0** |
| the legacy JS API grunt-dart-sass uses | removed in Dart Sass **2.0** |

Nothing is broken: the compiled CSS was checked against the previous compiler by
rendering the live front page and a posting page and comparing pixel by pixel —
zero differing pixels out of 2.8 and 2.3 million. The output only *looks*
different in the file, where `darken()` results now print as
`rgb(50.67%, 13.72%, 11.08%)` instead of `#8b0404`.

The last row is the awkward one and should be settled first: `grunt-dart-sass`
was last released in 2021 and calls the API that goes away in Dart Sass 2. The
partials can be modernised at leisure; that dependency needs a decision — a
maintained replacement, or compiling sass from a plain script instead of a grunt
task.

Not urgent, and not the kind of thing to do in the same change that moved Node.

### TypeScript stays on 6 until typescript-eslint catches up

`typescript-eslint` refuses to load against TypeScript 7 — it says so and exits,
so `yarn lint` produces no lint at all. Tracked upstream as
typescript-eslint#10940. TS 6.0.3 type-checks the island cleanly, so there is
nothing to gain from forcing it; this is a note so the next person who sees
Dependabot offer TypeScript 7 knows it has already been tried.

### Timezones: what is left after the helper was fixed

**Done on 2026-08-02.** `TimeHHelper` renders the stored instant in the forum's
timezone instead of adding an offset to the epoch, and it has tests now — it had
none, in fifteen call sites. Three defects went with it, and only the second had
ever been written down:

1. **The offset was computed once from *now*.** So in August every winter
   posting was shown an hour late and vice versa. On production a posting stored
   at `16:48 UTC` in January was displayed as **18:48** instead of 17:48. This
   was the one that actually reached readers, and nobody had noticed it.
2. The `datetime` attribute carried the shifted value labelled `+00:00`.
3. "Today" began at midnight **UTC**, because `mktime()` follows PHP's timezone —
   and this forum's busiest hour is the one right after local midnight.

`ThreadHtmlRenderer` reaches for the helper outside a view render, so
`beforeRender()` is not guaranteed; the clock is read lazily now. Before, that
path silently used an offset of zero and rendered in UTC.

**The configuration rule, which is the part worth carrying to any other
installation:** `App.defaultTimezone` **must be UTC**. CakePHP pins its own
connection to `+00:00` regardless of the DSN, so the value arriving from the
database is always a correct UTC rendering; PHP's timezone is what decides
whether it gets labelled correctly. Set the app to `Europe/Berlin` and every
instant read is wrong by the offset.

That is not hypothetical: **the test system was set that way** —
`env[APP_DEFAULT_TIMEZONE] = "Europe/Berlin"` in its fpm pool — and read every
posting an hour off in winter, two in summer. Corrected 2026-08-02, old pool
kept as `saito.conf.vor-tz-fix`. Production and beta were already on UTC.

It also means the previous version of this entry proposed the exact opposite of
the fix. Setting the app timezone to `Europe/Berlin` does not correct the
display; it breaks the reading.

**Checked and *not* broken**, contrary to what this entry used to claim:
`UsersController::_failedLoginMessage()` takes `ends` as a `Cake\I18n\DateTime`
off a `datetime` column, not a string, so the instant survives; and the RSS
`pubDate` was always right.

**What is left is the 2038 ceiling, and it is the only part that touches the
schema.** `timestamp` cannot hold an instant after **2038-01-19**:
`entries.time`, `last_answer`, `edited`, `users.registered`, `last_login` — the
columns new postings are written to. Eleven years off, a date rather than a
worry, and `datetime` has no such ceiling. Worth doing in the same pass as the
`last_refresh` difference left over from the schema alignment, since both are
`ALTER`s on the same tables.

Also noticed while measuring: six postings whose `edited` predates their `time`,
and seven with `last_answer < time`. Thirteen rows out of twenty years, almost
certainly import residue.

### Operator documentation is missing from the release tarball

The package excludes `docs/*.md` as developer documentation. That was right when
`docs/` held only developer notes; it is not right now. `configuration.md` and
`deployment-debian.md` are written for operators, and the README **is** shipped
and links to both:

```
TOT   docs/configuration.md
TOT   docs/deployment-debian.md
```

So the one file that tells an operator `APP_DEFAULT_TIMEZONE` must be UTC is the
one they cannot find from the package they downloaded. Ship the operator-facing
subset — configuration, deployment, update, upgrade, the privacy template — and
keep dev-setup and dev-hooks out. One line in the tar invocation.

### Residue found by the probe sweep of 2026-08-02

`dev/audit-probes.sh` runs the comparisons that found everything else this
cycle. It prints candidates, never verdicts — a `grep` proves a name is absent,
not that a feature is gone. What it turned up:

**Four settings rows nothing reads.** `api_enabled`, `api_crossdomain`,
`userranks_show`, `userranks_ranks`.

The first two are the more uncomfortable pair: an admin who sets `api_enabled`
to 0 believes the API is off. It is not, and never was — nothing consults that
value. Either wire it up or remove it, but a switch that pretends to control
something is worse than no switch.

**A correction, because the first version of this entry was wrong.** The API is
*not* dead. Probing `/api`, `/api/v2` and `/api/v2/entries` returns 404 and led
me to say so; the live routes are elsewhere and answer properly:

```
/api/v2/uploads/thumb/{id}   403      /api/v2/uploads.json   401
/api/v2/bookmarks            401
```

401 and 403 are authenticated endpoints working. They are registered by
**ImageUploader** and **Bookmarks**; the `Api` plugin supplies the base
controller and the JSON error renderer they share, and the CSRF exemption for
`/api/v2/` covers real routes. Nothing to revive — only to extend, if there is
ever a consumer.

### A Prometheus endpoint

There is a Prometheus server with access to the hosts over NetBird, which
settles the question the idea usually founders on: who reads it.

**Measured on production before designing anything** — the cost is one query:

| | |
|---|---|
| `COUNT(*) FROM entries` (680,292 rows) | **128 ms** |
| users, uploads, useronline, last-24h postings | 10–13 ms each |

InnoDB has to walk an index for the first one. At a 15-second scrape that is
about 1% of a core spent counting, so the total wants a short cache — a minute
is plenty; no forum needs its posting count to the second.

**Not under `/api/v2`.** The exposition format is `text/plain`, not JSON, so it
would inherit the JSON error renderer and the CSRF exemption for nothing. A
separate `/metrics` route.

**And not behind the admin session.** Prometheus is a scraper, not a member: it
can send basic auth, a static bearer token or a client certificate, but it
cannot log in and renew a JWT. So a static token from the environment
(`SAITO_METRICS_TOKEN`, compared with `hash_equals`), **empty meaning the
endpoint is off** — which keeps it absent on any installation that has not asked
for it, macfix included. The NetBird interface gives the second lock at nginx.

Half a day for the endpoint, the token check and a dozen metrics.

### Bring user ranks back, for the profile

The feature was extracted to a plugin in 2014 and has been gone from the code
since — 39 lines of logic, a threshold-to-title ladder keyed on a member's
posting count. The **settings survived on production and hold real data**:

```
10=Fischbrötchen|100=Schiffsjunge|1000=Maat|5000=Bootsmann
|10000=Harpunier|50000=Smutje|100000=Kpt. Ahab
```

Somebody configured that ladder and it has been displayed nowhere for eleven
years. `userranks_show` is 1.

**Scope: one row in the profile, nothing else.** Optional by construction —
driven by the two settings, so an installation without them shows nothing and
macfix is unaffected. No new column, no migration.

**One thing to check first:** the old code read `users.number_of_entries`, and
that field no longer exists. The profile counts through
`$user->numberOfPostings()` today; whether that costs a query per call decides
whether the rank can ever appear in the thread list, where it would run
hundreds of times a page. In the profile it does not matter.

**Emoji in the ladder — later.** The settings column is `varchar(255)` in
utf8mb4, so they fit, and the ladder currently uses 105 of those characters. But
colour emoji render differently on every platform, and monochrome dingbats
(`⚓ ⚒ ✦ ★ ⛵ ❖`) inherit the text colour and sit in a table row without
shouting. Worth trying once the plain version is up. Avoid `❶`–`❿`: that is the
glyph range the 7.2.5 XSS ran through — harmless in a setting, but not a range
to start using casually.

### An export an admin can run

Nothing exports anything today: no console command beyond the dummy-data
generator, no admin action, no per-user data download.

**Two different features hide under one word**, and they have almost nothing in
common:

**The whole forum.** 66.5 MB of posting text across 680,292 postings, plus 5,540
uploads. That cannot be assembled in memory or delivered through a request — it
is the same wall the InnoDB migration hit, and it belongs in a console command
that streams (JSON Lines, one posting per line) with the admin action doing no
more than starting it and handing back a file when it is done. Useful for a
move, for a content-level backup beside the SQL dump, and it would have helped
the 5.7 upgrade conversation.

**One member's data.** The largest account holds 50,878 postings and about 3 MB
of text — small enough to build and deliver in a request. This is the one with a
deadline attached: GDPR Art. 15 and 20 give a member the right to their data in
a machine-readable form, and Saito currently has no way to answer that except by
hand. It is also the smaller job.

Do the per-member one first. It is the obligation, it is a day's work, and the
full export can reuse its serialisation.

### Translation catalogues carrying dead weight

**180 of 535 msgids have no call site**, and both `default.po` files carry
entries already commented out as obsolete — 293 in German, 72 in English.

Expect false positives among the 180: a key assembled at runtime cannot be seen
by a static sweep. Which is why this is last on the list — the reward is a
smaller file, the risk is deleting a string that turns out to be built from
parts, and there is no test that would catch it.

The reverse direction is the one that matters and it is **clean**: no key is
used without being declared. That check belongs in CI, where the missing
`user.block.t` would have been caught before a moderator saw it.

### Video uploads


`video/mp4` and `video/webm` are already in the allowed types and `[video]`
already exists in the parser, so the feature is not missing — it is unusable.
The limits are far too small for any real recording, and nothing converts what
arrives.

**There is no single open format that plays everywhere**, and it is worth
saying plainly rather than discovering it halfway through. H.264/AAC in MP4 runs
on every phone and browser with hardware decoding, and is patent-encumbered.
VP9 in WebM is open and at home on Android, but Safari's support arrived late
and is not dependable. AV1 is open and modern, and older devices decode it in
software — a flat battery and a stuttering picture, when it plays at all. The
usual way out is to ship two encodings and let `<video>` pick, at the cost of
double the storage and double the encoding time.

**The cheap step, if the goal is "members can post a clip":** raise the limits
and accept only what already plays. Check the uploaded file with `ffprobe` and
refuse anything that is not H.264/AAC in MP4, with a message saying so. Phones
record exactly that, so it covers nearly everyone, and it needs no queue, no
migration and no new state. Someone with an exotic file has to convert it
themselves — that is the whole cost.

Four limits have to move together or it fails at the smallest one: Saito's own
`setDefaultMaxFileSize` (16 MB on the live install), PHP's `upload_max_filesize`
and `post_max_size` (30 MB each), and nginx's `client_max_body_size`. A minute
of phone video is easily 100 MB.

**The full version is a project, not a feature.** Converting cannot happen in
the request — a transcode takes minutes and would block a PHP worker into its
timeout — so it needs a queue and a real background worker. The `Cron` plugin
does not qualify; it is a poor man's cron that runs off page views. From the
queue follows a state model (an upload is "in progress", then ready), which
means a column on `uploads` and a migration, and a posting that can render both
states. `ffmpeg` is **not installed** on the live install, and encoding to H.264
carries its own licensing question.

Two things not to skip when it happens: storage — the live install holds 2.5 GB
of uploads today and video plus two encodings changes the order of magnitude,
not the percentage — and the fact that a video is a complex file handed to a
very large parser. Saito checks images for decompression bombs; video would need
ceilings on duration, resolution and bitrate, and `ffmpeg` should not run as the
same user as the forum.

### The rest of the way out from under Bootstrap 4

Bootstrap has been unmaintained since January 2023. The exposure is smaller than
the version number suggests: **Bootstrap's JavaScript is not loaded anywhere in
the project**, and the known Bootstrap 4 vulnerabilities are all in its JS
components. What is left is a stylesheet. So this is a weight and maintenance
question, not a security one.

The weight is the argument. Measured on 2026-07-29:

| | |
|---|---|
| What `templates/` actually uses | ~46 distinct Bootstrap classes |
| Grid usage in the frontend | `container`, `row`, `col-md-8`, `col-lg-6` — that is all |
| What is imported for it | the complete Bootstrap SCSS, 484 KB of source |
| What falls out | `theme.css`, 11,033 lines, 199 KB — 132 KB since the reduction below |

Essentially: `btn`, `card`, `modal`, `alert`, `form-control` and a handful of
spacing utilities, paid for with a full framework. Modern CSS covers the rest —
`<dialog>` replaces the modal component including backdrop and focus trap, grid
and flexbox replace `col-*`, and Nova already carries its own custom properties.

**Two separate fronts, and they are not equally hard:**

1. **The themes.** `plugins/Bota/webroot/css/src/partials/__theme.scss` imports
   Bootstrap wholesale, and Nova extends Bota, and Macnemo and Macfix extend
   Nova. Pulling Bootstrap out of Bota breaks *every* derived theme at once —
   including the ones on other installations. That is a break of the theme
   interface and belongs in a major version with notice to theme authors, not in
   a point release.
2. **Admin and installer.** These do not merely use Bootstrap's classes, they
   have BootstrapUI *generate* the markup
   (`plugins/Admin/src/Controller/AdminAppController.php` — Form, Flash,
   Paginator, Breadcrumbs). Dropping Bootstrap here means dropping BootstrapUI
   and writing form templates. The frontend is unaffected by this half; it uses
   the plain Cake helpers.

The first step shipped in 8.3.0: `__theme.scss` imports the nineteen modules
actually in use rather than all thirty-eight, 23% off every stylesheet without
changing a class in any template. It was meant to measure what is really being
paid for, and it did. What remains is `reboot`, `type`, `grid`, `tables`,
`forms`, `buttons`, `dropdown`, `button-group`, `input-group`, `card`, `badge`,
`alert` and `utilities` — and `utilities` alone accounts for forty of the classes in use,
mostly spacing and display helpers that modern CSS covers directly. So the
remaining question is no longer "how much is Bootstrap carrying" but "is a
framework worth it for buttons, cards, form controls and a spacing scale".

The admin area is worth reworking in the same pass, and only then. It has
already been taken off jQuery, DataTables and Bootstrap's JavaScript — it runs
on Alpine now — so its markup is the one thing still tying it to Bootstrap.
Doing it separately means paying for it twice.

### The last two schema differences, and why they stay

Measured again on 2026-08-01, after the migrations added in 8.3.7 and 8.3.8:
**21 differences remain between a freshly migrated database and production, and
19 of them are nothing.**

Display widths (`int(11)` against `int(4)`), `unsigned` on columns that only
ever hold positive ids and booleans, `char(32)` against `varchar(32)` for a
fixed-length hash. MySQL stores all of these identically; nothing behaves
differently. Levelling them would mean an `ALTER TABLE` on every table on a
forum whose `entries` holds 680,000 rows — real risk, no gain. They stay.

The two that are real are `users.last_refresh` and `users.last_refresh_tmp`:
**`timestamp` on production, `datetime` from the migrations.** Here the
migrations are the correct side. `timestamp` is converted by the server against
the session timezone on both write and read, and it runs out in 2038; `datetime`
does neither.

Converting production means rewriting two columns whose values were written
under whatever timezone the server had at the time — which is not a schema
change, it is the timezone item further up wearing a different hat. It belongs
with that work, not before it.

**What was closed instead**, and is now identical on both sides: `entries.nsfw`
and `entries.flattr` (one added where missing, one dropped), `entries.text`,
`drafts.text` and `users.profile` (`text` → `mediumtext`, 64 KB → 16 MB), and
the `users.username` index, which was UNIQUE on a fresh install and a plain
non-unique prefix on a grown one.

That last one was the find worth having. `UsersTable` validates usernames as
unique, case-insensitively; production had 821 members and no index enforcing
it. Nothing had gone wrong — 821 names, 821 distinct lower-cased — but the
database was not holding a guarantee the application was already making.

And the `text` one was not theoretical either: the longest posting on that forum
is **294,739 characters**, four and a half times what a `TEXT` column holds. A
dump of it could not have been restored into a freshly migrated install, and
outside strict mode it would have been cut rather than refused.

### Registration tells you whether an address already has an account

`error_email_reserved` on a duplicate address — which is what a registration
form has to say for the person filling it in, and at the same time an oracle
for asking whether someone is a member here. The throttle added in 8.3.2 caps
it at five questions an hour per address, which is the cheap half of the fix.
The expensive half is accepting the registration silently and saying so only in
the mail, and that changes what a person sees when they simply mistyped. Worth
deciding on rather than drifting into.
