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
            URL="https://forum.panxatony.net"
            ;;
        beta)
            COPY_HOST="vserver31-macnemo-saito8"
            ROOT="/usr/local/www/saito"
            OWNER="www:www"
            RELOAD="service php_fpm reload"
            URL="https://saito8-alpha.macnemo.de"
            ;;
        prod)
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
LIST=$(rsync_to_target -in | grep -E '^>f' | grep -vE '^>f\.\.t' || true)
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
# Same reason as the pre-flight probe: an installation older than 8.4.14 has no
# such command. Cake's "Unknown command" is four lines of suggestions and looks
# alarming in the middle of a deploy, so it is caught and explained instead —
# with the SQL to run by hand, because the row does have to be set.
if on_target "php bin/cake.php db_version '$VERSION' 2>/dev/null | grep -qE 'database:|nothing to do'"; then
    on_target "php bin/cake.php db_version"
else
    echo "This installation has no db_version command yet (it arrives with this"
    echo "release). Set the row by hand, once:"
    echo "    UPDATE settings SET value='$VERSION' WHERE name='db_version';"
    echo "From the next deployment onwards this step runs itself."
fi

say "reload"
on_host "$RELOAD" >/dev/null 2>&1 || true
sleep 3

# --- 7. verify ---------------------------------------------------------------
say "verify"
FAIL=0
for path in "/" "/login"; do
    code=$(curl -sS -o /dev/null -w '%{http_code}' "$URL$path" || echo 000)
    printf '  %-8s HTTP %s\n' "$path" "$code"
    [ "$code" = "200" ] || FAIL=1
done
printf '  version on target: '
on_target "grep -oE \"8[.][0-9]+[.][0-9]+\" src/Lib/version.php | head -1"
# Suppressed for the same reason as above, and stderr with it: on an
# installation without the command, cake's suggestion list is the last thing
# printed before "deployed" and reads like the deploy failed.
on_target "php bin/cake.php db_version 2>/dev/null" || true

if [ "$FAIL" -ne 0 ]; then
    say "SOMETHING IS WRONG"
    echo "The forum did not answer with 200. Backups are on the target as *.$STAMP"
    echo "and vendor.$STAMP. Look at the web server's error log before anything else."
    exit 1
fi

say "deployed"
echo "$TARGET is on $VERSION."
