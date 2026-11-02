<?php
declare(strict_types=1);

/**
 * Feed an XSS payload through tempest/highlight the way Saito's [code] tag does.
 *
 *     php scripts/probe-highlight-xss.php [language ...]
 *
 * Run it after every tempest/highlight update. The library's Escape::html()
 * maps its placeholder glyphs (U+2776–U+277F) back into real `<`, `>`, `"`
 * and `&` *after* escaping, which made [code] a stored XSS until 7.2.5.
 * JbbCodeCodeDefinition strips that range before highlighting; this probe
 * checks that the strip still covers what the library uses, and that no
 * language — new ones especially — emits anything but <span class="hl-…">.
 *
 * Exit status: 0 clean, 1 live markup found.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$payload = "<img src=x onerror=alert(1)> \"q\" 'a' & <script>x</script> </textarea>"
    . "<svg/onload=1> \u{2776}\u{2777}img src=x onerror=2\u{2778} {+ <b>x</b> +} "
    . "#{\"<i>\"} `<u>` /<a>/ %w(<x>) <div attr={\"<y>\"}>{`<z>`}</div>";

$languages = array_slice($argv, 1) ?: [
    'php', 'html', 'js', 'css', 'sql', 'json', 'yaml', 'xml', 'twig',
    'jsx', 'tsx', 'ruby', 'swift', 'sema', 'python', 'markdown',
    'rust', 'go', 'c', 'cpp', 'java', 'kotlin', 'csharp', 'toml',
];

// Same preparation as JbbCodeCodeDefinition::_parse().
$content = preg_replace('/[\x{2776}-\x{277F}]/u', '', trim($payload)) ?? '';

$highlighter = new \Tempest\Highlight\Highlighter();
$failed = 0;
foreach ($languages as $language) {
    $html = $highlighter->parse($content, $language);
    preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)/', $html, $tags);
    $foreign = array_diff(array_unique($tags[1]), ['span']);
    // `<span class="ignore">` is the library's own wrapper for unconsumed {+ +} / {- -} markers.
    $oddSpan = preg_match('/<span(?! class="(?:hl-[a-z-]+|ignore)">)[^>]*>/', $html);
    $ok = !$foreign && !$oddSpan;
    printf("%-9s %s\n", $language, $ok ? 'ok' : 'LIVE MARKUP');
    if (!$ok) {
        $failed++;
        echo '    ', $html, "\n";
    }
}

exit($failed ? 1 : 0);
