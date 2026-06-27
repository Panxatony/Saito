<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

use Cake\Routing\Router;

$routes->connect(
    '/help/:id',
    [
        'plugin' => 'SaitoHelp',
        'controller' => 'SaitoHelps',
        'action' => 'languageRedirect',
    ],
    ['pass' => ['id']]
);

$routes->connect(
    '/help/:lang/:id',
    [
        'plugin' => 'SaitoHelp',
        'controller' => 'SaitoHelps',
        'action' => 'view',
    ],
    // Constrain :lang to a language token. It is concatenated into a
    // filesystem path (docs/help/<lang>), so values like ".." must not pass.
    ['pass' => ['lang', 'id'], 'lang' => '[a-zA-Z_]+']
);
