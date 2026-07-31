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

use Cake\View\Helper;
use JBBCode\ElementNode;
use Saito\Markup\MarkupSettings;

/**
 * `$this->Html` and friends are not properties of this class — `__get()` below
 * hands them through to the calling CakePHP helper. Declaring them is what lets
 * static analysis see the same thing the runtime does; without it every use in
 * a subclass reads as access to something undefined.
 *
 * Only the helpers the definitions actually reach for are listed. Adding one
 * that is never used would be a claim about the helper's API that nothing here
 * checks.
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
abstract class CodeDefinition extends \JBBCode\CodeDefinition
{

    /**
     * @var Helper calling CakePHP helper
     */
    protected $_sHelper;

    protected $_sParseContent = true;

    protected $_sUseOptions = false;

    /**
     * @var string bbcode-tag
     */
    protected $_sTagName;

    /**
     * Saito's markup settings.
     *
     * Was declared `array` while the constructor has only ever assigned a
     * `MarkupSettings`. Six of the sixteen findings that surfaced when this
     * directory was brought back under static analysis were that one word:
     * every `$this->_sOptions->get(...)` in the definitions read as calling a
     * method on an array.
     *
     * @var MarkupSettings
     */
    protected $_sOptions;

    /**
     * {@inheritDoc}
     */
    public function __construct(Helper $Helper, MarkupSettings $options)
    {
        $this->_sOptions = $options;
        $this->_sHelper = $Helper;
        parent::__construct();
        $this->setTagName($this->_sTagName);
        $this->setParseContent($this->_sParseContent);
        $this->setUseOption($this->_sUseOptions);
    }

    /**
     * {@inheritDoc}
     */
    public function __get($name)
    {
        if (is_object($this->_sHelper->$name)) {
            return $this->_sHelper->{$name};
        }
    }

    /**
     * {@inheritDoc}
     */
    public function asHtml(ElementNode $el)
    {
        if (!$this->hasValidInputs($el)) {
            return $el->getAsBBCode();
        }
        $content = $this->getContent($el);
        $parsedString = $this->_parse($content, $el->getAttribute(), $el);
        if ($parsedString === false) {
            return $el->getAsBBCode();
        }

        return $parsedString;
    }

    /**
     * Parse
     *
     * @param string $content content
     * @param array $attributes attributes
     * @param ElementNode $node node
     *
     * @return mixed parsed string or bool false if parsing failed
     */
    abstract protected function _parse($content, $attributes, ElementNode $node);
}
