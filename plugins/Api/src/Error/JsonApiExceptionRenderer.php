<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Api\Error;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
use Cake\Http\Response;

class JsonApiExceptionRenderer extends WebExceptionRenderer
{
    /**
     * {@inheritDoc}
     *
     * @see https://stackoverflow.com/questions/40327079/how-to-change-error-response-structure-for-json-request-cakephp-3
     * @see http://jsonapi.org/format/#errors
     */
    protected function _outputMessage(string $template, bool $skipControllerCheck = false): Response
    {
        $vars = $this->controller->viewBuilder()->getVars();
        $data = [
            'errors' => [
                [
                    'title' => $vars['message'] ?? null,
                    'code' => $vars['code'] ?? null,
                ],
            ],
        ];

        if (Configure::read('debug')) {
            $data += $this->controller->viewBuilder()->getVars();
        }

        $this->controller->set('data', $data);
        $this->controller->viewBuilder()->setOption('serialize', 'data');

        // Render output as JSON instead of HTML.
        $viewClass = App::className('Json', 'View', 'View');
        $this->controller->viewBuilder()->setClassName($viewClass);

        return parent::_outputMessage($template, $skipControllerCheck);
    }
}
