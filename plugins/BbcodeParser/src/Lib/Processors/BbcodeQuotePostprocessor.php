<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Plugin\BbcodeParser\src\Lib\Processors;

class BbcodeQuotePostprocessor extends BbcodeProcessor
{

    /**
     * {@inheritDoc}
     */
    public function process($string)
    {
        $quoteSymbolSanitized = h($this->_sOptions->get('quote_symbol'));
        // Begin of the text or a new line in the text, maybe one space afterwards
        $pattern = '/(^|\n\r\s?)' . $quoteSymbolSanitized . '\s(.*)(?!\<br)/m';
        preg_match_all($pattern, $string, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        // Rebuilt from the end so earlier offsets stay valid.
        foreach (array_reverse($matches) as $m) {
            [$whole, $offset] = $m[0];
            // This runs over finished HTML. A line that starts *inside* a tag
            // — a newline in an [img] or [url] address — is an attribute
            // value, not a quoted line; a span inserted there broke the markup
            // (#173).
            $before = substr($string, 0, $offset);
            $open = strrpos($before, '<');
            $close = strrpos($before, '>');
            if ($open !== false && ($close === false || $open > $close)) {
                continue;
            }
            $replacement = $m[1][0] . '<span class="richtext-citation">' . $quoteSymbolSanitized . ' '
                . $m[2][0] . '</span>';
            $string = substr_replace($string, $replacement, $offset, strlen($whole));
        }

        return (string)$string;
    }
}
