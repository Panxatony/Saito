#!/bin/sh
#
# Put a released version onto an installation.
#
#     dev/deploy.sh test 8.4.13            # show what would happen
#     dev/deploy.sh test 8.4.13 --go       # do it
#
# Written after doing this by hand a dozen times in one week and getting it
# wrong twice. Both mistakes were the same shape: deciding *what to copy* from
# memory. This script decides the other way round — everything in the release
# package is copied, and only a short, named list is protected. What you forget
# then gets updated instead of quietly staying behind, and that failure is
# visible immediately rather than months later.
#
# The two it would have prevented:
#
#   - `config/` was skipped wholesale because `app.php` and `saito_config.php`
#     live there. So does `config/bootstrap.php`, which is not
#     installation-specific: production ran the old one against a new `vendor/`
#     and answered HTTP 500 for five minutes (2026-08-17).
#   - `config/routes.php` is not installation-specific either, and had been
#     stale on production since the SPA teardown. Every published posting URL
#     redirected to the login page for weeks (found 2026-08-21).
#
# Not a replacement for reading the CHANGELOG. It will not run migrations, and
# it says so when the release adds any.

set -eu

# --- targets -----------------------------------------------------------------
# `COPY_HOST` empty means the installation is on this machine; otherwise it is
# the ssh destination. on_target()/on_host() branch on it.
target_config() {
    case "$1" in
        test)
            COPY_HOST=""
            ROOT="/var/www/saito"
            OWNER="www-data:www-data"
            RELOAD="systemctl reload php8.4-fpm"
            # No config/.env here: the connection is an env[] line on the FPM
            # pool, which the web server has and a shell does not.
            POOL_CONF="/etc/php/8.4/fpm/pool.d/saito.conf"
            VERIFY="https://forum.panxatony.net"
            ;;
        beta)
            COPY_HOST="vserver31-macnemo-saito8"
            ROOT="/usr/local/www/saito"
            OWNER="www:www"
            RELOAD="service php_fpm reload"
            POOL_CONF=""
            VERIFY="https://saito8-alpha.macnemo.de"
            ;;
        prod)
            COPY_HOST="vserver31-macnemo"
            ROOT="/usr/local/www/apache24/data/macnemo-backend/forum"
            OWNER="www:www"
            RELOAD="service php_fpm reload"
            POOL_CONF=""
            # Anubis sits in front of this one and answers 200 with a
            # proof-of-work page to anything that cannot run JavaScript — curl
            # included. Verifying against the public URL therefore measures the
            # bot wall and not the forum, so this goes straight at the vhost
            # behind it.
            VERIFY="-H Host:macnemo.de http://127.0.0.1:8081"
            ;;
        *)
            echo "unknown target: $1 (test|beta|prod)" >&2
            exit 2
            ;;
    esac
}

# --- what the installation owns, and this must never touch -------------------
# Established by comparing every file in config/ between the package and a live
# installation: exactly these three differ. Everything else in config/ is stock
# code that belongs to the release — routes.php among it, which is how it went
# stale for weeks.
PROTECTED="
config/app.php
config/saito_config.php
config/.env
tmp
logs
webroot/useruploads
"

usage() {
    echo "usage: dev/deploy.sh <test|beta|prod> <version> [--go]" >&2
    exit 2
}

