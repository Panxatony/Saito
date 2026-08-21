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
# `run` is how a command reaches the host: a local sudo, or ssh.
target_config() {
    case "$1" in
        test)
            RUN="sudo sh -c"
            COPY_HOST=""
            ROOT="/var/www/saito"
            OWNER="www-data:www-data"
            RELOAD="systemctl reload php8.4-fpm"
            URL="https://forum.panxatony.net"
            ;;
        beta)
            RUN="ssh vserver31-macnemo-saito8 sh -c"
            COPY_HOST="vserver31-macnemo-saito8"
            ROOT="/usr/local/www/saito"
            OWNER="www:www"
            RELOAD="service php_fpm reload"
            URL="https://saito8-alpha.macnemo.de"
            ;;
        prod)
            RUN="ssh vserver31-macnemo sh -c"
            COPY_HOST="vserver31-macnemo"
            ROOT="/usr/local/www/apache24/data/macnemo-backend/forum"
            OWNER="www:www"
            RELOAD="service php_fpm reload"
            URL="https://macnemo.de"
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
on_target() { $RUN "cd '$ROOT' && $1"; }

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
echo -n "installed now:   "
on_target "grep -oE \"8[.][0-9]+[.][0-9]+\" src/Lib/version.php | head -1" || true
# `db_version` arrived in 8.4.14. An older installation does not have it, which
# is not a reason to stop — the row is set with the same command after the files
# are in place, by which point it does. Checked for emptiness rather than by
# exit status: an unknown command still leaves cake exiting zero.
DBV=$(on_target "php bin/cake.php db_version 2>/dev/null | sed -n 's/^database: *//p'" 2>/dev/null || true)
if [ -n "$DBV" ]; then
    echo "database says:   $DBV"
else
    echo "database says:   (not available — older than the db_version command)"
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

say "migrations in this release"
if [ -d "$PKG/config/Migrations" ]; then
    ls "$PKG/config/Migrations" | tail -3
    echo "^ if any of these are new here, run bin/cake migrations migrate on the target *after* this"
fi

# --- 3. what would change ----------------------------------------------------
say "files whose content differs"
EXCLUDES=""
for p in $PROTECTED; do
    EXCLUDES="$EXCLUDES --exclude=/$p"
done

if [ -z "$COPY_HOST" ]; then
    # `>f..t` is a file whose *timestamp* differs and whose content does not —
    # nearly every file in a fresh package, and pure noise in this list. Only
    # size ("s"), checksum ("c") and new files ("+++") say anything.
    # shellcheck disable=SC2086
    LIST=$(sudo rsync -ain --no-perms --no-owner --no-group $EXCLUDES "$PKG/" "$ROOT/" \
        | grep -E '^>f' | grep -vE '^>f\.\.t')
    if [ -n "$LIST" ]; then
        echo "$LIST" | head -40
        echo "$LIST" | tail -n +41 | sed -n '1p;$p' >/dev/null
        COUNT=$(echo "$LIST" | grep -c .)
        [ "$COUNT" -gt 40 ] && echo "  … and $((COUNT - 40)) more"
    else
        COUNT=0
        echo "  none — this installation already has this content"
    fi
    echo "changed files: $COUNT"
else
    # No rsync in the FreeBSD jails, so a remote dry run cannot list files
    # without copying them. Say so rather than implying the list is empty.
    echo "  (remote target: rsync is not installed there, so the list cannot be"
    echo "   produced without transferring. Run the dry run against 'test' to see"
    echo "   what a release changes.)"
fi

if [ "$MODE" != "LIVE" ]; then
    say "dry run finished"
    echo "nothing was changed. Re-run with --go to deploy."
    exit 0
fi

# --- 4. backups --------------------------------------------------------------
say "backups"
STAMP="pre-$VERSION"
on_target "for f in src/Lib/version.php config/bootstrap.php config/routes.php composer.lock CHANGELOG.md; do
    [ -f \"\$f\" ] && cp -p \"\$f\" \"\$f.$STAMP\"
done; echo 'key files backed up as *.$STAMP'"
on_target "test -d vendor && cp -a vendor vendor.$STAMP && echo 'vendor backed up' || true"

# --- 5. transfer -------------------------------------------------------------
say "transfer"
if [ -z "$COPY_HOST" ]; then
    # shellcheck disable=SC2086
    sudo rsync -a --no-perms --no-owner --no-group $EXCLUDES "$PKG/" "$ROOT/"
else
    TAR_EXCLUDES=""
    for p in $PROTECTED; do
        TAR_EXCLUDES="$TAR_EXCLUDES --exclude=./$p"
    done
    # shellcheck disable=SC2086
    tar czf - -C "$PKG" $TAR_EXCLUDES . | ssh "$COPY_HOST" "tar xzf - -C '$ROOT'"
fi
echo "done"

# --- 6. settle ---------------------------------------------------------------
say "ownership, syntax, caches"
on_target "chown -R $OWNER src plugins templates webroot bin docs vendor config CHANGELOG.md composer.json composer.lock index.php 2>/dev/null || true"
on_target "php -l config/bootstrap.php && php -l config/routes.php && php -l src/Lib/version.php"
on_target "find tmp/cache -type f -delete 2>/dev/null || true"

say "database version"
on_target "php bin/cake.php db_version '$VERSION'"

say "reload"
$RUN "$RELOAD" >/dev/null 2>&1 || true
sleep 3

# --- 7. verify ---------------------------------------------------------------
say "verify"
FAIL=0
for path in "/" "/login"; do
    code=$(curl -sS -o /dev/null -w '%{http_code}' "$URL$path" || echo 000)
    printf '  %-8s HTTP %s\n' "$path" "$code"
    [ "$code" = "200" ] || FAIL=1
done
echo -n "  version on target: "
on_target "grep -oE \"8[.][0-9]+[.][0-9]+\" src/Lib/version.php | head -1"
on_target "php bin/cake.php db_version" || true

if [ "$FAIL" -ne 0 ]; then
    say "SOMETHING IS WRONG"
    echo "The forum did not answer with 200. Backups are on the target as *.$STAMP"
    echo "and vendor.$STAMP. Look at the web server's error log before anything else."
    exit 1
fi

say "deployed"
echo "$TARGET is on $VERSION."
