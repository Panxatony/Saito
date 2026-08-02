<?php

declare(strict_types=1);

/**
 * Fail if a translation key is used that no catalogue declares.
 *
 * A key with no `msgid` does not fall back to anything readable — CakePHP
 * returns the key itself, so `__('user.block.t')` renders the literal string
 * `user.block.t` on the page. That shipped: moderators saw it as the label of
 * the block-duration select from the day blocking moved to the profile until
 * 2026-08-01, when a user found it. Nothing failed, nothing logged, and no test
 * covered it, because from the code's point of view everything worked.
 *
 * This is the cheap half of the translation problem and the only half worth
 * automating. The other direction — keys declared but never used — cannot be
 * decided statically: Saito assembles keys at runtime
 * (`__d('nondynamic', 'permission.role.' . $id)`, every admin setting label from
 * its own name, every page title from controller/action), so a sweep reports
 * working code as dead. See `dev/audit-probes.sh`, which prints those as
 * candidates for a human and does not fail on them.
 *
 * Dotted keys only. A plain-English `__('Submit')` is its own fallback and reads
 * correctly untranslated, which is the convention this project already follows.
 *
 * Usage: php dev/check-translations.php   (exit 1 on a finding)
 */

$root = dirname(__DIR__);
chdir($root);

/** Every msgid the German catalogues declare. German is the reference: it is the
 * complete one, and a key missing there is missing everywhere. */
$declared = [];
foreach (glob('src/Locale/de/*.po') ?: [] as $catalogue) {
    preg_match_all(
        '/^msgid "((?:[^"\\\\]|\\\\.)*)"/m',
        (string)file_get_contents($catalogue),
        $matches
    );
    foreach ($matches[1] as $id) {
        $declared[$id] = true;
    }
}
foreach (glob('plugins/*/src/Locale/de/*.po') ?: [] as $catalogue) {
    preg_match_all(
        '/^msgid "((?:[^"\\\\]|\\\\.)*)"/m',
        (string)file_get_contents($catalogue),
        $matches
    );
    foreach ($matches[1] as $id) {
        $declared[$id] = true;
    }
}

$missing = [];
$directories = ['templates', 'src', 'plugins'];
foreach ($directories as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        // Tests may name a key deliberately to prove it is absent.
        if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
            continue;
        }
        preg_match_all(
            "/\\b__(?:d|n|dn)?\\(\\s*'([a-z][a-zA-Z0-9_]*(?:\\.[a-zA-Z0-9_]+)+)'/",
            (string)file_get_contents($path),
            $matches
        );
        foreach ($matches[1] as $key) {
            if (!isset($declared[$key])) {
                $missing[$key] ??= $path;
            }
        }
    }
}

$count = count($declared);
if ($missing === []) {
    printf("%d keys declared, every dotted key used is among them.\n", $count);
    exit(0);
}

printf("%d keys declared. %d used but missing:\n\n", $count, count($missing));
foreach ($missing as $key => $path) {
    printf("  %-40s %s\n", $key, $path);
}
print "\nA key with no msgid renders as itself on the page. Add it to\n"
    . "src/Locale/de/*.po and src/Locale/en/*.po, or correct the spelling.\n";
exit(1);
