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

use Cake\Http\ServerRequest;
use Cake\View\Helper\UrlHelper;
use Saito\JsData\Notifications;

/**
 * Javascript Data Helper
 *
 * @property ServerRequest $request
 * @property UrlHelper $Url
 */
class JsDataHelper extends AppHelper
{
    public array $helpers = ['Url'];

    /**
     * Notifications
     *
     * @var Notifications
     */
    protected $Notifications;

    /**
     * Gets notifications
     *
     * @return Notifications The notifications.
     */
    public function notifications(): Notifications
    {
        if (empty($this->Notifications)) {
            $this->Notifications = new Notifications();
        }

        return $this->Notifications;
    }
}
