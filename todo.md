# TODO — after the SPA teardown

The Backbone/Marionette frontend is gone. Steps 1–5 of the teardown are done;
what follows is what the work uncovered and deliberately did not fix along the
way.

---

## Timezones: the database holds local time, the framework believes in UTC

Found on 2026-07-26 while chasing something else. **Not caused by the teardown**
— it has been like this for years and affected the old frontend just the same.

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

**Why it is not fixed here:** setting `APP_DEFAULT_TIMEZONE` to `Europe/Berlin`
shifts *every* time at once, including 2006, and those old rows may have been
written under changing assumptions. It needs:

1. an inventory of whether `entries.time` really is local throughout (check the
   DST boundaries — times around 02:00 in October),
2. a decision: migrate the stored data to UTC (clean, but a one-time risk) or
   teach the framework the right timezone (cheaper, but keeps local time in the
   database),
3. a pass over every output path: `TimeHHelper`, `<time>` elements, feeds,
   sorting, "unread since".

## Still pointing at the old world

- **`webroot/js/exports.bundle.js`** exists only because the administration
  backend needs `$`, `_` and Bootstrap on `window`. That is not the forum's
  frontend; it is one page's dependency. Worth revisiting when the admin area is
  modernised.
- **DeepSource JS-0067 / JS-0052** — 24 knowingly accepted reports, ignored in
  the dashboard. The reasoning is in `.deepsource.toml`.

## Not part of this, but adjacent

- The **admin area** was never part of the SPA and is unchanged. It is the last
  place still rendering with jQuery and Bootstrap components.
