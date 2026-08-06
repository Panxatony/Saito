# Upgrading from Saito 5.7 to Saito 8

**Short answer: yes, you can go straight from 5.7.x to 8.x.** There is no
intermediate version to stop at, and the database barely changes. The work is
almost entirely on the server, not in the data.

That is not an assumption, and it has been measured twice — the second time
against a database this project did not build, which is the measurement that
counts.

On 2026-08-01, a database was migrated to the 5.7 level, then taken to the
current release in a single `migrations migrate`, and compared column by column
with a fresh installation: 124 columns on both sides, no difference. That proved
the migrations are self-consistent and nothing more, because both sides came
from the same migrations. (That count is from the day it was taken and predates
the three additive migrations of the 8.4 line; they run identically on a grown
and a fresh installation, so they cannot introduce drift of their own.)

On 2026-08-03 the same run was done from a schema dump of a forum that had grown
from an old version. **It failed**, at the eighth of nine migrations, and it
turned up a second fault that would have been worse for being silent. Both are
fixed (see *Two faults that only a grown schema shows*, below). After the fix
the run completes and the result matches a fresh installation with no
character-set differences and four cosmetic column differences.

Stopping somewhere in between still buys nothing.

This document covers the 5.7 → 8 jump specifically. For the general "copy the
new files over" routine, see [update.md](update.md).

---

## What actually changes

### The database: thirteen migrations

Between 5.7.0 and the current release there are **thirteen** schema changes.
The second exists only to repair the first; one of them is the expensive one;
two are about columns that predate these migrations entirely; one finishes a
character-set conversion that the first left incomplete; and the last three
are additive — new tables and one new column for features that arrived in the
8.4 line, which cost an upgrade nothing but the time to create them.

| Migration | What it does |
|---|---|
| `20260604090000_ConvertLegacyTablesToUtf8mb4` | Converts `useronline` and the single column `users.user_category_custom` from utf8mb3 to utf8mb4 |
| `20260727190000_RestoreUserCategoryCustomWidth` | Restores `users.user_category_custom` to its full 1024 characters |
| `20260730000000_ConvertCoreTablesToInnodb` | Moves the core tables off MyISAM — **the expensive one, read below** |
| `20260730010000_DropLegacySaito5UserColumns` | Drops six `users` columns dead since 2012 |
| `20260730020000_DropUnusedEcachesTable` | Drops `ecaches`, a cache table nothing has written to since 2014 |
| `20260731210000_AddNsfwToEntries` | Adds `entries.nsfw` — **only where it is missing**, see below |
| `20260801080000_DropFlattrResidue` | Drops `entries.flattr` and three settings rows, if they are there |
| `20260801090000_AlignSchemaWithGrownInstalls` | Widens three text columns to `MEDIUMTEXT` and makes the username index unique — converting each table's character set first, see below |
| `20260802140000_DropApiSettings` | Drops two settings rows belonging to the retired API |
| `20260803090000_ConvertRemainingTablesToUtf8mb4` | Converts every remaining table from utf8mb3, so a four-byte character can be stored at all |
| `20260805100000_CreatePasswordResetTokens` | Adds `password_reset_tokens` for the self-service password reset (8.4.0). Empty until somebody forgets a password |
| `20260806100000_AddTosAcceptedVersionToUsers` | Adds `users.tos_accepted_version` (8.4.1). Starts at 0, as does the `tos_version` setting, so the upgrade asks nobody to agree to anything |
| `20260806120000_CreateTwoFactorTables` | Adds `two_factor_credentials` and `two_factor_recovery_codes` (8.4.2). Rows exist only for accounts that enrol, so an installation where nobody does carries no authentication secrets at all |

#### The last two are guarded, and that is not decoration

Both columns are older than these migrations. A forum running since Saito 4 has
`entries.nsfw` and `entries.flattr` already; one created from these migrations
has neither. So one migration adds a column only if it is absent and the other
drops one only if it is present — running either blindly is an error, not a
no-op.

If you are coming from 5.7 you almost certainly have both. `nsfw` will be left
exactly as it is, **including its data**: on the macnemo installation 1928
postings carry the marking, set between 2011 and 2020, and 8.3.2 gave it a
meaning again after six years of doing nothing. Nothing needs doing on your
side.

`flattr` is the other case — a micropayment service that no longer exists, read
by no code since the Saito 5 rewrite. It goes. If you want to keep the sixteen
thousand marks it holds on a grown forum, take them out of the backup you made
before upgrading; there is no way back afterwards.

#### The InnoDB conversion is the one to plan for

