# Updating an installation

This is the routine for moving an existing installation to a newer release.
For the specific jump from 5.7 to 8, read [upgrade.md](upgrade.md) first — it
covers the one prerequisite that cannot be fixed halfway through.

## Before you start

Take a backup you have actually restored somewhere once. A dump that has never
been read back is not a backup.

```bash
mysqldump -u <user> -p <database> | gzip > saito-before-update.sql.gz
tar czf saito-before-update.tar.gz /path/to/forum
```

## 1. Get the release

The release tarball ships with `vendor/` installed and the frontend assets
built, so nothing has to be compiled on the server.

```bash
V=<version>
curl -LO "https://github.com/Panxatony/Saito/releases/download/$V/saito-$V.tar.gz"
curl -LO "https://github.com/Panxatony/Saito/releases/download/$V/saito-$V.tar.gz.sha256"
sha256sum -c "saito-$V.tar.gz.sha256"
tar xzf "saito-$V.tar.gz"
```

> Releases up to and including 8.0.9 recorded the checksum with the CI build
> path in it, so `sha256sum -c` cannot find the file. For those, compare the
> hash itself:
> `echo "$(awk '{print $1}' "saito-$V.tar.gz.sha256")  saito-$V.tar.gz" | sha256sum -c -`

## 2. Copy the code, keep your data

Copy everything **except** the paths that hold your own content and settings:

| Keep yours | Why |
|---|---|
| `config/` | database credentials, salts, your forum's configuration |
| `webroot/useruploads/` | members' uploaded images |
| `logs/`, `tmp/` | runtime state |

```bash
rsync -a --delete \
  --exclude 'config/' \
  --exclude 'logs/' \
  --exclude 'tmp/' \
  --exclude 'webroot/useruploads/' \
  "saito-$V/" /path/to/forum/
```

> **The `config/` directory is the expensive mistake.** The tarball contains a
> placeholder `app.php`. Copying the directory wholesale overwrites your
> database credentials *and* your salts — and replacing the salts logs every
> member out and invalidates every outstanding password-reset link. Exclude the
> directory and merge new keys by hand, see the next step.

Note that `config/routes.php` is **application code**, not configuration,
despite living in `config/`. If you deploy by excluding the whole directory,
copy that one file across explicitly — an installation running an old
`routes.php` against new controllers fails in ways that look unrelated to the
update.

## 3. Merge new configuration keys

Releases add options rather than requiring them, so an untouched configuration
keeps working. Still, this is the moment where you would notice something new.

```bash
diff -u /path/to/forum/config/saito_config.php "saito-$V/config/saito_config.php"
diff -u /path/to/forum/config/app.php          "saito-$V/config/app.php"
```

Read the diff and copy individual keys. Do not replace the files.

## 4. Clear the caches

Route and configuration caches survive a file copy and then describe an
application that no longer exists. The errors that produces read like code
bugs, which is why this step is worth doing even when it seems unnecessary.

```bash
rm -rf /path/to/forum/tmp/cache/models/* /path/to/forum/tmp/cache/persistent/*
```

## 5. Let the updater run

Open the forum in a browser. Saito compares the version recorded in the
database with the version of the code; if they differ it routes you to the
updater instead of the front page. Confirm, and it applies any pending
migrations and records the new version.

> **Check what is pending first if you are crossing 8.3.0.** That release carries
> a migration that moves the core tables to InnoDB, and on an installation whose
> `entries` is still MyISAM it rewrites the table — measured at five and a half
> minutes for 680,000 postings, with the table locked throughout. PHP's execution
> limit will cut that short through the browser: the server finishes the
> conversion anyway, but it may then not be recorded as applied. Run it from the
> command line instead, and read the database section of
> [upgrade.md](upgrade.md) for what to expect.
>
> ```bash
> cd /path/to/forum
> php bin/cake.php migrations status   # what is pending
> php bin/cake.php migrations migrate
> php bin/cake.php schema_cache clear  # not optional -- see below
> ```
>
> **The cache clear belongs to the migration, not after it.** 8.3.0 drops six
> columns from `users`, and CakePHP keeps each table's column list in
> `tmp/cache/models`. Until that is cleared, every request still asks the
> database for a column that no longer exists and gets a 500 — on every page,
> for everyone logged in. Reload PHP-FPM afterwards.

**This is expected, not an error.** If you deploy without the updater — from a
configuration-management tool, say — remember that the database version has to
be set as well, or every request keeps landing on the updater.

## 6. Check

- The front page loads and the footer shows the new version.
- A posting opens and a reply can be written.
- Logging in and out works.
- The administration area opens.
- An uploaded image still displays.
- `logs/error.log` has nothing new in it.

## If it goes wrong

```bash
rm -rf /path/to/forum && tar xzf saito-before-update.tar.gz -C /
gunzip < saito-before-update.sql.gz | mysql -u <user> -p <database>
```

Migrations carry a `down()`, but restoring the backup is faster and leaves less
to reason about. If the update crossed a PHP requirement, put PHP back too —
restoring the code alone leaves a forum that still does not start.

## Web server

On Apache the rewrite rules live in `.htaccess` files, which the tarball
brings along; they are easy to miss when copying, because they are hidden.

On **nginx `.htaccess` does nothing at all** — it is not read, and no warning is
issued. Everything those files express has to exist in the server
configuration instead. See [deployment-debian.md](deployment-debian.md) for a
working setup, and treat a move from Apache to nginx as its own project rather
than a step inside an update.
