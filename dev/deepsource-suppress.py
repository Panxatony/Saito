#!/usr/bin/env python3
"""
Retire DeepSource occurrences that sit in excluded paths.

## Why this exists

`exclude_patterns` in `.deepsource.toml` stops the analyser from *looking*. It
does not retire what earlier runs already recorded: an occurrence is resolved
only by a run that looks at the file and no longer finds the problem, and a run
that never looks cannot do that. So an excluded path leaves orphans behind —
active occurrences for code nothing will examine again.

Measured on 2026-09-04: 1157 active occurrences, 1114 of them from the PHP
analyzer, and the arithmetic says where they came from — three runs that each
introduced ~372 of the same framework false positives and resolved none:

    e5f38dbbf  372  +  b7ce7a140  372  +  8030453e2  371  =  1115

Against 1114 active. The one missing is the single real finding in that set, a
method-name casing in BbcodeParser, which was fixed and therefore resolved.

## What it does not do

It does not decide anything. Nothing here judges whether a finding is a false
positive; the selection is by *path*, and the paths come from the exclude list
in `.deepsource.toml`. A rule that fires in code we still analyse is untouched.

There is no API to create an ignore rule — `Mutation` has no such field, and
the one rule this repository has was made in the dashboard. Per-occurrence
suppression is the only route the API offers.

## Order of operations

Run this *after* the exclusion patterns are confirmed to work, never before.
Suppressing an occurrence the next run will record again buys nothing and hides
the evidence that the patterns are still wrong. The check is in
`.deepsource.toml`: the next commit that touches a `.php` file must report 0
introduced.

## Usage

    export DEEPSOURCE_TOKEN=$(cat ~/deepsource.txt)

    dev/deepsource-suppress.py                 # dry run: show what would go
    dev/deepsource-suppress.py --apply         # actually suppress
    dev/deepsource-suppress.py --limit 5 --apply   # try a handful first

Dry run is the default and `--apply` is the only way past it, because this
writes to a dashboard that other people read.
"""

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

API = "https://api.deepsource.io/graphql/"
LOGIN, NAME, PROVIDER = "Panxatony", "Saito", "GITHUB"

# Paths whose occurrences are orphans: excluded in .deepsource.toml, so no
# future run will look at them. Kept as literal prefixes rather than globs —
# this decides what gets written to, and a prefix is something a reader can
# check against the exclude list by eye.
ORPHAN_PREFIXES = (
    "templates/",
    "config/",
    "dev/",
    "vendor/",
    "webroot/",
)
ORPHAN_PLUGIN_DIRS = ("templates/", "config/", "webroot/")

# Reason recorded on every suppression. FALSE_POSITIVE is the honest category:
# these are CakePHP's injected variables reported as undefined, not findings
# somebody decided to live with.
REASON_CATEGORY = "FALSE_POSITIVE"
REASON_TEXT = (
    "Path is excluded in .deepsource.toml; the analyzer no longer looks at it, "
    "so the occurrence can never be resolved by a run. Framework context "
    "(CakePHP injects these variables), not a defect."
)


def is_orphan(path: str) -> bool:
    """Does this path sit in a directory the config excludes?"""
    if path.startswith(ORPHAN_PREFIXES):
        return True
    # plugins/<Name>/{templates,config,webroot}/...
    parts = path.split("/")
    if len(parts) > 2 and parts[0] == "plugins":
        return f"{parts[2]}/" in ORPHAN_PLUGIN_DIRS
    return False


def call(token: str, query: str, variables: dict | None = None) -> dict:
    body = json.dumps({"query": query, "variables": variables or {}}).encode()
    req = urllib.request.Request(
        API,
        data=body,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            # Not optional. The API answers 403 to urllib's default
            # `Python-urllib/3.x`, and only to that — the same query through
            # curl is fine. Verified both ways on 2026-09-04, because a 403
            # reads like a bad token and would send the next reader looking in
            # the wrong place entirely.
            "User-Agent": "saito-dev-deepsource-suppress/1.0",
        },
    )
    for attempt in range(1, 4):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                out = json.load(r)
            break
        except (urllib.error.URLError, TimeoutError) as e:
            if attempt == 3:
                sys.exit(f"api unreachable after 3 attempts: {e}")
            time.sleep(attempt * 5)
    if "errors" in out:
        sys.exit("api error: " + out["errors"][0]["message"])
    return out["data"]


def wanted_occurrences(token: str) -> set[tuple[str, int, str]]:
    """Every active occurrence in an excluded path, keyed by where it is."""
    q = """
    query($a:String){repository(name:"%s",login:"%s",vcsProvider:%s){
      issueOccurrences(first:100,after:$a){
        pageInfo{hasNextPage endCursor}
        edges{node{path beginLine issue{shortcode}}}}}}
    """ % (NAME, LOGIN, PROVIDER)
    keys, after = set(), None
    while True:
        conn = call(token, q, {"a": after})["repository"]["issueOccurrences"]
        for e in conn["edges"]:
            n = e["node"]
            if is_orphan(n["path"]):
                keys.add((n["path"], n["beginLine"], n["issue"]["shortcode"]))
        if not conn["pageInfo"]["hasNextPage"]:
            return keys
        after = conn["pageInfo"]["endCursor"]