[ $# -ge 2 ] || usage
TARGET="$1"
VERSION="$2"
GO="${3:-}"
target_config "$TARGET"

if [ "$GO" = "--go" ]; then
    MODE="LIVE"
else
    MODE="dry run — nothing will be changed; add --go to deploy"
fi

say() { printf '\n=== %s\n' "$1"; }

# Run a command in the installation directory.
#
# The remote half is piped over stdin rather than passed as arguments, and that
# is not a style choice. `ssh host sh -c "cd '$ROOT' && cmd"` joins its arguments
# into one line and hands it to the *login* shell, which is csh in these jails.
# csh takes the `&&` for itself: `sh -c cd '/usr/local/www/saito'` runs as its
# own command and does nothing, then csh runs the rest — in /root.
#
# So every command ran in the wrong directory, and a failing `cd` stopped
# nothing. `test -f config/.env` answered "no such file" and the check written
# to prevent the 2026-08-10 outage reported "nothing to check" on the very
# installation it was meant to protect. Nothing failed; that was the problem.
#
# Piping to a bare `sh` gives ssh a single argument with no metacharacters, so
# there is nothing for csh to reinterpret. Verified by prove_target_dir below.
on_target() {
    if [ -z "$COPY_HOST" ]; then
        sudo sh -c "cd '$ROOT' && $1"
    else
        printf 'cd %s || exit 1\n%s\n' "$ROOT" "$1" | ssh "$COPY_HOST" sh
    fi
}

# Run a command on the host, not tied to the installation directory.
on_host() {
    if [ -z "$COPY_HOST" ]; then
        sudo sh -c "$1"
    else
        printf '%s\n' "$1" | ssh "$COPY_HOST" sh
    fi
}

# Refuse to go on unless on_target really lands in $ROOT. The bug above was
# invisible precisely because everything downstream reported success, so this
# asks the one question that would have caught it, before anything is touched.
prove_target_dir() {
    where=$(on_target "pwd" 2>/dev/null || true)
    if [ "$where" != "$ROOT" ]; then
        echo "REFUSING: commands on $TARGET run in '${where:-nowhere}', not '$ROOT'." >&2
        echo "Backups and safety checks would silently do nothing. Fix on_target first." >&2
        exit 1
    fi
    echo "commands land in $ROOT"
}

echo "target:  $TARGET ($ROOT)"
echo "version: $VERSION"
echo "mode:    $MODE"

# --- 1. the package ----------------------------------------------------------
say "release package"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
gh release download "$VERSION" --pattern "saito-$VERSION.tar.gz*" --dir "$WORK" >/dev/null
( cd "$WORK" && sha256sum -c "saito-$VERSION.tar.gz.sha256" )
( cd "$WORK" && tar xzf "saito-$VERSION.tar.gz" )
PKG="$WORK/saito-$VERSION"
[ -f "$PKG/src/Lib/version.php" ] || { echo "package looks wrong" >&2; exit 1; }
grep -q "'v' => '$VERSION'" "$PKG/src/Lib/version.php" \
    || { echo "package version.php does not say $VERSION" >&2; exit 1; }
echo "verified, and it says $VERSION"

# --- 2. pre-flight -----------------------------------------------------------
say "pre-flight"
prove_target_dir
printf 'installed now:   '
on_target "grep -oE \"8[.][0-9]+[.][0-9]+\" src/Lib/version.php | head -1" || true
# `db_version` needs the database, and the console may not have it: where the
# connection is an env[] line on the FPM pool rather than config/.env, the web
# server has it and a shell does not. POOL_CONF names that file per target so
# the value can be lifted from it; without one, the command runs as it is.
DB_ENV=""
if [ -n "${POOL_CONF:-}" ]; then
    DB_URL=$(on_host "grep -oE '^env\\[DATABASE_URL\\][[:space:]]*=[[:space:]]*.*' '$POOL_CONF' 2>/dev/null | head -1 | sed -E 's/^[^=]*=[[:space:]]*//; s/^\"//; s/\"$//'" || true)
    if [ -n "$DB_URL" ]; then
        DB_ENV="DATABASE_URL='$DB_URL'"
        echo "(connection read from $POOL_CONF)"
    fi
fi

# `db_version` arrived in 8.4.14, so an older installation does not have it —
# not a reason to stop, since the row is set with the same command once the
# files are in place. Emptiness rather than exit status: an unknown command
# still leaves cake exiting zero.
DBV=$(on_target "$DB_ENV php bin/cake.php db_version 2>/dev/null | sed -n 's/^database: *//p'" 2>/dev/null || true)
if [ -n "$DBV" ]; then
    echo "database says:   $DBV"
else
    echo "database says:   (not available yet — older than the db_version command)"
fi

# The check that would have saved production on 2026-08-10: since 8.4.10 an
# unquoted value containing a space stops the forum from starting at all.
say "config/.env — values with spaces must be quoted"
if on_target "test -f config/.env"; then
    if on_target "grep -nE '=[^\"'\\''][^\"'\\'']*[[:space:]]' config/.env"; then
        echo "REFUSING: quote the values above first — the forum will not start otherwise" >&2
        exit 1
    fi
    echo "clean"
else
    echo "no config/.env on this installation — nothing to check"
fi

# This used to print `ls config/Migrations | tail -3` from the package and ask
# the operator to work out whether any were new. Two things were wrong with it.
# The package carries *every* migration ever written, so the three newest are
# not "the migrations in this release"; and a release adding four would have
# shown three, hiding one under a heading that claimed to list them all.
#
# Now it compares against the target and names exactly what is missing there.
# Possible only since remote commands started running in the right directory —
# before that this could not have asked the question at all.
say "migrations the target does not have yet"
if [ -d "$PKG/config/Migrations" ]; then
    find "$PKG/config/Migrations" -maxdepth 1 -name '*.php' -exec basename {} \; \
        | sort > "$WORK/mig-pkg"
    on_target "find config/Migrations -maxdepth 1 -name '*.php' -exec basename {} ';' 2>/dev/null | sort" \
        > "$WORK/mig-target" 2>/dev/null || : > "$WORK/mig-target"
    if [ ! -s "$WORK/mig-target" ]; then
        echo "  (could not read the target's migrations — check by hand)"
    elif comm -23 "$WORK/mig-pkg" "$WORK/mig-target" | grep -q .; then
        comm -23 "$WORK/mig-pkg" "$WORK/mig-target" | sed 's/^/  /'
        echo "  ^ run bin/cake migrations migrate on the target *after* this deploy"
    else
        echo "  none — the target already has every migration in this package"
    fi
fi

# --- 3. what would change ----------------------------------------------------
say "files whose content differs"
EXCLUDES=""
for p in $PROTECTED; do
    EXCLUDES="$EXCLUDES --exclude=/$p"
done

# One transfer mechanism for every target. An earlier version claimed the
# FreeBSD jails had no rsync and fell back to a tar pipe there, which is why
# beta and prod could not be dry-run at all. Both have /usr/local/bin/rsync
# (checked 2026-08-21); the claim was never true.
rsync_to_target() {  # $1: extra flags, e.g. -n for a dry run
    if [ -z "$COPY_HOST" ]; then
        # shellcheck disable=SC2086
        sudo rsync -a $1 --no-perms --no-owner --no-group $EXCLUDES "$PKG/" "$ROOT/"
    else
        # shellcheck disable=SC2086
        rsync -a $1 --no-perms --no-owner --no-group $EXCLUDES "$PKG/" "$COPY_HOST:$ROOT/"
    fi
}

# `>f..t` is a file whose *timestamp* differs and whose content does not —
# nearly every file in a fresh package, and pure noise in this list. Only size
# ("s"), checksum ("c") and new files ("+++") say anything.
#
# `|| true` is load-bearing under `set -e`: grep exits 1 when it matches
# nothing, and "nothing differs" is the *normal* result of deploying a version
# that is already there. Without it the script died right here, silently and
# with status 0, before backing anything up — found by running this against the
# test forum twice in a row (2026-08-21).
# `>f` and `<f`: rsync's first character is the *direction*, `>` when the file
# is received (a local destination) and `<` when it is sent (a remote one).
# Matching only `>f` meant every remote dry run reported "changed files: 0" —
# not because nothing differed, but because nothing matched. Beta and prod
# never once produced a real list, and said so as if they had.
#
# `-c` for the listing only. Without it rsync decides by size and mtime, so a
# file edited without changing length shows as timestamp-only and is filtered
# out — which hid `src/Lib/version.php`, of all files, from every list this
# ever printed. The transfer below keeps the default: the package's mtimes are
# always newer, and checksumming the tree twice buys nothing there.
LIST=$(rsync_to_target -inc | grep -E '^[<>]f' | grep -vE '^[<>]f\.\.t' || true)
if [ -n "$LIST" ]; then
    echo "$LIST" | head -40
    COUNT=$(echo "$LIST" | grep -c .)
    [ "$COUNT" -gt 40 ] && echo "  … and $((COUNT - 40)) more"
else
    COUNT=0
    echo "  none — this installation already has this content"
fi
echo "changed files: $COUNT"

# Nothing here removes files, deliberately: no --delete anywhere. It is what
# lets an installation keep things the release does not ship — the Macfix
# theme on beta, which lives only on the theme/macfix branch, and the .bak
# files an operator left in config/. A --delete added later would take both.


if [ "$MODE" != "LIVE" ]; then
    say "dry run finished"
    echo "nothing was changed. Re-run with --go to deploy."
    exit 0
fi

# --- 4. backups --------------------------------------------------------------
say "backups"
STAMP="pre-$VERSION"
# `[ ! -f ... ]` for the same reason as vendor below: on a second run of the same
# version the file has already been replaced, so copying again would overwrite
# the backup with the new content and lose the state it exists to preserve.
on_target "for f in src/Lib/version.php config/bootstrap.php config/routes.php composer.lock CHANGELOG.md; do
    [ -f \"\$f\" ] && [ ! -f \"\$f.$STAMP\" ] && cp -p \"\$f\" \"\$f.$STAMP\"
done; echo 'key files backed up as *.$STAMP'"
# Only when there is no backup yet, and for two reasons. `cp -a vendor
# vendor.pre-X` copies *into* an existing directory of that name rather than
# replacing it, so a repeated deploy of the same version nests a 353 MB tree
# inside the last one (seen on test, 2026-08-21). And the first backup is the
# one worth keeping: it holds the state before this version was ever here.
on_target "if [ ! -d vendor.$STAMP ] && [ -d vendor ]; then
    cp -a vendor vendor.$STAMP && echo 'vendor backed up'
else
    echo 'vendor.$STAMP already exists — keeping the earlier one'
fi"

# --- 5. transfer -------------------------------------------------------------
say "transfer"
rsync_to_target ""
echo "done"

# --- 6. settle ---------------------------------------------------------------
say "ownership, syntax, caches"
on_target "chown -R $OWNER src plugins templates webroot bin docs vendor config CHANGELOG.md composer.json composer.lock index.php 2>/dev/null || true"
on_target "php -l config/bootstrap.php && php -l config/routes.php && php -l src/Lib/version.php"
on_target "find tmp/cache -type f -delete 2>/dev/null || true"

say "database version"
# Two different things can stop this working, and the earlier version reported
# both as the first one — telling the operator the command was missing on an
# installation that had just received it. The message was wrong in exactly the
# way this release is about, so it now reads the output and says which it is.
#
# `db_version` needs the database, and the console may not have it: where the
# connection is an env[] line on the FPM pool rather than config/.env, the web
# server has it and a shell does not. POOL_CONF names that file per target so
# the value can be lifted from it; without one, the command runs as it is.
DBOUT=$(on_target "$DB_ENV php bin/cake.php db_version '$VERSION' 2>&1" || true)
case "$DBOUT" in
    *"Unknown command"*)
        echo "This installation has no db_version command yet — it arrives with"
        echo "this release, so from the next deployment this step runs itself."
        echo "Set the row by hand, once:"
        echo "    UPDATE settings SET value='$VERSION' WHERE name='db_version';"
        ;;
    *MissingConnectionException*|*"Access denied"*|*"could not be established"*)
        echo "The command is installed but cannot reach the database from the"
        echo "console — the connection is not in config/.env and no pool file is"
        echo "configured for this target. Either add POOL_CONF above, or run:"
        echo "    DATABASE_URL='mysql://…' bin/cake db_version $VERSION"
        ;;
    *)
        echo "$DBOUT" | sed 's/^/  /'
        ;;
