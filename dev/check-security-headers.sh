#!/bin/sh
#
# Are the security headers actually reaching the browser?
#
# Written because they stopped doing so on one installation and nothing
# noticed: the shipped nginx example gained the headers on its static and
# upload locations, the deployed vhost never did, and for months uploads were
# served without `X-Content-Type-Options`. Nothing fails visibly when that
# happens — which is exactly why it needs a check that runs on a schedule
# rather than a pair of eyes.
#
# Three probes per installation, because nginx applies headers per location and
# they drift independently:
#
#   /                  a dynamic page (PHP; app + edge both contribute)
#   the first .css     a static file (edge only — PHP never sees it)
#   /useruploads/…     an upload path (edge only, attacker-controlled content)
#
# The upload probe deliberately requests a file that does not exist: a 404 from
# nginx still carries the location's `always` headers, so the check needs no
# fixture and no filename that could be deleted underneath it.
#
# Usage:  sh dev/check-security-headers.sh https://forum.example.com [...]
# Exits 0 when every probe carries what it must, 1 otherwise.

set -eu

if [ $# -eq 0 ]; then
    echo "usage: $0 <base-url> [<base-url> ...]" >&2
    exit 2
fi

fail=0

# Headers every response must carry, dynamic or not.
required_everywhere="x-content-type-options referrer-policy strict-transport-security"
# Only a page rendered by the application carries these.
required_dynamic="content-security-policy x-frame-options"

headers_of() {
    # -sS: quiet, but still complain on a connection error.
    curl -sSI --max-time 20 "$1" 2>/dev/null | tr 'A-Z' 'a-z'
}

check() {
    _url="$1"
    _label="$2"
    _needed="$3"

    _out=$(headers_of "$_url" || true)
    if [ -z "$_out" ]; then
        echo "  ✗ $_label — no response from $_url"
        fail=1
        return
    fi

    _status=$(printf '%s' "$_out" | head -1 | tr -d '\r')
    _missing=''
    for _h in $_needed; do
        printf '%s' "$_out" | grep -q "^$_h:" || _missing="$_missing $_h"
    done

    if [ -n "$_missing" ]; then
        echo "  ✗ $_label ($_status) — missing:$_missing"
        fail=1
    else
        echo "  ✓ $_label ($_status)"
    fi
}

for base in "$@"; do
    base=${base%/}
    echo "== $base"

    check "$base/" "dynamic page" "$required_everywhere $required_dynamic"

    # Discover a stylesheet from the page itself rather than hard-coding a
    # theme path: each installation ships a different theme, and a path that
    # 404s would pass a check that only looks at headers.
    css=$(curl -sS --max-time 20 "$base/" 2>/dev/null \
        | grep -oE 'href="[^"]+\.css[^"]*"' \
        | head -1 | sed -E 's/^href="//; s/"$//' || true)
    if [ -n "$css" ]; then
        case "$css" in
            http*) css_url="$css" ;;
            /*)    css_url="$base$css" ;;
            *)     css_url="$base/$css" ;;
        esac
        check "$css_url" "static asset" "$required_everywhere"
    else
        echo "  ✗ static asset — no stylesheet found to probe"
        fail=1
    fi

    # A path that cannot exist: the 404 still comes from the upload location,
    # which is the thing being tested.
    check "$base/useruploads/nonexistent-header-probe.jpg" "upload path" "$required_everywhere"
done

if [ "$fail" -ne 0 ]; then
    echo ""
    echo "Security headers are missing somewhere. A location block with its own"
    echo "add_header does NOT inherit the server-level ones in nginx, so each of"
    echo "them has to repeat the headers — see config/nginx/saito.conf.example."
    exit 1
fi

echo ""
echo "All probes carry the expected security headers."
