# TODO

Work that is known, understood, and deliberately not done yet. Items are filed
under the release they are meant for; anything without a release is waiting on a
decision rather than on time.

Findings that turn out to be already fixed get deleted from here rather than
ticked off — this file should describe the present, not the past.

---

## Release 8.3

### Get out from under Bootstrap 4

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
| What falls out | `theme.css`, 11,033 lines, 199 KB |

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

**The first step is done.** `__theme.scss` imports the nineteen modules actually
in use rather than all thirty-eight, which took 23% off all six stylesheets
without changing a class in any template. The measurement it was supposed to
produce: what remains is `reboot`, `type`, `grid`, `tables`, `forms`, `buttons`,
`dropdown`, `button-group`, `input-group`, `card`, `badge`, `alert` and
`utilities` — and `utilities` alone accounts for forty of the classes in use,
mostly spacing and display helpers that modern CSS covers directly. So the
remaining question is no longer "how much is Bootstrap carrying" but "is a
framework worth it for buttons, cards, form controls and a spacing scale".

The admin area is worth reworking in the same pass, and only then. It has
already been taken off jQuery, DataTables and Bootstrap's JavaScript — it runs
on Alpine now — so its markup is the one thing still tying it to Bootstrap.
Doing it separately means paying for it twice.

### Take `'unsafe-inline'` out of the script policy

The stored XSS fixed in 8.2.3 was dangerous because of what stood behind it, not
because of what it was. The last link — a role change that asked for nothing but
the session — closed in 8.2.4. This is the other one, and the bigger lever: with
it gone, that XSS would have been inert.

**The CSP allows `'unsafe-inline'` for scripts**, so it does not stop an
`onerror=` handler. The header (set at the edge, not by the app) currently
reads:

    script-src 'self' 'unsafe-inline' 'unsafe-eval' 'wasm-unsafe-eval' https://plausible.panxatony.net

Dropping `'unsafe-inline'` is the single biggest lever against this whole class,
and it is achievable — but not free, because three things rely on it today:

- `templates/layout/htmx_island.php` has two blocks that must run *before*
  paint: the theme stylesheet choice out of `localStorage` (still via
  `document.write`) and the font scale. Externalising them reintroduces the
  flash they exist to prevent, so these need a **nonce** — generated per request
  and passed into the header, which means the app has to own the CSP rather than
  the edge.
- `plugins/Admin/templates/Settings/index.php` carries the scroll-spy
  replacement. That one just belongs in the admin bundle.
- `plugins/Feeds/templates/cell/FeedLinks/display.php` uses
  `onclick="this.select()"` as a documented no-JS fallback; a delegated listener
  does the same.

There used to be a fourth, and it was the dangerous kind: the `[spoiler]` parser
wrote an `onclick` into every spoiler it rendered. Nothing linked to the tag, so
dropping `'unsafe-inline'` would have broken a working feature that nobody was
watching. 8.2.8 moved it to a delegated handler and gave it an editor button, so
it is now both reachable and policy-safe.

`'unsafe-eval'` is a separate question and probably **not** worth chasing:
Alpine evaluates its expressions as strings, so removing it means the CSP build
plus rewriting the expressions in nine templates into component methods. It also
buys far less — `unsafe-eval` does not enable an inline event handler, which is
what the attack actually used.

Worth noting what the current CSP *does* achieve, so it is not mistaken for
useless: `connect-src 'self'` and `form-action 'self'` keep data from being
posted off-site. It just cannot help against an attacker who acts same-origin,
and a takeover needs nothing else. (`img-src` allows `https:` generically, so
that exfiltration path is open regardless.)

### The audit's correctness findings

From the 2026-07-29 audit. Its security half shipped as 8.2.3 and the small
self-contained bugs as 8.2.4; what is left needs a fuller release cycle than a
patch, either because it touches a data-mutating path or because the blast
radius is wider than the change. Each one was read and confirmed, not inferred.

The three thread-merge items belong together — read all three before touching
that method.

**Merging threads gets `last_answer` from the wrong posting.**
`PostingBehavior::threadMerge()` compares the source against `$targetPosting`,
but only a thread's *root* carries a current `last_answer` —
`EntriesTable::afterSave()` bumps the root alone, so every reply keeps the value
it was created with. Merge an old thread onto a posting in the middle of an
active one and the root's `last_answer` is overwritten with the older date: the
thread sinks down a front page sorted by exactly that column, though it was
answered today. Compare against the target *root*.

