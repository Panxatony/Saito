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

use Cake\View\Helper;
use Cake\View\Helper\IdGeneratorTrait;

/**
 * App Helper
 *
 * @property \Cake\View\Helper\FormHelper $Form
 * @property \Cake\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\UrlHelper $Url
 */
#[\AllowDynamicProperties]
class AppHelper extends Helper
{
    use IdGeneratorTrait;

    protected static $_tagId = 0;

    /**
     * tag id
     *
     * @return string
     */
    public static function tagId()
    {
        return 'id' . static::$_tagId++;
    }
}
