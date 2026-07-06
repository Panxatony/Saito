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

// Cake 5 uses `{name}` route placeholders (the old `:name` syntax is treated
// as a literal path segment, which broke /help/… since the Cake 5 upgrade).
$routes->connect(
    '/help',
    [
        'plugin' => 'SaitoHelp',
        'controller' => 'SaitoHelps',
        'action' => 'index',
    ]
);

$routes->connect(
    '/help/{id}',
    [
        'plugin' => 'SaitoHelp',
        'controller' => 'SaitoHelps',
        'action' => 'languageRedirect',
    ]
)->setPass(['id']);

$routes->connect(
    '/help/{lang}/{id}',
    [
        'plugin' => 'SaitoHelp',
        'controller' => 'SaitoHelps',
        'action' => 'view',
    ]
    // Constrain {lang} to a language token. It is concatenated into a
    // filesystem path (docs/help/<lang>), so values like ".." must not pass.
)->setPass(['lang', 'id'])->setPatterns(['lang' => '[a-zA-Z_]+']);
