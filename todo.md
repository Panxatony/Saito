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

### The stylesheets still use `@import`, 54 times

Everything else Dart Sass warned about is fixed; `@import` is what is left. It
is removed in **Dart Sass 3.0**, and there is not even a 2.0 yet, so this has no
date — but it is the one part with no automated path.

**The official migrator refuses this codebase.** `sass-migrator module` exits
with *"multiple possible migrations … depending on the context in which it's
loaded"* for `Bota/…/partials/__theme.scss`, and it is right to: Bota, Nova and
Macnemo each load it with different variables set beforehand. That is the
`!default` inheritance the themes are built on, and resolving it into `@use …
with (…)` / `@forward` is a design decision per theme, not a rename.

So it has to be done by hand, one theme at a time, and it is the job that is
best done when nothing else is in flight — a mistake in the variable chain is
invisible until somebody opens the right page in the right theme.

**The gate that made the rest of this safe:** every conversion below was
output-preserving, so the test was `cmp` on the compiled CSS, not a pixel diff —
all seven stylesheets byte-identical, through the full `grunt` release chain
including minification. Use the same gate for `@use`; if the CSS moves, the
migration is wrong.

Done on 2026-08-02, for whoever wonders why the count dropped from 148 to 32:

- **Dependencies are silenced properly now.** `--quiet-deps` had no effect
  because Bootstrap and Font Awesome were pulled in by *relative path*
  (`../../../../../../node_modules/…`), which Sass counts as project code. They
  come in over `--load-path=node_modules` now, and their 56 warnings are gone.
  Note that this is *all* it does — Bootstrap 5.3.8 still has 40 `@import`
  lines, 24 `map-get` and 84 `if()` of its own, so upgrading it would not have
  fixed a single warning here.
- **`/` for division** — two sites, done by `sass-migrator division`. This was
  the only deadline with the nearer number (2.0) and it is cleared.
- **`map-get` → `map.get`** — 14 sites. A `@use "sass:map"` works fine inside a
  file that is itself `@import`ed, which is why this did not have to wait for
  the `@use` migration.
- **`darken()` / `lighten()` / `desaturate()`** — 11 sites, now `color.adjust()`.

**Two traps found the hard way, both caught by the byte comparison:**

`color.adjust()` does not clamp. `darken($c, 40%)` floors at black; `color.adjust`
keeps counting and emits `hsl(255, 8%, -0.59%)`, which no browser accepts. The
night presets are dark enough to hit that floor, so `_layout-and-navs.scss` now
clamps explicitly. Any further colour work needs the same care.

And a nested call converted by regex silently swaps its arguments:
`darken(desaturate($c, 20%), 40%)` became `-20%` *lightness* and `-40%`
*saturation*. It compiled, it looked plausible, and only the compiled CSS showed
it. Convert nested colour calls by hand.

A guard is in place so none of the three cleared categories can come back:
`--fatal-deprecation=slash-div,global-builtin,color-functions` in the `css:*`
scripts. Verified by reintroducing a `map-get` and watching the build fail with
exit 65 — and it does not fire on dependencies, `--quiet-deps` wins there.

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

### Bootstrap stays a dependency; only the shipped CSS is trimmed

**Decided 2026-08-02, after trying the other way first.** Two modules —
`utilities` and `grid` — were reimplemented in this repository and then reverted.
They worked and the pixels did not move, but the result was Bootstrap's design
under our name: 37% and 64% of the code lines were word for word Bootstrap's,
because the gate was "zero differing pixels" and identical output admits
essentially one implementation. The choice of gate had chosen the design.

The question that ended it was the right one: **what is theirs and what is
ours?** A reimplementation is a permanent fork of code nobody here wrote, with
none of the upstream benefit, an MIT notice to carry, and — measured — a saving
of 132 KB to 111. Keeping Bootstrap in `node_modules` and trimming the *output*
gives a clean boundary and four times the saving.

| | Nova `theme.css` | Grenze fremd/eigen |
|---|---|---|
| unverändert | 132 KB | sauber |
| nachgebaut | 111 KB | verwischt |
| **gepurgt** | **41 KB** | **sauber** |

Across all seven stylesheets: **810 KB to 271 KB.** `dev/purge-css.js` in the
release chain, configured in `purgecss.config.js`, verified by
`dev/pixel-diff.sh` — twelve comparisons, zero differing pixels, three themes in
both presets plus three pages and three viewport widths.

**This step is lossy and fails silently, which is the whole reason the harness
exists.** PurgeCSS keeps a rule when the class name appears as a literal in
`content`. Three separate blind spots turned up, none of them guessable:

