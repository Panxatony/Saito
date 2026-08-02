#!/usr/bin/env bash
#
# Residue probes.
#
# Every serious find of the 8.3.x cycle came from comparing two things, never
# from reading code: a grown database against a migrated one (flattr, six
# `users` columns), an old lockfile against a regenerated one (`sass` was never
# declared), the documentation against the code (an operator guide that told
# people to set the wrong timezone). A `grep` proves a name is absent. It does
# not prove a feature is gone — that lesson is written into
# `config/Migrations/20260730010000_DropLegacySaito5UserColumns.php` and it is
# the reason every probe here prints candidates rather than verdicts.
#
# Nothing is deleted, nothing is changed. Read the output, then check each
# candidate by hand.
#
#   ./dev/audit-probes.sh              # code-only probes
#   ./dev/audit-probes.sh "$DB_URL"    # …plus the ones that need the database
#
set -uo pipefail
cd "$(dirname "$0")/.."

DB_URL="${1:-}"
hr() { printf '\n\033[1m── %s\033[0m\n' "$1"; }

# ---------------------------------------------------------------------------
hr "1. Settings rows nothing reads"
# Found api_enabled, api_crossdomain, userranks_show, userranks_ranks on
# 2026-08-02. The last two held a configured ladder on production that had not
# been displayed anywhere since 2014.
if [ -n "$DB_URL" ]; then
  u=${DB_URL#*://}; cred=${u%%@*}; rest=${u#*@}
  user=${cred%%:*}; pass=${cred#*:}
  host=${rest%%/*}; dbn=${rest#*/}; dbn=${dbn%%\?*}
  MYSQL_PWD="$pass" mysql -h "$host" -u "$user" "$dbn" -N -e "SELECT name FROM settings" 2>/dev/null \
  | sort -u | while read -r n; do
      # db_version is written by the updater and read by the middleware through
      # a different path; never a candidate.
      [ "$n" = "db_version" ] && continue
      if ! grep -rq "$n" --include='*.php' --include='*.ts' src/ plugins/ templates/ config/ 2>/dev/null; then
        echo "  no reader: $n"
      fi
    done
else
  echo "  (skipped — pass a DATABASE_URL as \$1)"
fi

# ---------------------------------------------------------------------------
hr "2. Translation keys with no call site"
# The first version of this probe reported 180 of 535 and was almost entirely
# wrong: Saito assembles keys at runtime — `__d('nondynamic', 'permission.role.'
# . $id)`, every admin setting label from its own name, every page title from
# controller/action. A probe that cannot see those reports working code as dead,
# which is worse than no probe.
#
# So concatenation prefixes are collected too, and any key starting with one is
# considered used. What is left over is small enough to read.
python3 - <<'PY_INNER'
import re, os
ids = {}
for po in ('src/Locale/de/default.po', 'src/Locale/de/nondynamic.po', 'src/Locale/de/page_titles.po'):
    if not os.path.exists(po):
        continue
    for m in re.finditer(r'^msgid "((?:[^"\\]|\\.)*)"', open(po, encoding='utf-8').read(), re.M):
        if m.group(1):
            ids[m.group(1)] = os.path.basename(po)

used, prefixes = set(), set()
dynamic = False
for dp, dn, fn in os.walk('.'):
    if any(x in dp for x in ('/vendor', '/node_modules', '/.git', '/tmp')):
        continue
    for f in fn:
        if not f.endswith(('.php', '.ts', '.js')):
            continue
        try:
            s = open(os.path.join(dp, f), encoding='utf-8', errors='ignore').read()
        except OSError:
            continue
        used.update(m.group(1) for m in re.finditer(r"__(?:d|n|dn)?\(\s*'([^']+)'", s))
        used.update(m.group(1) for m in re.finditer(r'__(?:d|n|dn)?\(\s*"([^"]+)"', s))
        # __('prefix.' . $var)  and  __d('domain', 'prefix.' . $var)
        prefixes.update(m.group(1) for m in re.finditer(r"__(?:d|n|dn)?\([^)]*?'([^']*)'\s*\.\s*\$", s))
        # a wholly variable key: __($x) / __d($domain, $x) — nothing can be judged
        if re.search(r"__(?:d|n|dn)?\(\s*\$", s) or re.search(r"__d\(\s*'[^']+'\s*,\s*\$", s):
            dynamic = True

orphan = sorted(k for k in ids
                if k not in used and not any(k.startswith(p) for p in prefixes if p))
print(f"  {len(ids)} msgids, {len(prefixes)} concatenation prefixes seen, "
      f"{len(orphan)} left without a call site")
if dynamic:
    print("  NOTE: at least one __() takes a fully variable key (page titles, "
          "settings labels),")
    print("        so some of the following are looked up by a name this probe "
          "cannot see.")
for k in orphan:
    print(f"    {k}  [{ids[k]}]")
PY_INNER

# ---------------------------------------------------------------------------
hr "3. Translation keys used but never declared"
# This is the direction that ships a defect: a key with no msgid renders as
# itself. `user.block.duration` was `user.block.t` until 2026-08-01.
python3 - <<'PY'
import re, os
ids = set()
for dp, dn, fn in os.walk('src/Locale/de'):
    for f in fn:
        if f.endswith('.po'):
            for m in re.finditer(r'^msgid "((?:[^"\\]|\\.)*)"',
                                 open(os.path.join(dp, f), encoding='utf-8').read(), re.M):
                ids.add(m.group(1))
missing = {}
for d in ('templates', 'src', 'plugins'):
    for dp, dn, fn in os.walk(d):
        if any(x in dp for x in ('/vendor', '/tests')):
            continue
        for f in fn:
            if not f.endswith('.php'):
                continue
            p = os.path.join(dp, f)
            for m in re.finditer(r"\b__(?:d|n|dn)?\(\s*'([a-z][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)+)'",
                                 open(p, encoding='utf-8', errors='ignore').read()):
                if m.group(1) not in ids:
                    missing.setdefault(m.group(1), p)
print(f"  {len(missing)} dotted keys used but not in any de catalogue")
for k, p in sorted(missing.items()):
    print(f"    {k}  ({p})")
PY

# ---------------------------------------------------------------------------
hr "4. Obsolete entries the catalogues already flag"
for po in src/Locale/*/*.po; do
  n=$(grep -c '^#~ msgid' "$po" 2>/dev/null) || n=0
  if [ "${n:-0}" -gt 0 ]; then
    printf '  %-32s %s commented-out entries\n' "$po" "$n"
  fi
done

# ---------------------------------------------------------------------------
hr "5. Composer packages nothing requires"
# `cakephp/bake` was found this way — it also broke `bin/cake migrations`
# outright, which is how it finally surfaced.
#
# Matched on the namespaces a package actually declares, read from its own
# composer.json, not on its name: guessing "php-domain-parser" -> "phpdomainparser"
# reports every correctly-used library as unused, which is worse than no probe.
php -r '
$c = json_decode(file_get_contents("composer.json"), true);
foreach (["require", "require-dev"] as $section) {
    foreach (array_keys($c[$section] ?? []) as $pkg) {
        if (!str_contains($pkg, "/")) { continue; }   // php, ext-*
        $meta = "vendor/$pkg/composer.json";
        $ns = [];
        if (is_file($meta)) {
            $m = json_decode(file_get_contents($meta), true);
            foreach (["psr-4", "psr-0"] as $std) {
                foreach (array_keys($m["autoload"][$std] ?? []) as $n) {
                    $n = trim($n, "\\");
                    if ($n !== "") { $ns[] = $n; }
                }
            }
        }
        if (!$ns) { $ns[] = str_replace(["-", "_"], "", substr($pkg, strrpos($pkg, "/") + 1)); }
        $found = false;
        foreach ($ns as $n) {
            $hit = shell_exec("grep -rliF " . escapeshellarg($n) . " --include=*.php --include=*.neon --include=*.xml --include=*.dist --include=*.json src plugins config tests 2>/dev/null | head -1");
            if (trim((string)$hit)) { $found = true; break; }
        }
        if (!$found) {
            echo "  no reference to " . implode(", ", $ns) . ": $pkg ($section)\n";
        }
    }
}
echo "  (a package can be used through a binary or a config file rather than a\n";
echo "   namespace — phpstan, psalm and the sniffers all are. Check before removing.)\n";'

# ---------------------------------------------------------------------------
hr "6. Routes that lead nowhere, controllers nothing routes to"
php -r '
$controllers = [];
foreach (["src/Controller", "plugins"] as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match("/(\w+)Controller\.php$/", $f->getFilename(), $m)) {
            if (str_contains($f->getPathname(), "/tests/")) { continue; }
            if (in_array($m[1], ["App", "AdminApp", "ApiApp"], true)) { continue; }
            $controllers[$m[1]] = $f->getPathname();
        }
    }
}
$routes = "";
foreach (glob("config/routes.php") as $f) { $routes .= file_get_contents($f); }
foreach (glob("plugins/*/config/routes.php") as $f) { $routes .= file_get_contents($f); }
foreach ($controllers as $name => $path) {
    if (!str_contains($routes, "\x27$name\x27") && !str_contains($routes, strtolower($name))) {
        echo "  not named in any routes file: $name  ($path)\n";
    }
}
echo "  (a controller can still be reached by the fallback DashedRoute — check before removing)\n";'

# ---------------------------------------------------------------------------
hr "7. Markers left in the code"
grep -rn "@td\|TODO\|FIXME\|XXX\|HACK" --include='*.php' --include='*.ts' src/ plugins/ templates/ frontend/ 2>/dev/null \
  | grep -v '/vendor/' | grep -v '/tests/' | sed 's/^/  /' | head -20

# ---------------------------------------------------------------------------
hr "8. Schema against migrations"
echo "  Not automated on purpose: it needs an empty database to replay the"
echo "  migrations into, and comparing it with a dump of a grown one. The"
echo "  procedure and the last result are in todo.md — it found the flattr"
echo "  column, six users columns, and text columns four times too narrow for"
echo "  the longest posting on the live forum."

printf '\n\033[1mNone of this is a verdict.\033[0m Each line is a candidate; check what a\n'
printf 'feature actually does before removing anything.\n\n'
