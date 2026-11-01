#!/bin/sh
#
# Status of Saito installations after an OS or package update.
#
#     sh scripts/status-check.sh URL... [-- SSH_HOST...]
#
# Example:
#     sh scripts/status-check.sh https://example.org/ https://example.org/entries/htmx-widgets \
#         -- edge-host forum-jail
#
# For every URL: HTTP status and content type. A bot filter in front of the
# forum answers 200 with an HTML challenge page, so for a stylesheet the content
# type is the signal, not the status.
#
# For every ssh host: OS patch level, uptime, whether nginx / php_fpm / anubis
# run (only those the host has), their start times, and `pkg audit`. Remote
# commands are piped to `sh` because the login shell on the FreeBSD hosts is
# csh, which misreads `2>&1`, `$(…)` and `&&` in arguments.
#
# Reads only; changes nothing.

set -u

urls=""
hosts=""
seen_sep=0
for arg in "$@"; do
    if [ "$arg" = "--" ]; then
        seen_sep=1
    elif [ "$seen_sep" -eq 0 ]; then
        urls="$urls $arg"
    else
        hosts="$hosts $arg"
    fi
done

if [ -z "$urls$hosts" ]; then
    sed -n '3,8p' "$0" >&2
    exit 2
fi

for url in $urls; do
    printf '%s  %s\n' \
        "$(curl -s -o /dev/null -m 15 -A 'Mozilla/5.0 (status-check)' -w '%{http_code} %{content_type}' "$url")" \
        "$url"
done

for host in $hosts; do
    echo "== $host"
    ssh "$host" sh <<'EOF'
# -k fails inside a jail (no kernel there) and takes -u down with it, so ask separately.
echo "userland $(freebsd-version -u 2>/dev/null)  kernel $(freebsd-version -k 2>/dev/null || echo '(jail)')"
uptime
for s in nginx php_fpm anubis; do
    out=$(service "$s" status 2>&1) || true
    case "$out" in
        *"does not exist"*) ;;
        *)
            echo "$out" | head -1
            pid=$(echo "$out" | sed -n 's/.*pid \([0-9]*\).*/\1/p')
            [ -n "$pid" ] && ps -o lstart= -p "$pid" | sed 's/^/    started /'
            ;;
    esac
done
echo "pkg audit: $(pkg audit -q 2>/dev/null | sort -u | tr '\n' ' ')"
EOF
done
