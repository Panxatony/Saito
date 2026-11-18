<?php

declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link      http://cakephp.org CakePHP(tm) Project
 * @since     3.0.0
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace App\View;

use Cake\View\View;

/**
 * App View class
 *
 * Helpers that templates use without loading them: the controllers or the
 * plugins add them at runtime, so they are declared here for static readers.
 *
 * @property \App\View\Helper\PermissionsHelper $Permissions
 * @property \App\View\Helper\PostingHelper $Posting
 * @property \SaitoHelp\View\Helper\SaitoHelpHelper $SaitoHelp
 */
#[\AllowDynamicProperties]
class AppView extends View
{

    /**
     * Initialization hook method.
     *
     * For e.g. use this method to load a helper for all views:
     * `$this->loadHelper('Html');`
     *
     * @return void
     */
    public function initialize(): void
    {
    }
}
