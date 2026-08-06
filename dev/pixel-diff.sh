#!/usr/bin/env bash
#
# Does the release purge change how the forum looks? Renders real pages against
# the stylesheet before and after `dev/purge-css.js` and counts differing pixels.
#
# Run this after touching `purgecss.config.js`. Zero across the board is the
# gate. Any other number means a class is missing from the safelist — not that
# the number is small enough.
#
#   dev/pixel-diff.sh                    # against the test forum
#   PXHOST=https://example.org dev/pixel-diff.sh
#
# Needs chromium and python3-pil, and a Saito instance to mirror pages from —
# the markup has to be real, because that is what decides which rules match.
set -euo pipefail

cd "$(dirname "$0")/.."
WORK="${TMPDIR:-/tmp}/saito-pixel-diff"
mkdir -p "$WORK"

THEMES=(Bota Nova Macnemo)
PRESETS=(theme night)
PAGES=(/ /users/login /entries/add)
WIDTHS=(375 800 1440)

echo "== Referenz bauen (ungepurgt)"
yarn css >/dev/null
yarn css:postcss >/dev/null
for t in "${THEMES[@]}"; do
  for p in "${PRESETS[@]}"; do
    cp "plugins/$t/webroot/css/$p.css" "$WORK/$t-$p-ref.css"
  done
done
cp webroot/css/stylesheets/static.css "$WORK/static-ref.css"

echo "== Purge anwenden"
node dev/purge-css.js
for t in "${THEMES[@]}"; do
  for p in "${PRESETS[@]}"; do
    cp "plugins/$t/webroot/css/$p.css" "$WORK/$t-$p-new.css"
  done
done
cp webroot/css/stylesheets/static.css "$WORK/static-new.css"

echo "== Vergleichen"
port=9300
fail=0
run() { # ref new label width [static]
  port=$((port + 1))
  local out
  out=$(PXSTATIC="${5:-}" PXPORT=$port python3 dev/pixel-diff.py "$4" "$1" "$2" "$3" "${6:-1280}" 2>&1 | grep "$3:" || true)
  echo "$out"
  grep -q " 0 von " <<<"$out" || fail=1
}

for t in "${THEMES[@]}"; do
  for p in "${PRESETS[@]}"; do
    run "$WORK/$t-$p-ref.css" "$WORK/$t-$p-new.css" "$t-$p" /
  done
done
run "$WORK/static-ref.css" "$WORK/static-new.css" static / 1
for pg in "${PAGES[@]:1}"; do
  run "$WORK/Macnemo-theme-ref.css" "$WORK/Macnemo-theme-new.css" "page$(tr / _ <<<"$pg")" "$pg"
done
for w in "${WIDTHS[@]}"; do
  run "$WORK/Macnemo-theme-ref.css" "$WORK/Macnemo-theme-new.css" "w$w" / "" "$w"
done

# Leave the tree with unpurged stylesheets: the purge belongs to a release, and
# a developer who forgets this ran would otherwise be looking at trimmed CSS.
yarn css >/dev/null

if [ "$fail" -ne 0 ]; then
  echo "FEHLGESCHLAGEN — mindestens ein Vergleich ist nicht null."
  exit 1
fi
echo "Alle Vergleiche null."