**…and does it without a transaction.** Five dependent writes in a row. A
failure after the first leaves the source root pointing into the target thread
while its subtree still carries the old `tid`, and the merge cannot be retried —
`isRoot()` is false now, so `threadMerge()` returns false at its first check.
The half-merged thread is unrepairable through the interface.

**`Thread::get('root')` assumes the smallest id is the root.** True until a
merge re-parents an older thread onto a newer one; then the smallest id belongs
to a child. It decides the dimming of ignored thread starters
(`thread_cached_init.php`) and the cache-validity stamp of thread lines
(`Thread::getLastAnswer()`) — the latter can leave cached lines unrefreshed when
answers arrive. The data carries `pid == 0`; use that.

**Pasted code blocks lose their formatting.** `htmlToBbcode()` collapses
whitespace in every text node, `<pre>` and `<code>` included, and the final tidy
pass removes what survives. A code block copied from a documentation page
arrives as one unindented line — and because the conversion "added something",
the browser's own paste was suppressed to produce it. Take those text nodes
verbatim and keep them out of the tidy pass.

**`SaitoHelp` decides "admin only" from a comment in the localized file.**
`findAll()` lets a localized topic replace the English baseline wholesale, and
`view()` reads the marker off whichever file it found. Both German admin topics
carry it, so nothing is wrong today — but a future translation that omits the
`<!-- admin -->` line silently makes that topic public in that language, and no
test or lint would notice. Derive it from the English baseline or from the
filename instead.

### Draft autosave: the storage survived, the feature did not

Found in the 2026-07-29 sweep for backend capability the frontend no longer uses.
`DraftsTable` is complete — validation, uniqueness rules, a daily Cron garbage
collection, `EntriesTable::hasOne('Drafts')`, `UsersTable::hasMany('Drafts')`, and
a post-save `deleteDraftForPosting()` — and the `drafts` table is real. **Nothing
has ever created a draft since the teardown**: `DraftsController` and the help
topic went with `7070aa075`. A daily `DELETE` runs against a table nothing fills.

Left standing on purpose. Unlike the thread colours and the reload interval —
both of which took a member's input and quietly discarded it, and are fixed in
8.2.8 — drafts are invisible: no form, no setting, nobody is being misled. And
unlike `[embed]`, the write half is gone too, so this is not a one-line
reconnection but a feature: an autosave endpoint, a restore path in the editor,
and a decision about what happens when a draft and a half-typed reply disagree.

Whoever picks it up starts from a working storage layer. Feature `#342`.

### Residue deliberately left in place

Three things the same sweep turned up that look dead and were kept, so the next
reader does not re-derive the reasoning:

- **`UsersTable::setCategory()`** has no caller in the application but five
  tests. Tested behaviour with no caller is as likely to be public API for a
  site-specific plugin as it is to be residue, and the cost of keeping it is
  nothing.
- **`UserOnlineTable::setOnlinePeriod()`** is called only by tests, which use it
  to make the online-period deterministic. That is a legitimate use.
- **`$SaitoSettings`**, set on every request in `AppController`, is read by no
  template *in this repository*. Saito runs on installations whose themes are not
  tracked here and cannot be checked, and removing a view variable would fail
  there silently. Not worth the risk for one `set()` call.

---

## No release assigned

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

### Psalm: it does earn something — the note here was wrong

An earlier version of this file said Psalm "duplicates PHPStan at lower
strictness" and should be raised or dropped. That was wrong about how it is used.
Nothing runs Psalm's general analysis; the pipeline runs
`psalm --taint-analysis`, which follows user input through to dangerous sinks —
something PHPStan does not do without its paid extension. Measured on
2026-07-30: 30 seconds, no findings, types inferred for 83.6% of the codebase.

So `errorLevel="8"` and `findUnusedCode="false"` describe a mode nobody invokes,
and changing them would change nothing. Left alone deliberately.

### Two PHP dependencies still worth moving

- **`claviska/simpleimage` is on the unmaintained 3.x line** (3.7.2) and it parses
  user-uploaded images. The v4 API differences are modest.
- **`mobiledetect/mobiledetectlib` 2.x is EOL** (current is 4.x). Only UA regexes,
  but the device data is frozen around 2018.

### DeepSource JS-0067 / JS-0052

Knowingly accepted reports, ignored in the dashboard. The reasoning is in
`.deepsource.toml`; revisit it there rather than here.
