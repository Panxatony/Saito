<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

use Api\Error\JsonApiExceptionRenderer;
use Cake\Core\Configure;

/**
 * Which requests answer errors as JSON instead of the HTML error page.
 *
 * This runs at bootstrap, before there is a request object or a router, so the
 * address has to be read off the environment by hand — that is why the list of
 * fallbacks below looks the way it does.
 *
 * The match is on the **path** only. It used to be `strstr($uri, 'api/')`,
 * which matches anywhere in the address, query string included: asking for
 * `/anything?x=api/` answered a reader's 404 with `{"errors":[...]}` instead of
 * the error page. Reproduced on the test system, and gone after this change.
 */
$getUri = function () {
    if (!empty($_SERVER['PATH_INFO'])) {
        $uri = $_SERVER['PATH_INFO'];
    } elseif (isset($_SERVER['REQUEST_URI'])) {
        $uri = $_SERVER['REQUEST_URI'];
    } elseif (isset($_SERVER['PHP_SELF']) && isset($_SERVER['SCRIPT_NAME'])) {
        $uri = str_replace($_SERVER['SCRIPT_NAME'], '', $_SERVER['PHP_SELF']);
    } elseif (isset($_SERVER['HTTP_X_REWRITE_URL'])) {
        $uri = $_SERVER['HTTP_X_REWRITE_URL'];
    } elseif ($var = env('argv')) {
        $uri = $var[0];
    } else {
        throw new \Exception('Could not evaluate URL', 155949137);
    }

    return $uri;
};

$path = (string)parse_url($getUri(), PHP_URL_PATH);
// `(^|/)api/` rather than a plain prefix: an installation in a subdirectory
// serves the same routes under a base path. The API itself lives at `/api/v2`.
if (preg_match('{(^|/)api/}', $path) === 1) {
    Configure::write('Error.exceptionRenderer', JsonApiExceptionRenderer::class);
}
