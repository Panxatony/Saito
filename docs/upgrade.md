# Upgrading from Saito 5.7 to Saito 8

**Short answer: yes, you can go straight from 5.7.x to 8.x.** There is no
intermediate version to stop at, and the database barely changes. The work is
almost entirely on the server, not in the data.

This document covers the 5.7 → 8 jump specifically. For the general "copy the
new files over" routine, see [update.md](update.md).

---

## What actually changes

### The database: one migration

Between 5.7.0 and 8.x there is **exactly one** schema change:

| Migration | What it does |
|---|---|
| `20260604090000_ConvertLegacyTablesToUtf8mb4` | Converts `useronline` and the single column `users.user_category_custom` from utf8mb3 to utf8mb4 |

That is the whole schema delta. Older installations kept two stragglers on the
3-byte character set, so they could not store 4-byte characters — emoji, mostly.
Both hold short ASCII values today, which is what makes the conversion safe.

No table is added, dropped or restructured. **Your postings, users, categories
and settings are untouched.**

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

Saito 8 still ships the classic frontend and uses it by default
(`SAITO_FRONTEND` defaults to `spa`), so **an upgraded installation looks and
behaves the way your members know it.** The new htmx/Alpine "island" frontend is
opt-in.

Be aware of the direction, though: the classic frontend is being retired, and a
later release line will ship the island frontend only. If you plan the upgrade
now, plan a look at the island frontend too — see "Trying the new frontend"
below.

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
V=8.0.9
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
  saito-8.0.9/ /path/to/forum/
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
diff -u /path/to/forum/config/saito_config.php saito-8.0.9/config/saito_config.php
diff -u /path/to/forum/config/app.php          saito-8.0.9/config/app.php
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
instead of the front page. Confirm, and it applies the pending migration and
writes the new version.

The updater refuses to run if the recorded database version is below 4.10.0 — a
5.7 installation is well above that.

### 6. Check

- The front page loads and shows the new version in the footer.
- A posting opens, and you can write a reply.
- Log in and out.
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

## Trying the new frontend

The island frontend replaces the JavaScript application with server-rendered
HTML and small htmx/Alpine islands. It is what this project is moving to, and
5.7 → 8 is a sensible moment to look at it — but do it as a **second** step,
after the upgrade itself is done and quiet.

```bash
# in the environment, or config/saito_config.php
SAITO_FRONTEND=island
```

Then clear the route cache (`tmp/cache/persistent/`) — the root route is decided
at boot and is cached.

Switch back by removing the variable. Nothing in the database depends on which
frontend is active, so the choice is reversible at any time.

---

## Notes for old installations

**Times are stored as local time.** Installations that go back far enough hold
local time in `entries.time` while the application is configured for UTC. The
displayed times are correct as long as the server timezone and the forum's
timezone setting agree; machine-readable output (RSS `pubDate`, the `datetime`
attribute) is off by the local offset. This predates Saito 8 and is not changed
by the upgrade — but if you are moving the forum to a new server anyway, do not
"tidy up" the server timezone in the same step.

**The `Local` theme was renamed.** If your installation carries a custom theme
under `plugins/Local`, it keeps working — the rename affected the theme shipped
for macnemo.de, not the mechanism. Custom themes should carry `!default` on
their variables, or a parent theme's changes will not reach them.
