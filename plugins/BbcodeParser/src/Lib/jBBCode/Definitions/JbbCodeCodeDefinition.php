<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Plugin\BbcodeParser\src\Lib\jBBCode\Definitions;

use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Themes\InlineTheme;

class CodeWithoutAttributes extends CodeDefinition
{
    protected $_sTagName = 'code';

    protected $_sParseContent = false;

    /** @var Highlighter|null shared highlighter (reused across code blocks) */
    private static ?Highlighter $highlighter = null;

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        $type = 'text';
        if (!empty($attributes['code'])) {
            $type = $attributes['code'];
        }

        if (self::$highlighter === null) {
            // InlineTheme emits inline `style="color:…"` on the spans, so the
            // output is self-contained and needs no external stylesheet
            // (matching the old GeSHi behaviour, no frontend rebuild required).
            self::$highlighter = new Highlighter(new InlineTheme(__DIR__ . '/code-theme.css'));
        }

        // SECURITY: tempest/highlight does NOT escape via htmlspecialchars. Its
        // Escape::html() first entity-escapes real <, >, ", & and then
        // reverse-maps its internal placeholder glyphs U+2776–U+277F back into
        // raw <, >, ", & — AFTER escaping. So any of those glyphs typed by the
        // user is turned into a live HTML metacharacter, bypassing the escape
        // (mutation XSS: `[code]❷img src=x onerror=…❸[/code]` renders a real
        // <img>). Strip the token range from the input before highlighting so
        // the escaper's boundary holds. These are rarely-used dingbat code
        // points; dropping them from code blocks is acceptable.
        $content = preg_replace('/[\x{2776}-\x{277F}]/u', '', trim($content));

        // parse() now HTML-escapes the sanitized content and falls back to
        // plain (escaped) text for an unknown language, so both the content and
        // the language token ($type) are safe to pass through.
        $highlighted = self::$highlighter->parse($content, $type);

        // The "geshi-wrapper" class is legacy but retained: the themes still
        // style it (padding), so keeping it avoids a full theme SCSS rebuild.
        return '<div class="geshi-wrapper"><pre><code class="hl-code">'
            . $highlighted . '</code></pre></div>';
    }
}

//@codingStandardsIgnoreStart
class CodeWithAttributes extends CodeWithoutAttributes
//@codingStandardsIgnoreEnd
{
    protected $_sUseOptions = true;
}
