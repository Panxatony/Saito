<?php

declare(strict_types=1);

namespace App\Test\TestCase\Lib\Saito\Markup;

use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use ReflectionMethod;
use Saito\Markup\MarkupSettings;

/**
 * The two base URLs in MarkupSettings are unlike any other route reference in
 * the app: they are substituted into posting *text* when it is rendered, so
 * every `@name` mention and every `#123` tag in twenty years of postings points
 * at whatever they say today.
 *
 * Nothing else links them to the routing table — no `Router::url()`, no helper,
 * just two strings. Retiring the action they name breaks user content
 * everywhere at once, and silently: the pages still render, the links just lead
 * nowhere. This test is the missing connection.
 */
class MarkupSettingsTest extends TestCase
{
    /**
     * Both defaults must resolve to a controller action that actually exists.
     *
     * @return void
     */
    public function testDefaultBaseUrlsPointAtLiveActions(): void
    {
        $this->loadRoutes();
        $settings = new MarkupSettings();

        foreach (['atBaseUrl' => 'Mitch', 'hashBaseUrl' => '1'] as $key => $argument) {
            $url = '/' . trim((string)$settings->get($key), '/') . '/' . $argument;
            $parsed = Router::parseRequest(new ServerRequest(['url' => $url]));

            $controller = 'App\\Controller\\' . $parsed['controller'] . 'Controller';
            $this->assertTrue(
                class_exists($controller),
                sprintf('%s ("%s") routes to the unknown controller %s', $key, $url, $controller)
            );

            $hint = sprintf(
                '%s ("%s") routes to %s::%s(). Every @mention and #tag in existing '
                    . 'posting text points there — retire it and those links die '
                    . 'silently, all at once.',
                $key,
                $url,
                $controller,
                $parsed['action']
            );
            $this->assertTrue(method_exists($controller, $parsed['action']), $hint);
            $this->assertTrue((new ReflectionMethod($controller, $parsed['action']))->isPublic(), $hint);
        }
    }

    /**
     * A guard for the guard: pin the actual values, so that changing them is a
     * deliberate act with a visible diff rather than a silent edit. Whoever
     * changes one has to think about the postings already out there.
     *
     * @return void
     */
    public function testDefaultBaseUrlsAreTheDocumentedOnes(): void
    {
        $settings = new MarkupSettings();

        $this->assertSame('users/name/', $settings->get('atBaseUrl'));
        $this->assertSame('entries/htmx-posting/', $settings->get('hashBaseUrl'));
    }
}