def check_issue_ids(token: str, runs: int) -> dict:
    """
    Collect every CheckIssue id that sits in an excluded path.

    The repository-level ids are `Occurrence:…` and the mutation wants
    `CheckIssue:…`, so they have to be gathered from the runs that recorded
    them, newest first.

    Deliberately *not* one id per location. The dashboard counts 1157
    occurrences over 324 distinct (file, line, rule) triples: the same finding
    is recorded again by every run that looks, and each recording has its own
    id. Keying by location would retire one of every three and leave the badge
    two-thirds unchanged — which would look like the API had refused, and send
    the next reader chasing the wrong problem.
    """
    runs_q = """
    query($a:String){repository(name:"%s",login:"%s",vcsProvider:%s){
      analysisRuns(first:20,after:$a){
        pageInfo{hasNextPage endCursor}
        edges{node{runUid createdAt}}}}}
    """ % (NAME, LOGIN, PROVIDER)
    issues_q = """
    query($u:UUID!,$a:String){run(runUid:$u){checks(first:20){edges{node{
      analyzer{shortcode}
      issues(first:100,after:$a){
        pageInfo{hasNextPage endCursor}
        edges{node{id shortcode path beginLine}}}}}}}}
    """

    found, seen_runs, after = {}, 0, None
    while seen_runs < runs:
        conn = call(token, runs_q, {"a": after})["repository"]["analysisRuns"]
        for e in conn["edges"]:
            if seen_runs >= runs:
                break
            uid = e["node"]["runUid"]
            seen_runs += 1
            cursor = None
            while True:
                checks = call(token, issues_q, {"u": uid, "a": cursor})["run"]["checks"]
                more = False
                for ce in checks["edges"]:
                    iss = ce["node"]["issues"]
                    for ie in iss["edges"]:
                        n = ie["node"]
                        if is_orphan(n["path"]):
                            found[n["id"]] = (n["path"], n["beginLine"], n["shortcode"])
                    if iss["pageInfo"]["hasNextPage"]:
                        more, cursor = True, iss["pageInfo"]["endCursor"]
                if not more:
                    break
            print(f"  run {uid[:8]}… scanned — {len(found)} ids so far")
        if not conn["pageInfo"]["hasNextPage"]:
            break
        after = conn["pageInfo"]["endCursor"]
    return found


def suppress(token: str, check_issue_id: str) -> bool:
    m = """
    mutation($i:ID!,$c:DispositionReasonCategory!,$t:String,$a:DispositionActorType!){
      suppressIssueOccurrence(input:{
        checkIssueId:$i, reasonCategory:$c, reasonText:$t, actorType:$a}){ok}}
    """
    d = call(token, m, {
        "i": check_issue_id,
        "c": REASON_CATEGORY,
        "t": REASON_TEXT,
        # Honest about who is doing this. The API has the value; use it.
        "a": "AI_AGENT",
    })
    return bool(d["suppressIssueOccurrence"]["ok"])


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--apply", action="store_true",
                   help="actually suppress; without it nothing is written")
    p.add_argument("--limit", type=int, default=0,
                   help="stop after N suppressions (0 = no limit)")
    p.add_argument("--runs", type=int, default=40,
                   help="how many analysis runs to scan for ids (default 40)")
    args = p.parse_args()

    token = os.environ.get("DEEPSOURCE_TOKEN", "").strip()
    if not token:
        sys.exit("set DEEPSOURCE_TOKEN (e.g. export DEEPSOURCE_TOKEN=$(cat ~/deepsource.txt))")

    print("== active occurrences in excluded paths")
    wanted = wanted_occurrences(token)
    if not wanted:
        print("  none — nothing to retire.")
        return

    by_dir: dict[str, int] = {}
    by_rule: dict[str, int] = {}
    for path, _line, code in wanted:
        by_dir["/".join(path.split("/")[:3])] = by_dir.get("/".join(path.split("/")[:3]), 0) + 1
        by_rule[code] = by_rule.get(code, 0) + 1
    print(f"  {len(wanted)} occurrences")
    for k, v in sorted(by_rule.items(), key=lambda x: -x[1]):
        print(f"    {k:12} {v}")
    for k, v in sorted(by_dir.items(), key=lambda x: -x[1])[:10]:
        print(f"    {k:44} {v}")

    print("\n== locating the CheckIssue ids the mutation needs")
    ids = check_issue_ids(token, args.runs)
    covered = set(ids.values())
    uncovered = wanted - covered
    print(f"  {len(ids)} ids covering {len(covered)} of {len(wanted)} locations")
    if uncovered:
        # Counting ids against locations would not catch this: a location can
        # be recorded several times while another is not reached at all, so the
        # totals can look healthy while a file is missed entirely.
        print(f"  {len(uncovered)} locations have no id yet — they were recorded by")
        print("  runs older than --runs. Raise it and re-run, or their occurrences")
        print("  stay and the badge does not go all the way down. For example:")
        for k in sorted(uncovered)[:3]:
            print(f"    {k[2]}  {k[0]}:{k[1]}")

    if not args.apply:
        print(f"\n== dry run — nothing was changed. {len(ids)} would be suppressed.")
        print("   Re-run with --apply once the exclusion patterns are confirmed working.")
        return

    print(f"\n== suppressing {len(ids)}")
    done = failed = 0
    for (path, line, code), cid in sorted(ids.items()):
        if args.limit and done >= args.limit:
            print(f"  stopping at --limit {args.limit}")
            break
        if suppress(token, cid):
            done += 1
        else:
            failed += 1
            print(f"  refused: {code} {path}:{line}")
        if done % 50 == 0 and done:
            print(f"  {done} done")
    print(f"\n== suppressed {done}, refused {failed}")


if __name__ == "__main__":
    main()