MyISAM has no transactions and does not object to being asked for one: it accepts
`BEGIN` and `COMMIT` and ignores them. Every safeguard that groups several writes
together is therefore correct on InnoDB and silently unprotected on MyISAM —
merging two threads is five dependent writes, and a failure part-way through used
to leave a thread half-merged and unrepairable through the interface.

A 5.7 installation created before the 2018 schema almost certainly still has
MyISAM tables. The figures below were measured on a copy of a live table with
679,910 postings taking 321 MB, converted for real rather than estimated:

- **5 minutes 31 seconds**, with the table locked throughout, so the forum is
  unavailable for that long. `entries` carries a full-text index and InnoDB needs
  a hidden column for one that cannot be added afterwards, so the table is
  rewritten in full — there is no incremental path.
- **Keep room for a second copy.** The rebuild writes the new table beside the old
  one. The result came out *smaller* — 279 MB against 321 — but you need roughly
  double the table size in transit. This is the likeliest way the upgrade fails.
- **Run migrations from the command line**, `bin/cake migrations migrate`. Through
  the web updater PHP's execution limit will cut a five-minute conversion short;
  the server finishes it regardless, but it may then not be recorded as applied.
- **The search finds more afterwards.** MyISAM ignores words shorter than four
  characters and carries some 500 stopwords; InnoDB's limits are three characters
  and 36. On the measured copy the three-letter term `mac` went from **0 hits to
  16,384**, while a longer term returned exactly the same count. Nothing is lost —
  Saito searches in boolean mode, so MyISAM's "ignore words in over half the rows"
  rule never applied — but a forum whose members search for short words will
  notice.

Check what you are in for before you start:

```sql
SELECT table_name, engine, table_rows,
       ROUND((data_length + index_length) / 1024 / 1024) AS mb
FROM information_schema.tables
WHERE table_schema = DATABASE() AND engine = 'MyISAM';
```

Nothing listed means the conversion is a no-op for you.

#### The dropped columns

`user_font_size`, `show_about`, `show_donate` and three `flattr_*` exist only on
installations grown from Saito 5 — upstream removed them around 2012, as a manual
SQL step printed in a changelog rather than as a migration, so nobody ran it.
They are dropped rather than carried over: `user_font_size` holds a Saito 5
scaling *factor*, not the percentage today's settings page works in, so the stored
values no longer mean what they say.

That is the whole schema delta. Older installations kept two stragglers on the
3-byte character set, so they could not store 4-byte characters — emoji, mostly.
Both hold short ASCII values today, which is what makes the conversion safe.

**Which version to land on: the newest.** There is one release you must not
stop *below* — see the paragraph directly after this one — and above it there is
no reason to aim at anything but the latest. Each release since carries fixes
that a 5.7 installation would otherwise walk straight into, and the migrations
run as one chain regardless of how many of them there are.

**Upgrade to 8.0.12 or later — do not stop at an earlier 8.0.x.** Up to and
including 8.0.11, the first migration also narrowed `user_category_custom` from
1024 characters back to 512 as an unintended side effect of restating the
column. That column holds a serialized list of the categories a member chose to
see, roughly 14 characters per category: 512 runs out at about 40 categories,
1024 at about 75. Outside MySQL's strict mode the value is cut silently, and a
cut serialized list cannot be read back at all — the member's category
selection is destroyed, not shortened. From 8.0.12 the migration keeps the
column at 1024, so a forum upgrading now is never narrowed in the first place.

If you already upgraded to 8.0.0–8.0.11, the second migration widens the column
again on the next update; check whether any member lost their selection by
looking for values at exactly 512 characters:

```sql
SELECT COUNT(*) FROM users WHERE CHAR_LENGTH(user_category_custom) = 512;
```

Zero means nothing was truncated.

No table is added or restructured, and no posting, user or category is altered.
The 8.3.0 migrations change how tables are stored and remove a column set and a
cache table nothing has read since 2012 and 2014; the two from 8.3.7 and 8.3.8
touch columns older than these migrations, one of which is dropped along with
three settings rows. **Your postings, members and categories are untouched.**

#### Two faults that only a grown schema shows

Both were found on 2026-08-03 and both are fixed. They are written down because
the *reason* they went unnoticed applies to anything else in here: until that
day, every check ran against a database these migrations had built.

**The upgrade used to stop at `AlignSchemaWithGrownInstalls`.** It widened
`entries.text` with a statement that also named `utf8mb4`. On a table still in
utf8mb3 that converts one column and leaves its neighbours — and `entries`
carries a FULLTEXT index over `subject`, `name` and `text`, which may not span
two character sets:

    ERROR 1283: Column 'text' cannot be part of FULLTEXT index

The migration aborted and the ones after it never ran. It now converts the whole
table first, and only where that is still needed.

