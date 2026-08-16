<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Admin;

use Cake\Core\BasePlugin;

/**
 * The admin area.
 *
 * `bootstrap()` used to load the BootstrapUI plugin here. That plugin supplied
 * replacement Form, Html, Flash, Paginator and Breadcrumbs helpers, declared
 * through `Controller::$helpers` — a property CakePHP 5 no longer reads, so the
 * declaration stopped taking effect at the framework upgrade and nobody
 * noticed. Verified before removing: the helpers actually in use were
 * `Cake\View\Helper\FormHelper` and `Cake\View\Helper\HtmlHelper`, and the
 * rendered markup was the framework's own (`<div class="input text">`, no
 * `form-control`, no `form-group`).
 *
 * The admin area looks like Bootstrap because the Bootstrap stylesheet is
 * loaded and the templates carry the class names themselves, not because of
 * that plugin (#73).
 *
 * The class stays, empty, because it is the plugin's declared entry point and
 * the place any future hook belongs.
 */
class AdminPlugin extends BasePlugin
{
}
