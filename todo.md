# TODO

Work that is known, understood, and deliberately not done yet — nothing else.
Items are filed under the release they are meant for; anything without a release
is waiting on a decision rather than on time.

Two things this file is not: a record of what was decided against, and a record
of what has been finished. Both belong in the commit that made the decision.
Anything here that turns out to be done gets deleted rather than ticked off.

---

## Deployment, not code

### Turn the strict content-security policy on for prod and beta

The application side is finished and shipped in 8.3.0 — Saito emits no inline
script and no event attributes anywhere. What is left is a web-server header,
one installation at a time:

- `config/nginx/saito.conf.example` carries the strict policy, still commented
  out, because anything an installation embeds has to be added to it first.
- **Test and beta run it.** Beta was switched on at the edge on 2026-07-30 and
  measured in a browser afterwards: four pages, zero policy violations, zero
  JavaScript errors, and 209 Alpine/htmx markers in the DOM to show the scripts
  really did run. The detector was checked against a page that violates on
  purpose, so the zero means something.
- **Prod cannot have it yet, and the reason is the version.** macnemo.de runs
  8.2.9; the change that removed the last inline scripts is in 8.3.0. Its own
  markup was rendered against the strict policy on 2026-07-30 and produced
  **four blocks**: the theme-stylesheet picker, the font scale, the Plausible
  snippet, and the Plausible script itself. The first two run before the page is
  painted — blocking them is not a console message, it is a visibly wrong page.

  So this waits on the 8.3.0 deploy, and needs one addition beyond the example
  policy: `https://plausible.panxatony.net` in both `script-src` and
  `connect-src`. Prod's current header already allows it; what has to go is
  `'unsafe-inline'` from `script-src`, and only after 8.3.0 is live.
- `'unsafe-eval'` has to stay: Alpine evaluates its expressions as strings. It
  is the far less dangerous of the two — it does not enable an injected event
  handler, which is what the stored XSS of 8.2.3 actually used.
- `style-src` keeps `'unsafe-inline'`: templates still set `style` attributes,
  and inline style is not a route to code execution.

Anything an installation embeds must be added before the header goes on. The
macfix installation, for one, carries an ad tag and a Matomo snippet; the ad tag
is an external script from its own origin and passes `'self'`, the Matomo
snippet is inline and would not.

---

## No release assigned

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
| `phpunit` 11 → 13 | **Tried, and closer than it looks: every one of the 647 tests passes with the same 1545 assertions.** The run exits non-zero on 548 *notices*, and they come from vendor code — `suin/php-rss-writer` and `jbbcode` — plus one warning about `apc.enable_cli`, an ini in `phpunit.xml` that already fails to apply today. So this is not a code migration but a decision: should the suite fail on deprecations raised inside dependencies? Answer that first, then this is a short job. Reverted for now. |
| `squizlabs/php_codesniffer` 3 → 4 | **Not ours to move.** `cakephp/cakephp-codesniffer` and `slevomat/coding-standard` both still require `^3`. Wait for them. |

All four remaining were attempted one at a time with the suite as the gate,
which is the only reason the notes above say anything useful. Two went in, two
came back out.

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

**Why it is not fixed yet:** setting `APP_DEFAULT_TIMEZONE` to `Europe/Berlin`
shifts *every* time at once, including 2006, and those old rows may have been
written under changing assumptions. It needs:

1. an inventory of whether `entries.time` really is local throughout (check the
   DST boundaries — times around 02:00 in October),
2. a decision: migrate the stored data to UTC (clean, but a one-time risk) or
   teach the framework the right timezone (cheaper, but keeps local time in the
   database),
3. a pass over every output path: `TimeHHelper`, `<time>` elements, feeds,
   sorting, "unread since", and `UsersController::_failedLoginMessage()`, which
   feeds the database-local block `ends` through `timeAgoInWords` as if it were
   UTC — so "your block ends in N hours" is off by the server offset.

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

### What the jBBCode PHPStan exclusion hides


`phpstan.neon` excludes `plugins/BbcodeParser/src/Lib/jBBCode/Definitions/*`,
which is the code that parses untrusted markup — so the one place most worth
analysing gets none. Measured on 2026-07-30 by removing the exclusion: **16
errors**, and they come from three causes rather than sixteen.

- **One wrong property type, six symptoms.** `CodeDefinition::$_sOptions` is
  declared `array` and holds a `MarkupSettings`, so every `->get()` on it reports
  "cannot call method get() on array". Fix the declaration and six of the sixteen
  go.
- **Four `??` fallbacks that can never fire.** The `embed` library declares
  `$favicon`, `$providerName`, `$providerUrl` and `$url` as non-nullable, so the
  defaults behind `??` are unreachable. Harmless, but they promise a robustness
  that is not there — worth knowing before someone relies on it.
- **`$node->getParent()->getTagName()`** in the `[img]` definition. `getParent()`
  is typed as returning `Node`, which has no `getTagName()`; only `ElementNode`
  does. Not reachable today — the parser wraps everything in a document root, so
  an `[img]` always has an element parent — but the code depends on an invariant
  the types do not state, and `getParent()` returning null would be fatal.

The rest are Cake's magic helper properties (`$this->Html`) needing `@property`
annotations.

None of it is a live fault, which is why this is a to-do and not a fix. But
"untrusted markup, no static analysis" is the wrong place to leave a gap, and the
work is now quantified rather than guessed at.

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
