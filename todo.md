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

### The rest of the way out from under Bootstrap 4

Bootstrap has been unmaintained since January 2023. The exposure is smaller than
the version number suggests: **Bootstrap's JavaScript is not loaded anywhere in
the project**, and the known Bootstrap 4 vulnerabilities are all in its JS
components. What is left is a stylesheet. So this is a weight and maintenance
question, not a security one.

**Do not detour through Bootstrap 5 on the way out.** Asked and measured on
2026-08-02. It buys nothing for the deprecation warnings above — 5.3.8 still
carries 40 `@import` lines, no `@use` at all, 24 `map-get` and 84 `if()` — and a
4 → 5 migration is a breaking pass over the whole Bota → Nova → Macnemo → Macfix
chain plus the ~46 classes below, all of it thrown away when Bootstrap goes.

But it turned up something that is true *now*: **the PHP side is already on
Bootstrap 5 while the CSS is on 4.** `friendsofcake/bootstrap-ui` is at 5.2.0
and targets Bootstrap 5.3, and the Admin plugin has it generate the markup
(`BootstrapUI.Form`, `.Html`, `.Flash`, `.Paginator`). So the admin emits classes
like `form-label` and `me-2` — which appear in *no* stylesheet we ship, not
Bootstrap 4.6's and not our own. Checked: zero occurrences across every compiled
theme.

The damage is small and worth knowing rather than fixing blind: both classes only
set `margin: 0.5rem`, so admin forms lose spacing and nothing breaks. A handful
of declarations closes it if it ever annoys anybody. It is *not* an argument for
the 5 migration — it is an argument for not being surprised by it later.

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

**The theme interface is 36 names, not 671 — so this need not be a breaking
release.** Measured 2026-08-02, correcting what this entry claimed before.
Bootstrap defines 671 variables. Bota sets 33 of them, Nova 36, and Macnemo —
the theme the live forum actually runs — exactly six: `body-bg`, `body-color`,
`border-color`, `orange`, `primary`, `text-muted`.

So what derived themes consume is a few dozen well-known names, and Bota can
keep declaring them itself, with `!default`, once Bootstrap is gone. Macfix then
keeps working unchanged without anybody having to touch it — which matters,
because we cannot see it. **The earlier plan of "major version plus notice to
theme authors" was based on assuming the whole framework was the interface. It
is not.**

The residual risk is worth stating plainly: a derived theme that overrides a
Bootstrap variable we do *not* carry over loses its effect **silently** — the
same failure mode as the missing `!default` that let Bota's `$enable-rounded`
quietly beat Macnemo's. The mitigation is to be generous rather than minimal:
carry fifty names, not thirty.

**Two separate fronts, and they are genuinely independent** — checked on
2026-08-02, because "frontend first" only works if it is true:

1. **The themes.** `plugins/Bota/webroot/css/src/partials/__theme.scss` imports
   Bootstrap wholesale, and Nova extends Bota, and Macnemo and Macfix extend
   Nova. The frontend layout loads `stylesheets/static.css` plus
   `<theme>.theme.css` — and *not* the admin's stylesheet.
2. **Admin and installer.** These do not merely use Bootstrap's classes, they
   have BootstrapUI *generate* the markup
   (`plugins/Admin/src/Controller/AdminAppController.php` — Form, Flash,
   Paginator, Breadcrumbs). Dropping Bootstrap here means dropping BootstrapUI
   and writing form templates. The frontend is unaffected by this half; it uses
   the plain Cake helpers.

   And the admin loads its own stylesheet — `stylesheets/bootstrap.min.css`,
   copied out of `node_modules` by grunt, plus `Admin.admin.css`. It never loads
   a theme. So the front can be taken off Bootstrap without the admin noticing,
   and the admin keeps its copy until its own pass.

**What the work actually is, measured rather than guessed:**

| | |
|---|---|
| Bootstrap's share of `Nova/theme.css` (minified) | **108 KB of 132 — 82%** |
| Component classes used in `templates/` | 33, in five families |
| Utility classes used in `templates/` | 7 |
| `@extend` on *Bootstrap* classes in our own SCSS | **83 calls, 43 distinct** |
| `@extend` on our own classes | 33 calls |

The 33 component classes are buttons, cards, alerts, form controls and a grid
that consists of `container`, `row`, `col-md-8`, `col-lg-6`. That is the whole
of it. The `@extend` calls are the part that is easy to miss: the coupling is
not only the `@import` lines, and each one becomes a hand-written declaration.

Removing Bootstrap does **not** clear the `@import` deprecations above — only 19
of the 54 sites are Bootstrap's. And unlike that work, the compiled CSS *will*
change here, so `cmp` cannot be the gate; it is a pixel diff per theme and page,
as used for the compiler change.

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
