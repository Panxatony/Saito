#!/bin/sh
#
# Has anybody looked at Dependabot's ignore list lately?
#
# Run before a tag is built, next to dev/check-changelog.sh, and for the same
# reason: it guards the one thing no other signal produces.
#
# An `ignore` entry is a decision that was right when it was written. It then
# ages in silence — and worse than in silence, because the entry suppresses the
# very pull request that would tell you the reason has gone. On 2026-08-31
# `squizlabs/php_codesniffer` was still pinned to `^3` "because
# cakephp-codesniffer and slevomat require ^3". They had both moved to `^4`
# weeks earlier, and our own constraint was by then the reason Dependabot could
# not update cakephp-codesniffer at all. The shield had become a brake, and
# nothing said so.
#
# So this does not try to judge whether a reason still holds — it cannot read
# prose, and a resolution test would flag the two entries whose blocker is our
# own test suite, every single time, until it was ignored like everything that
# cries wolf. It asks a smaller question, the one that actually failed: **when
# did a human last read this list?**
#
# Each entry carries a `# reviewed: YYYY-MM-DD` line. Look at the list, satisfy
# yourself the reasons still hold, set the date. That is the whole ritual, and
# it costs a minute.
#
# Usage:  sh dev/check-ignores.sh [max-age-days] [config-path]
#
# Exits 0 when every entry has been reviewed within the window, 1 when one has
# not, 2 on a usage error.

set -eu

MAX_AGE_DAYS="${1:-60}"
CONFIG="${2:-.github/dependabot.yml}"

if [ ! -f "$CONFIG" ]; then
    echo "check-ignores: $CONFIG not found (run from the repository root)" >&2
    exit 2
fi

TODAY=$(date +%s)

# Walk the file, tracking which ecosystem block we are in, and pair every
# `- dependency-name:` with the `# reviewed:` line directly above it. Directly
# above on purpose: a marker further away drifts from the entry it describes and
# ends up vouching for a neighbour.
awk '
    /^[[:space:]]*-[[:space:]]*package-ecosystem:/ {
        eco = $NF
        next
    }
    /^[[:space:]]*#[[:space:]]*reviewed:/ {
        reviewed = $NF
        next
    }
    /^[[:space:]]*-[[:space:]]*dependency-name:/ {
        printf "%s\t%s\t%s\n", eco, $NF, (reviewed == "" ? "-" : reviewed)
        reviewed = ""
        next
    }
    # Anything else breaks the adjacency: a marker only counts for the line
    # that follows it.
    { reviewed = "" }
' "$CONFIG" > /tmp/check-ignores.$$

STALE=0
COUNT=0

while IFS='	' read -r ECO NAME REVIEWED; do
    COUNT=$((COUNT + 1))

    # Best-effort: what is the newest release out there? Purely to make looking
    # cheap. A registry that is slow or unreachable must never fail a release,
    # so every failure here degrades to "?".
    LATEST='?'
    case "$ECO" in
        composer)
            # Anchor both ends of the sed: `s/.*"//` is greedy and eats the
            # version along with everything before it, which is how this
            # silently reported "?" for every composer package on first run.
            LATEST=$(curl -sS --max-time 5 "https://repo.packagist.org/p2/${NAME}.json" 2>/dev/null \
                | tr ',' '\n' | grep -o '"version":"v\{0,1\}[0-9][0-9.]*"' \
                | sed 's/^"version":"//;s/"$//;s/^v//' \
                | sort -t. -k1,1n -k2,2n -k3,3n | tail -1) || LATEST='?'
            ;;
        npm)
            LATEST=$(curl -sS --max-time 5 "https://registry.npmjs.org/${NAME}/latest" 2>/dev/null \
                | tr ',' '\n' | grep -o '"version":"[^"]*"' | sed 's/.*://;s/"//g' | head -1) || LATEST='?'
            ;;
    esac
    [ -n "$LATEST" ] || LATEST='?'

    if [ "$REVIEWED" = "-" ]; then
        printf '  %-9s %-30s newest %-10s reviewed: NEVER\n' "$ECO" "$NAME" "$LATEST"
        STALE=$((STALE + 1))
        continue
    fi

    # date -d is GNU; BSD date wants -j -f. Try both, and treat an unparsable
    # date as stale rather than as fine.
    WHEN=$(date -d "$REVIEWED" +%s 2>/dev/null || date -j -f %Y-%m-%d "$REVIEWED" +%s 2>/dev/null || echo '')
    if [ -z "$WHEN" ]; then
        printf '  %-9s %-30s newest %-10s reviewed: %s (unreadable date)\n' "$ECO" "$NAME" "$LATEST" "$REVIEWED"
        STALE=$((STALE + 1))
        continue
    fi

    AGE=$(( (TODAY - WHEN) / 86400 ))
    if [ "$AGE" -gt "$MAX_AGE_DAYS" ]; then
        printf '  %-9s %-30s newest %-10s reviewed: %s (%s days ago)  STALE\n' "$ECO" "$NAME" "$LATEST" "$REVIEWED" "$AGE"
        STALE=$((STALE + 1))
    else
        printf '  %-9s %-30s newest %-10s reviewed: %s (%s days ago)\n' "$ECO" "$NAME" "$LATEST" "$REVIEWED" "$AGE"
    fi
done < /tmp/check-ignores.$$

rm -f /tmp/check-ignores.$$

if [ "$COUNT" -eq 0 ]; then
    echo "check-ignores: no ignore entries in $CONFIG — nothing to review."
    exit 0
fi

if [ "$STALE" -gt 0 ]; then
    cat >&2 <<MSG

check-ignores: $STALE of $COUNT entries have not been reviewed in $MAX_AGE_DAYS days.

Read the list above and ask, for each one, whether its reason still holds — the
comments in $CONFIG say what each reason was. Then record that you looked:

    # reviewed: $(date +%Y-%m-%d)
    - dependency-name: …

An entry whose reason has gone should be removed, not re-dated.
MSG
    exit 1
fi

echo "check-ignores: all $COUNT entries reviewed within $MAX_AGE_DAYS days."
