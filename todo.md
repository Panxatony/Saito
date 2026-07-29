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
   sorting, "unread since".

### DeepSource JS-0067 / JS-0052

Knowingly accepted reports, ignored in the dashboard. The reasoning is in
`.deepsource.toml`; revisit it there rather than here.