1. **Markup is built in `src/`, not only in `templates/`.** The thread renderers
   and several helpers emit their own classes — `threadLine-pre`,
   `threadTree-node`, `et-root`, `solves-isSolved`. Missing those globs stripped
   the whole thread tree: 27% of the page.
2. **Icon names are composed.** `$iconLabel('sign-in', …)` produces
   `fa-sign-in`, which exists nowhere as a literal. Font Awesome is therefore
   kept whole — a cleverer extractor would learn today's call pattern and miss
   tomorrow's, and icons are too small a saving to buy that risk.
3. **Some classes only exist after `@extend`.** `flex-bar`, `flex-bar-header`.

Re-run `dev/pixel-diff.sh` after touching the config. **Zero is the gate**, not
"small enough" — every entry in the safelist is there because it broke a
comparison, and the next one will be found the same way or not at all.

Still open, and unchanged by this: Bootstrap 4 has been unmaintained since
January 2023. Its JavaScript is not loaded anywhere here, so this is a
maintenance question rather than a security one — but the dependency is frozen,
and a real modernisation (CSS grid, `<dialog>`, custom properties, Bootstrap's
class names dropped) is still the only thing that would retire it. That is a
change to how the forum *looks*, so `cmp` and the pixel harness both stop being
the gate and human judgement starts; it belongs in a release that is about the
appearance, not smuggled into one that is not.

**Do not detour through Bootstrap 5.** Measured: 5.3.8 still carries 40
`@import` lines, no `@use` at all, 24 `map-get` and 84 `if()`, so it fixes none
of the deprecations above, and a 4 → 5 migration is a breaking pass over every
theme plus ~46 classes in the templates — all of it thrown away when Bootstrap
goes.

But that measurement turned up something true *now*: **the PHP side is already
on Bootstrap 5 while the CSS is on 4.** `friendsofcake/bootstrap-ui` is at 5.2.0
and targets Bootstrap 5.3, and the Admin plugin has it generate the markup. So
the admin emits `form-label` and `me-2`, which appear in no stylesheet we ship.
Both only set `margin: 0.5rem`, so admin forms lose spacing and nothing breaks —
worth knowing rather than fixing blind.

**The admin is a separate front and does not block this one.** It loads
`stylesheets/bootstrap.min.css`, copied out of `node_modules` by grunt, plus
`Admin.admin.css`, and never a theme. Dropping Bootstrap there means dropping
BootstrapUI and writing form templates; it is worth doing in the same pass as
the modernisation above, and paying for twice otherwise.

**There is no LICENSE file at the repository root.** `composer.json` says MIT
and no file in the distribution does. Noticed while deriving those two partials;
they are reverted, so nothing derived remains — but the missing root file is a
separate omission, and it should list the third-party code the distribution
actually contains.

### The remaining schema drift, after two migration fixes

Two faults in the upgrade path were found on 2026-08-03 by running the
migrations against a schema from a forum that grew from an old version, rather
than against one these migrations built. Both are fixed; what is written here is
what the exercise showed about the ones that are left.

**The blocking one:** `AlignSchemaWithGrownInstalls` widened `entries.text` with
a `MODIFY` that states `CHARACTER SET utf8mb4`. On a table still in utf8mb3 that
changes one column and leaves its neighbours, and `entries` carries a FULLTEXT
index over `subject`, `name` and `text` — which may not span two character sets.
MySQL raises `ERROR 1283`, the migration aborts, and everything after it never
runs. The installations here never met it because their `entries` had been
converted years earlier; the migration now converts the table first, guarded so
it costs nothing where it is already done.

**The silent one:** the utf8mb4 conversion only ever covered `users` and
`useronline`. Ten tables stayed three-byte, so on a grown install a bookmark
note, a category name or a block reason containing an emoji is *refused* —
`ERROR 1366` under the default strict mode, truncated without complaint outside
it. `ConvertRemainingTablesToUtf8mb4` closes it.

**What still differs** between an upgraded install and a fresh one, verified
after both fixes — four columns, and none of them is worth an `ALTER`:

| | grown | fresh |
|---|---|---|
| `categories.accession` | `tinyint(4)` | `int(11)` |
| `user_blocks.hash` | `char(32)` | `varchar(32)` |
| `users.last_refresh` | `timestamp` | `datetime` |
| `users.last_refresh_tmp` | `timestamp` | `datetime` |

The first two are storage-identical for the values these columns hold. The two
`timestamp` columns are the 2038 ceiling described below, and they belong in
that pass rather than in a charset one.

**The lesson worth keeping**: a migration verified against a database the
migrations themselves built proves only that they are self-consistent. Both
faults above needed a schema from somewhere else to surface, and one of them
stopped the upgrade dead.

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
