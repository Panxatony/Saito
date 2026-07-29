#!/bin/sh
#
# Does CHANGELOG.md describe this release?
#
# Run before a tag is built. Version, tag and tarball all come out of the
# pipeline by themselves; the description does not, and nothing used to notice
# when it was missing — the release job simply fell back to publishing the bare
# version string as its own release notes. That is how 8.2.2, 8.2.3 and 8.2.4
# were built and published with no record of what changed in them, and how
# 8.0.12 and 8.0.13 lost their sections before that.
#
# Usage:  sh dev/check-changelog.sh 8.2.5
#
# Exits 0 when the section is there and says something, 1 when it is missing or
# empty, 2 on a usage error.

set -eu

VERSION="${1:-}"
CHANGELOG="${2:-CHANGELOG.md}"

if [ -z "$VERSION" ]; then
    echo "usage: $0 <version> [changelog-path]" >&2
    exit 2
fi

if [ ! -f "$CHANGELOG" ]; then
    echo "check-changelog: $CHANGELOG not found (run from the repository root)" >&2
    exit 2
fi

# The heading the release job looks for, and the one a reader scrolls to. The
# trailing " - " is part of the test on purpose: a section opened as
# "## [8.2.5] -" with no date is a placeholder somebody meant to come back to.
if ! grep -q "^## \[${VERSION}\] - ." "$CHANGELOG"; then
    cat >&2 <<MSG
check-changelog: $CHANGELOG has no dated section for ${VERSION}.

Add one before tagging:

    ## [${VERSION}] - $(date +%Y-%m-%d)

    - [Full commit-log](https://github.com/Panxatony/Saito/compare/<previous>...${VERSION})

    <whether upgrading needs more than a code deploy>

    ### Changes

    - ✓ Fix: …

Without it the release publishes with its version number as the only note.
MSG
    exit 1
fi

# A heading alone is not a description. Count the lines of the section that
# carry something other than the heading, the compare link and whitespace.
BODY=$(awk -v v="$VERSION" '
    $0 ~ "^## \\[" v "\\] - " { grab = 1; next }
    grab && /^## \[/          { exit }
    grab                      { print }
' "$CHANGELOG" | grep -vcE '^[[:space:]]*$|^- \[Full commit-log\]|^### ' || true)

if [ "${BODY:-0}" -lt 1 ]; then
    echo "check-changelog: the ${VERSION} section is empty — it needs to say what changed." >&2
    exit 1
fi

echo "check-changelog: ${VERSION} is described in $CHANGELOG (${BODY} lines)."
