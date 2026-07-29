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

**The first step is cheap and breaks nothing:** import only the Bootstrap SCSS
modules actually in use instead of the whole framework. Not one class in any
template changes, no theme breaks, and the remaining list then shows in numbers
how much Bootstrap is really carrying — which turns "drop it entirely?" into a
decision with measurements behind it.

The admin area is worth reworking in the same pass, and only then. It has
already been taken off jQuery, DataTables and Bootstrap's JavaScript — it runs
on Alpine now — so its markup is the one thing still tying it to Bootstrap.
Doing it separately means paying for it twice.

### Break the chain that turns any XSS into a forum takeover

The stored XSS fixed in 8.2.3 was not dangerous on its own — it was dangerous
because nothing downstream stopped it. Both links below are worth removing
whether or not another XSS ever appears, because they decide what *any* future
one is worth.

**A role change needs no confirmation.** `Admin\UsersController::role()` checks
the permission and nothing else — no password, no second step. So a script
running in an admin's browser can promote an account to `admin` with one POST,
and the attacker keeps that account long after the payload is gone. The CSRF
token is no obstacle: it sits in a `<meta>` tag that same-origin script reads
freely, which is what it is for. Asking for the admin's password before a role
change breaks the chain at its last link — the cheapest of the two by far.

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

From the 2026-07-29 audit. The security half went out as 8.2.3; what follows
changes behaviour rather than exposure, so it waits for a feature release. Each
one was read and confirmed, not inferred.

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

**The settings cache is deleted under a key that is never written.**
`SettingsTable::load()` writes `Saito.appSettings.<version>`; `clearCache()`
deletes `Saito.appSettings`. Saving a setting works today only because
`parent::clearCache()` wipes the whole default cache as a side effect — and only
inside a web request. From console or updater, stale settings survive.

**Undo is still broken in two places.** `insertAtCursor()` was written to keep
the browser's undo stack, and the smiley button uses it. The BBCode toolbar
(`editor.ts`) and the upload insert (`uploads.ts`) still assign `textarea.value`
directly, which wipes the history — and the upload path fires no `input` event,
so the textarea does not grow to fit what was inserted.

**Pasted code blocks lose their formatting.** `htmlToBbcode()` collapses
whitespace in every text node, `<pre>` and `<code>` included, and the final tidy
pass removes what survives. A code block copied from a documentation page
arrives as one unindented line — and because the conversion "added something",
the browser's own paste was suppressed to produce it. Take those text nodes
verbatim and keep them out of the tidy pass.

**Pinning and locking shows no effect.** `postings.ts` refreshes the posting
with two synthetic clicks, but `toggleInlinePosting()` only flips `display` when
a slider already exists. The moderator sees the old state, clicks again, and
sets the flag back on the server.

**`unlock()` reported success on failure** and **crashed on a stale request** —
both fixed in 8.2.3 because that method was being touched anyway. Listed here
only so the audit's numbering stays honest.

Smaller items worth folding into whichever release touches the file: `solve()`
swallows every exception into an anonymous 400 and discards the cause;
`htmxWidgetState()` updates the session even when the save failed; the
FormProtection unlock list still names `slidetabToggle`/`slidetabOrder`, which
no longer exist; `ThreadsComponent::paginateThreads()` starts a Stopwatch it
never stops on the early return; and `SaitoHelp` decides "admin only" from an
HTML comment in the *localized* file, so a future translation that drops the
comment silently makes that topic public in that language.

### Drop the six legacy `users` columns

`user_font_size`, `show_about`, `show_donate`, `flattr_uid`, `flattr_allow_user`
and `flattr_allow_posting` exist only on installations that grew from Saito 5.
Upstream removed them around 2012 — but as a manual SQL step printed in
`docs/CHANGELOG_OLD.md`, not as a migration, so nobody ran it. A fresh install
built from the 2018 `Initial` migration never had them.

Drop, do not convert. `user_font_size` holds a Saito 5 *factor* rather than the
percentage today's settings page uses; reviving those values would resize the
forum for 194 accounts who never asked for it.

Note for whoever does it: a `grep` over the codebase proves a column is unused,
never that it is residue. The way to tell is to compare a grown database against
one built from the migrations — the difference is exactly these six.

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

### Toolchain, from the same audit

Nothing broken, but everything on this list is past its support window and they
have to move together: TypeScript is pinned at 3.7.4 (2019) while the code is
written for a modern compiler, ESLint 8 and typescript-eslint 5 are both EOL,
and Vite 5 is past its own. `tsconfig.json` sets `strict: true` and **nothing
ever type-checks** — esbuild strips types without looking at them, and neither
`yarn build` nor `yarn lint` nor `composer test-all` runs `tsc --noEmit`. So the
strictness is enforced by whichever editor happens to be open. One coordinated
bump, then add the type-check to the lint gate.

Also: pin Bootstrap to 4.6.2 rather than the current 4.4.1 while the v4 exit
above is still pending — 4.6.2 is the last v4 and carries fixes 4.4.1 does not.
And `plugins/BbcodeParser/src/Lib/jBBCode/` is excluded from PHPStan, so the
definitions that parse untrusted markup get no static analysis at all.

### DeepSource JS-0067 / JS-0052

Knowingly accepted reports, ignored in the dashboard. The reasoning is in
`.deepsource.toml`; revisit it there rather than here.