esac

say "reload"
on_host "$RELOAD" >/dev/null 2>&1 || true
sleep 3

# --- 7. verify ---------------------------------------------------------------
say "verify"
# A status code is not enough. Production sits behind Anubis, which answers 200
# with "Making sure you're not a bot!" to anything without JavaScript — so the
# old check passed while measuring the wall, and would have passed just the same
# with the forum broken behind it. Every page is now also asked for a marker
# only Saito emits.
FAIL=0
for path in "/" "/login"; do
    body=$(on_host "curl -sS -o /tmp/saito-verify.html -w '%{http_code}' $VERIFY$path" 2>/dev/null || echo 000)
    marker=$(on_host "grep -c 'meta name=\"csrf-token\"' /tmp/saito-verify.html 2>/dev/null" || echo 0)
    if [ "$body" = "200" ] && [ "$marker" -ge 1 ]; then
        printf '  %-8s HTTP 200, and it is the forum\n' "$path"
    elif [ "$body" = "200" ]; then
        printf '  %-8s HTTP 200 but no Saito markup — a bot wall or an error page?\n' "$path"
        FAIL=1
    else
        printf '  %-8s HTTP %s\n' "$path" "$body"
        FAIL=1
    fi
done
on_host "rm -f /tmp/saito-verify.html" || true
printf '  version on target: '
on_target "grep -oE \"8[.][0-9]+[.][0-9]+\" src/Lib/version.php | head -1"
on_target "$DB_ENV php bin/cake.php db_version 2>/dev/null" || true

if [ "$FAIL" -ne 0 ]; then
    say "SOMETHING IS WRONG"
    echo "The forum did not answer with 200. Backups are on the target as *.$STAMP"
    echo "and vendor.$STAMP. Look at the web server's error log before anything else."
    exit 1
fi

say "deployed"
echo "$TARGET is on $VERSION."