**And the conversion was never finished.** `ConvertLegacyTablesToUtf8mb4` reaches
`useronline` and one column of `users`; ten tables stayed three-byte. On such an
installation a four-byte character — an emoji in a bookmark note, a category
name, a block reason — is *refused*:

    ERROR 1366: Incorrect string value: '\xF0\x9F\x91\x8D ...'

under MySQL's default strict mode, and silently truncated without it.
`ConvertRemainingTablesToUtf8mb4` closes that.

**What still differs** after the upgrade, compared with a fresh installation —
four columns, none worth an `ALTER`: `categories.accession` (`tinyint` against
`int`), `user_blocks.hash` (`char` against `varchar`), and
`users.last_refresh`/`last_refresh_tmp` (`timestamp` against `datetime`). The
first two are storage-identical for the values they hold; the last two are the
2038 question and belong to their own pass.

#### Clear the schema cache afterwards, or the forum will not come back

```shell
bin/cake schema_cache clear
```

This is not optional and it is not tidiness. CakePHP remembers each table's
column list on disk, and dropping six columns from `users` does not tell it. The
next request builds its query from the remembered list, asks for a column that no
longer exists, and the database refuses it:

```
Unknown column 'Users.user_font_size' in 'SELECT'
```

Every page that looks at a user — which is every page for anyone logged in —
answers 500 until the cache is cleared. Nothing is damaged and nothing is lost;
the forum simply stays down for as long as it takes to notice. Run the clear
immediately after `migrations migrate`, before you go looking at the site, and
reload PHP-FPM after it.

Found the hard way on the beta installation while preparing 8.3.0.

Every setting Saito 8 reads already exists in a 5.7 database — the newest of
them was introduced before 5.0. Nothing has to be inserted by hand.

### The platform: this is the real step

| | Saito 5.7 | Saito 8 |
|---|---|---|
| PHP | 7.2+ | **8.4** |
| CakePHP | 3.8 | 5 |

Two major PHP versions and two major framework versions. For the application
that was a large amount of work (releases 6.0.0 and 7.0.0); for you as the
operator it means one thing: **the server needs PHP 8.4 before Saito 8 will
run at all.** Everything else in this document is routine.

No PHP extension beyond a standard install is required. A typical set:
`gd`, `intl`, `mbstring`, `mysql`, `xml`, `curl`, `zip`.

### The interface

This is the part your members will notice. Up to and including 8.0.x, Saito
shipped both frontends and used the classic one by default. **From 8.1 there is
one**: pages are rendered on the server and enhanced with small htmx/Alpine
islands, and the Backbone/Marionette application is gone.

What that means in practice:

- Nothing in the database changes, and no content is touched. It is a different
  way of drawing the same forum.
- Published addresses keep working. `/entries/view/<id>`, `/users/view/<id>`,
  `/entries/mix/<tid>` and the two indexes redirect permanently to their new
  equivalents, so links from search engines, bookmarks and other sites still
  land in the right place.
- Members with a page open across the upgrade should reload once. A tab keeps
  running the JavaScript it loaded, however new the files on the server are.

If a staged approach suits you better, 8.0.x is a working intermediate stop: it
carries the whole CakePHP 5 / PHP 8.4 migration and still offers the old
interface, so the platform move and the interface change can be done on
different days.

The **default theme** for new installations changed to Nova. Existing
installations keep whatever their own configuration says; nothing changes
visually unless you ask for it.

---

## Before you start

- [ ] **PHP 8.4 available** on the server (`php -v`). This is the one hard
      requirement — check it first, because everything else is reversible and
      this is not something you fix mid-upgrade.
- [ ] **A database dump you have actually tested**, not just taken. A dump that
      cannot be restored is not a backup.
- [ ] **A copy of the whole installation directory**, including
      `webroot/useruploads/` and your `config/`.
- [ ] **A maintenance window.** The updater runs while the forum is reachable;
      the migration itself is quick, but the file copy and a failed PHP version
      are not things you want members walking into.
- [ ] Know your current version: the footer shows it, or read
      `src/Lib/version.php`.

```bash
# database
mysqldump -u <user> -p <database> | gzip > saito-before-8.sql.gz

# files
tar czf saito-before-8.tar.gz /path/to/forum
```

---

## The upgrade

### 1. Get the release

Download the tarball from the GitHub release page. It ships with `vendor/`
already installed and the frontend assets already built — no Composer, no Node,
no build step on your server.

```bash
V=8.3.8   # or whatever the newest release is
curl -LO "https://github.com/Panxatony/Saito/releases/download/$V/saito-$V.tar.gz"
curl -LO "https://github.com/Panxatony/Saito/releases/download/$V/saito-$V.tar.gz.sha256"

# Releases up to 8.0.9 recorded the checksum with the CI build path in it, so
# `sha256sum -c` cannot find the file. Compare the hash itself:
echo "$(awk '{print $1}' "saito-$V.tar.gz.sha256")  saito-$V.tar.gz" | sha256sum -c -

tar xzf "saito-$V.tar.gz"
```

