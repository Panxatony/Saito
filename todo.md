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

### Timezones: the database holds local time, the framework believes in UTC


Found on 2026-07-26 while chasing something else, and **not** caused by the SPA
teardown — it has been like this for years and affected the old frontend just
the same.

**The finding**, measured on one posting:

| | |
|---|---|
| Server | `21:11 CEST` = `19:11 UTC` |
| `entries.time` in the database | `20:54:02` — i.e. **local time** (DB timezone `SYSTEM`) |
| `APP_DEFAULT_TIMEZONE` | `UTC` |
| the `<time datetime>` served | `2026-07-26T20:54:02+00:00` |
| RSS `<pubDate>` | `… +0000` |
| the text shown | `20:54` — **correct** |

**Why the display is right anyway:** `TimeHHelper` computes
`serverOffset - offset(Saito.Settings.timezone)`. The setting says
`Europe/Berlin`, the server runs on CEST — the difference is zero, so the raw
value is printed unchanged and happens to be right.

**What follows from that:**

- Everything machine-readable is wrong by the local offset: the `datetime`
  attribute, the RSS `pubDate`, and therefore every feed reader. Postings appear
  **two hours in the future** there (one in winter).
- It is also **fragile**: move the server to UTC — an obvious thing to do during
  a migration — and the display that is currently correct shifts by two hours.
  Its correctness rests on the server timezone and a display setting happening to
  agree.

**The inventory is done — 2026-08-01, on production — and it makes this smaller
than it looked.**

`entries.time` is a **`timestamp`**, not a `datetime`. MySQL stores those as UTC
internally and converts on read and write through the session timezone. So the
stored instant is unambiguous; a repeated hour cannot corrupt it, and **there is
no data to migrate**. That removes the risky half of the job.

That it is local time on the way out was measured three independent ways, and
they agree back to the first posting in 2006:

| | |
|---|---|
| `time` against `created` on the same row (`timestamp` vs `datetime`) | exactly 7200 s in summer, 3600 s in winter, **no exception in 17,345 rows** |
| the hour that does not exist (last Sunday in March, 02:00–02:59) | **0 postings** — against 53 on the preceding Sunday |
| the hour that happens twice (last Sunday in October) | **179** in hour 2 against 141 in hour 1; on an ordinary Sunday it falls 163 → 73 |

The daily rhythm of all 680,280 postings confirms it a fourth time: the trough
sits at 05:00–06:00 and the peak at midnight. In UTC the trough would be at 03:00.

**What is actually wrong** is one thing only: the application reads the returned
local time as if it were UTC. That is configuration and output, not storage.

**What cannot be repaired:** 179 postings — 0.026% — were written during a
repeated hour, and their true instant is uncertain by one hour. The information
was lost when they were written.

**Found alongside, and it belongs to the same job:** `timestamp` cannot hold an
instant after **2038-01-19**. That is `entries.time`, `last_answer`, `edited`,
`users.registered` and `last_login` — the columns new postings are written to.
Eleven years off, but it is a date rather than a worry, and whoever moves the
timezones should lift those columns to `datetime`, which has no such ceiling.

Also noticed: six postings whose `edited` predates their `time`, and seven with
`last_answer < time`. Thirteen rows out of twenty years, almost certainly import
residue. No effect, recorded so the next person does not rediscover them.

**What remains** is a pass over every output path: `TimeHHelper`, `<time>`
elements, feeds, sorting, "unread since", and
`UsersController::_failedLoginMessage()`, which feeds the database-local block
`ends` through `timeAgoInWords` as if it were UTC — so "your block ends in N
hours" is off by the server offset.

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
