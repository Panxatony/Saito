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

Surfaced by the Node 24 upgrade: `sass` had never been declared, and declaring
it properly moved the compiler from 1.24 to 1.102 — which prints warnings the
2020 version never did.

| Warning | Deadline |
|---|---|
| `@import` is deprecated | removed in Dart Sass **3.0** |
| `lighten()` / `darken()` are deprecated | use `color.adjust()` / `color.scale()` |
| `/` for division outside `calc()` | removed in Dart Sass **2.0** |
| global built-ins (`abs()`, `if()`) | removed in Dart Sass **3.0** |

Nothing is broken. The compiled CSS was checked against the previous compiler by
rendering the live front page and a posting page and comparing pixel by pixel —
zero differing pixels out of 2.8 and 2.3 million. The output only *looks*
different in the file, where `darken()` results now print as
`rgb(50.67%, 13.72%, 11.08%)` instead of `#8b0404`.

Dart Sass is at 1.102 and there is no 2.0, so none of these deadlines has a
date. Modernising the partials is a leisurely job — one that touches every theme
and is best done when nothing else is in flight, since a mistake in a colour
function is invisible until somebody looks at the right page.

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
