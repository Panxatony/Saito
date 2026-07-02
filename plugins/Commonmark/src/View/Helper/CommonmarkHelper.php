<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Commonmark\View\Helper;

use Cake\View\Helper;
use League\CommonMark\CommonMarkConverter;

class CommonmarkHelper extends Helper
{
    protected $_converter;

    /**
     * Parse text as CommonMark
     *
     * @param string $text text to parse
     *
     * @return string
     */
    public function parse($text)
    {
        return (string)$this->_getParser()->convert($text);
    }

    /**
     * Get parser
     *
     * @return CommonMarkConverter
     */
    protected function _getParser()
    {
        if ($this->_converter !== null) {
            return $this->_converter;
        }
        // Harden against XSS: escape raw HTML in the input and drop unsafe
        // link schemes (javascript:, data:, …). league/commonmark defaults to
        // allowing both. Currently only trusted help pages are rendered, but
        // this keeps the helper safe if ever pointed at user input.
        $this->_converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return $this->_converter;
    }
}
