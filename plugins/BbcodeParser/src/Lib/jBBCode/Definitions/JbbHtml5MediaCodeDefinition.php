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

use Plugin\BbcodeParser\src\Lib\Helper\UrlParserTrait;
use Plugin\BbcodeParser\src\Lib\jBBCode\Definitions\CodeDefinition;

//@codingStandardsIgnoreStart
class Html5Audio extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    use UrlParserTrait;

    protected $_sTagName = 'audio';

    protected $_sParseContent = false;

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        $isUpload = !empty($attributes['src']) && $attributes['src'] === 'upload';
        if ($isUpload) {
            $content = $this->_linkToUploadedFile($content);
        } elseif (!$this->_hasSafeUrlScheme($content)) {
            // An author-supplied address goes straight into a src attribute, so
            // it needs the same scheme check [img] and [url] make. A media src
            // does not execute script, but `javascript:`/`data:` have no place
            // here and the editor's own paste conversion can produce them.
            return false;
        }

        // Better: preload='metadata'. But Safari 12 doesn't support it.
        return "<audio src='" . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . "' controls='controls' preload='auto' x-webkit-airplay='allow'></audio>";
    }
}

//@codingStandardsIgnoreStart
class Html5AudioWithAttributes extends Html5Audio
//@codingStandardsIgnoreEnd
{
    protected $_sUseOptions = true;
}

//@codingStandardsIgnoreStart
class Html5Video extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    use UrlParserTrait;

    protected $_sTagName = 'video';

    protected $_sParseContent = false;

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        $isUpload = !empty($attributes['src']) && $attributes['src'] === 'upload';
        if ($isUpload) {
            $content = $this->_linkToUploadedFile($content);
        } elseif (!$this->_hasSafeUrlScheme($content)) {
            // See the audio tag above: same attribute, same check.
            return false;
        }

        // Better: preload='metadata'. But Safari 12 doesn't support it and
        // only shows a blank preview.
        return "<video src='" . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . "' controls='controls' preload='auto' x-webkit-airplay='allow'></video>";
    }
}

//@codingStandardsIgnoreStart
class Html5VideoWithAttributes extends Html5Video
//@codingStandardsIgnoreEnd
{
    protected $_sUseOptions = true;
}