### 2. Copy the code, keep your data

Copy everything **except** the paths that hold your own content and settings:

| Keep yours | Why |
|---|---|
| `config/app.php` | database credentials, salts |
| `config/saito_config.php` | your forum's configuration |
| `webroot/useruploads/` | members' uploaded images |
| `logs/`, `tmp/` | runtime state |

```bash
rsync -a --delete \
  --exclude 'config/' \
  --exclude 'logs/' \
  --exclude 'tmp/' \
  --exclude 'webroot/useruploads/' \
  saito-8.2.0/ /path/to/forum/
```

> **Careful with `config/`.** The tarball contains a placeholder `app.php`.
> Copying the directory wholesale overwrites your database credentials and
> salts — and overwriting the salts logs every member out and invalidates
> password-reset links. Exclude the directory and merge the new keys by hand
> (next step).

### 3. Merge the new configuration keys

Compare your files against the ones in the tarball and copy over what is new.
Both files are commented; nothing here is required for the forum to start, but
it is the moment where you would notice a new option.

```bash
diff -u /path/to/forum/config/saito_config.php saito-8.2.0/config/saito_config.php
diff -u /path/to/forum/config/app.php          saito-8.2.0/config/app.php
```

Do **not** replace the files — read the diff and copy individual keys.

### 4. Clear the caches

Stale route and configuration caches survive a file copy and produce confusing
errors that look like code bugs.

```bash
rm -rf /path/to/forum/tmp/cache/models/* /path/to/forum/tmp/cache/persistent/*
```

### 5. Let the updater run

Open the forum in a browser. Saito compares the version in the database with the
version of the code; because they now differ, it sends you to the updater
instead of the front page. Confirm, and it applies the pending migrations and
writes the new version.

The updater refuses to run if the recorded database version is below 4.10.0 — a
5.7 installation is well above that.

### 6. Check

- The front page loads and shows the new version in the footer.
- A posting opens, and you can write a reply.
- Log in and out.
- `SELECT COUNT(*) FROM users WHERE CHAR_LENGTH(user_category_custom) = 512;`
  returns 0 — see [the database section](#the-database-two-migrations).
- Open the administration area.
- An uploaded image still displays (thumbnails are served from
  `/api/v2/uploads/thumb/<id>`; if they 404, the web server is not passing
  through to `index.php`).
- `logs/error.log` has nothing new in it.

---

## If it goes wrong

Nothing in this upgrade is one-way except the migration, and that one has a
`down()`. In practice the fastest route back is the backup:

```bash
# restore files
rm -rf /path/to/forum && tar xzf saito-before-8.tar.gz -C /

# restore database
gunzip < saito-before-8.sql.gz | mysql -u <user> -p <database>
```

Then put PHP back to the version 5.7 ran on. Restoring the code without
restoring PHP leaves you with a forum that still does not start.

---

## Coming from 8.0.x

If you are already on 8.0.x, the step to 8.1 is the interface change and nothing
else: same database, same schema, same configuration — except that
`SAITO_FRONTEND` no longer does anything and can be removed. 8.0.x let you try
the island frontend by setting it to `island`; doing that before upgrading is a
good way to see what your members are about to get, with a one-line way back.

---

## Notes for old installations

**Times: the stored data is fine, and `APP_DEFAULT_TIMEZONE` must stay `UTC`.**
An earlier version of this note claimed installations hold local time in
`entries.time`. They do not. That column is a MySQL `timestamp`, which stores an
unambiguous UTC instant, and CakePHP pins its own connection to `+00:00` — so
the value the application reads is correct on every installation, however old.
The measurement behind the wrong claim had been taken through a `mysql` client
whose session ran on local time.

What the variable controls is how PHP *labels* that value. Set it to a local
zone and every instant in the forum is read wrong by the offset. The forum's own
timezone is a separate setting in the admin area, and Saito renders displayed
times into it.

Up to and including 8.3.10 the display had three faults of its own — winter
postings shown with the summer offset and vice versa, a `datetime` attribute
carrying the wrong offset, and "today" beginning at midnight UTC. Fixed after
8.3.10. **Expect times from the other half of the year to move by an hour when
you upgrade past it**; that is the correction, not a new fault.

**The `Local` theme was renamed.** If your installation carries a custom theme
under `plugins/Local`, it keeps working — the rename affected the theme shipped
for macnemo.de, not the mechanism. Custom themes should carry `!default` on
their variables, or a parent theme's changes will not reach them.
