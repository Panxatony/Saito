# TODO

Work that is known, understood, and deliberately not done yet — nothing else.

Two things this file is not: a record of what was decided against, and a record
of what has been finished. Both belong in the commit that made the decision.
Anything here that turns out to be done gets deleted rather than ticked off, and
anything that turns out to be *wrong* gets corrected with the reason, so nobody
re-derives the false premise.

Each entry says what was measured. A number without a measurement behind it is a
guess, and this file has cost time before by carrying one — the timezone entry
described a database problem that did not exist, because the measurement had
been taken through a client in the wrong timezone.

Nothing here is ordered by priority. Three are waiting on somebody else:
**macfix's CSP** on kt007's upgrade, **TypeScript 7** on typescript-eslint, and
**php_codesniffer 4** on its two dependants. The rest is ours to start whenever
it is worth it.

---

### The strict content-security policy on the other installation

Test, beta and prod run it; `script-src` has no `'unsafe-inline'` on any of the
three. **macfix is the one still open**, and not for the same reason: its ad tag
is an external script from its own origin and passes `'self'`, but its **Matomo
snippet is inline** and would be blocked. It needs what Plausible got here — the
snippet moved into a file. kt007 runs 5.7.1, so this waits on that upgrade
anyway.

Also left: `config/nginx/saito.conf.example` still carries the strict policy
commented out. Now that three installations run it, the comment could come off —
with a line saying what an installation must check first.

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

Each was attempted on its own with the suite as the gate, which is the only
reason the notes above say anything useful. `phpunit` 11 → 13 was the fourth and
went in with 8.3.12, once the question it was waiting on had an answer: no, the
suite does not fail on deprecations raised inside dependencies.

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

### The 2038 ceiling on the timestamp columns

`timestamp` cannot hold an instant after **2038-01-19**, and that is the type of
`entries.time`, `last_answer`, `edited`, `users.registered` and `last_login` —
the columns new postings are written to. Eleven years off, but it is a date
rather than a worry, and `datetime` has no such ceiling.

Do it in the same pass as `users.last_refresh` below: both are `ALTER`s on the
same tables, and both convert values written under whatever timezone the server
had at the time.

**The rule that came out of fixing the display, and is worth carrying to any
other installation:** `App.defaultTimezone` **must be UTC**. CakePHP pins its own
connection to `+00:00` regardless of the DSN, so what arrives from the database
is always a correct UTC rendering; PHP's timezone decides whether it gets
labelled correctly. Set the app to `Europe/Berlin` and every instant read is
wrong by the offset — which is exactly how the test system was configured until
2026-08-02.

Noticed while measuring, and not worth acting on: six postings whose `edited`
predates their `time`, seven with `last_answer < time`. Thirteen rows out of
twenty years, almost certainly import residue.

### The whole-forum export

The per-member half shipped in 8.3.12. The other half is a different problem:

**66.5 MB of posting text across 680,292 postings, plus 5,540 uploads.** That
cannot go through a request at all; it belongs in a console command that streams
JSON Lines, with an admin action doing no more than starting it and handing back
a file when it is done. Useful for a move and for a content-level backup beside
the SQL dump.

The serialisation is already written and can be reused: `Saito\User\DataExport`
turns a row into the shape, and `eachPosting()` already reads in batches.

### Translation catalogues carrying dead weight

**175 of 535 msgids have no call site a static sweep can find**, and both
`default.po` files carry entries already commented out as obsolete — 293 in
German, 72 in English.

Most of the 175 are false positives and the probe says so itself: Saito
assembles keys at runtime — `permission.role.` plus an id, every admin setting
label from the setting's own name, every page title from controller and action.
What is left after discounting those is the English plain-text group ("All
Categories", "Apply", "Cite", "Media") and one message in CakePHP 2's
`:placeholder` syntax, which look like the retired frontend's own catalogue.

Last on the list on purpose: the reward is a smaller file, the risk is deleting
a string that turns out to be built from parts, and no test would catch it. Read
the candidates rather than scripting the removal.

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

### The last schema difference, and the nineteen that stay

Measured 2026-08-01 against a freshly migrated database: **21 differences remain
and 19 of them are nothing** — display widths (`int(11)` against `int(4)`),
`unsigned` on columns that only ever hold positive ids and booleans, `char(32)`
against `varchar(32)` for a fixed-length hash. MySQL stores all of these
identically. Levelling them means an `ALTER TABLE` on every table of a forum
whose `entries` holds 680,000 rows: real risk, no gain. They stay.

The real one is `users.last_refresh` and `users.last_refresh_tmp` — **`timestamp`
on production, `datetime` from the migrations** — and here the migrations are the
correct side. Converting means rewriting two columns whose values were written
under whatever timezone the server had at the time, so it belongs with the 2038
work above and not before it.

### A refused metrics scrape writes an error to the log

`MetricsController` answers a missing or wrong token with a `NotFoundException`,
and that lands in `error.log` like any other. Right, in the sense that the
request failed; wrong, in that it is not an application error — it is the guard
doing its job.

It costs nothing while the token is correct. The moment it is not — a rotated
token, a typo in the scrape config — a 60-second interval writes **1,440 entries
a day**, and the log stops being readable exactly when somebody needs to read it.
Refuse without raising, or exclude this path from the error logger.

Seen on the test system, where two of the day's five entries came from probing
the guard.

### Registration tells you whether an address already has an account

`error_email_reserved` on a duplicate address — which is what a registration
form has to say for the person filling it in, and at the same time an oracle
for asking whether someone is a member here. The throttle added in 8.3.2 caps
it at five questions an hour per address, which is the cheap half of the fix.
The expensive half is accepting the registration silently and saying so only in
the mail, and that changes what a person sees when they simply mistyped. Worth
deciding on rather than drifting into.
