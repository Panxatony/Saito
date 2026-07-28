<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Admin\View\Helper;

use App\View\Helper\AppHelper;
use App\View\Helper\TimeHHelper;
use Cake\Cache\Cache;
use Cake\View\Helper\BreadcrumbsHelper;
use Cake\View\Helper\HtmlHelper;
use SaitoHelp\View\Helper\SaitoHelpHelper;

/**
 * @property BreadcrumbsHelper $Breadcrumbs
 * @property HtmlHelper $Html
 * @property SaitoHelpHelper $SaitoHelp
 * @property TimeHHelper $TimeH
 */
class AdminHelper extends AppHelper
{
    public array $helpers = [
        'Breadcrumbs',
        'SaitoHelp',
        'Html',
        'TimeH',
    ];

    /**
     * help
     *
     * @param string $id id
     * @return mixed
     */
    public function help($id)
    {
        return $this->SaitoHelp->icon($id, ['style' => 'float: right;']);
    }

    /**
     * Get badge type for an engine
     *
     * @param string $engine engine-Id
     * @return string
     */
    public function badgeForCache(string $engine): string
    {
        $class = get_class(Cache::pool($engine));
        $class = explode('\\', $class);
        $class = str_replace('Engine', '', end($class));

        switch ($class) {
            case 'File':
                $type = 'warning';
                break;
            case 'Apc':
            case 'Apcu':
                $type = 'success';
                break;
            case 'Debug':
                $type = 'important';
                break;
            default:
                $type = 'info';
        }

        return $this->badge($class, $type);
    }

    /**
     * badge
     *
     * @param string $text text
     * @param string $badge type
     * @return string
     */
    public function badge(string $text, string $badge = 'info'): string
    {
        return $this->Html->tag(
            'span',
            $text,
            ['class' => "badge badge-$badge"]
        );
    }

}
