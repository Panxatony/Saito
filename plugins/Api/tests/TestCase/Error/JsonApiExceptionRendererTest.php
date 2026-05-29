<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Api\Test\TestCase\Error;

use Api\Error\Exception\GenericApiException;
use Api\Error\JsonApiExceptionRenderer;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * Guards the Cake 5 port of the API exception renderer.
 *
 * The renderer is installed for every `/api/` request (see the Api plugin
 * bootstrap). It used to extend the Cake 4 `Cake\Error\ExceptionRenderer`,
 * which was removed in Cake 5 — any API error then died with a fatal
 * "class not found" instead of producing a JSON error response.
 */
class JsonApiExceptionRendererTest extends TestCase
{
    public function testRendersJsonErrorForHttpException(): void
    {
        $request = new ServerRequest(['url' => '/api/v2/does-not-exist']);
        $renderer = new JsonApiExceptionRenderer(new NotFoundException('Not Found'), $request);

        $response = $renderer->render();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string)$response->getBody(), true);
        $this->assertIsArray($body, 'Response body must be valid JSON.');
        $this->assertArrayHasKey('errors', $body);
        $this->assertSame('Not Found', $body['errors'][0]['title']);
        $this->assertSame(404, $body['errors'][0]['code']);
    }

    public function testRendersJsonErrorForGenericApiException(): void
    {
        $request = new ServerRequest(['url' => '/api/v2/uploads']);
        $renderer = new JsonApiExceptionRenderer(new GenericApiException('boom'), $request);

        $response = $renderer->render();

        $this->assertSame(400, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('boom', $body['errors'][0]['title']);
        $this->assertSame(400, $body['errors'][0]['code']);
    }
}
