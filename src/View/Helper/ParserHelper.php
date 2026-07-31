<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\View\Helper;

use Cake\View\Helper\FormHelper;
use Cake\View\Helper\HtmlHelper;
use SaitoHelp\View\Helper\SaitoHelpHelper;
use Saito\App\Registry;
use Saito\Markup\MarkupInterface;
use Stopwatch\Lib\Stopwatch;

/**
 * Parser Helper
 *
 * @property FormHelper $Form
 * @property HtmlHelper $Html
 * @property SaitoHelpHelper $SaitoHelp
 */
class ParserHelper extends AppHelper
{

    /**
     * These helpers are also used in the Parser.
     *
     * No `@var` here on purpose: the property already carries the native
     * `array` type, and CakePHP 5.4 narrowed the parent's own PHPDoc to
     * `array<int|string, array<string, mixed>|string>`. A bare `@var array`
     * restates it more loosely than the parent, which PHPStan reads — rightly —
     * as widening an inherited type.
     */
    public array $helpers = [
        'MailObfuscator.MailObfuscator',
        'Form',
        'Html',
        'Text',
        'Url',
        //= usefull in Parsers
        'Layout',
        'SaitoHelp',
    ];

    /**
     * @var array parserCache for parsed markup
     *
     * Esp. useful for repeating signatures in long mix view threads
     */
    protected $_parserCache = [];

    /** @var MarkupInterface */
    protected $Markup;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        /** @var MarkupInterface */
        $Markup = Registry::get('Markup');
        $this->Markup = $Markup;
    }

    /**
     * parse
     *
     * @param string $string string
     * @param array $options options
     * @return string
     */
    public function parse($string, array $options = [])
    {
        Stopwatch::start('ParseHelper::parse()');
        if (empty($string) || $string === 'n/t') {
            Stopwatch::stop('ParseHelper::parse()');

            return $string;
        }

        $defaults = ['return' => 'html', 'embed' => true, 'multimedia' => true, 'wrap' => true];
        $options += $defaults;

        $cacheId = md5(serialize($options) . $string); // render cache key, not password hashing skipcq: PHP-A1004
        if (isset($this->_parserCache[$cacheId])) {
            $html = $this->_parserCache[$cacheId];
        } else {
            $html = $this->Markup->parse($string, $this, $options);
            $this->_parserCache[$cacheId] = $html;
        }
        if ($options['return'] === 'html' && $options['wrap']) {
            $html = '<div class="richtext">' . $html . '</div>';
        }
        Stopwatch::stop('ParseHelper::parse()');

        return $html;
    }
}
