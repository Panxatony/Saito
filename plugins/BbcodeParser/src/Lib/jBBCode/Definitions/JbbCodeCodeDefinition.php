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

        // parse() HTML-escapes the code and falls back to plain (escaped) text
        // for an unknown language, so both the user-supplied content and the
        // language token ($type) are safe to pass through.
        $highlighted = self::$highlighter->parse(trim($content), $type);

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
