<?php

declare(strict_types=1);

namespace App\Test\TestCase\Lib\Saito\Markup;

use Cake\Core\Configure;
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
     * Both defaults must resolve to a controller action that actually exists —
     * for either frontend, since this release still ships both.
     *
     * @return void
     */
    public function testDefaultBaseUrlsPointAtLiveActions(): void
    {
        $this->loadRoutes();

        foreach (['spa', 'island'] as $frontend) {
            Configure::write('Saito.frontend', $frontend);
            $settings = new MarkupSettings();

            foreach (['atBaseUrl' => 'Mitch', 'hashBaseUrl' => '1'] as $key => $argument) {
                $url = '/' . trim((string)$settings->get($key), '/') . '/' . $argument;
                $parsed = Router::parseRequest(new ServerRequest(['url' => $url]));

                $controller = 'App\\Controller\\' . $parsed['controller'] . 'Controller';
                $hint = sprintf(
                    '%s ("%s", frontend=%s) routes to %s::%s(). Every @mention and #tag in '
                        . 'existing posting text points there — retire it and those links die '
                        . 'silently, all at once.',
                    $key,
                    $url,
                    $frontend,
                    $controller,
                    $parsed['action']
                );
                $this->assertTrue(class_exists($controller), $hint);
                $this->assertTrue(method_exists($controller, $parsed['action']), $hint);
                $this->assertTrue(
                    (new ReflectionMethod($controller, $parsed['action']))->isPublic(),
                    $hint
                );
            }
        }
    }

    /**
     * The `#123` tag follows the active frontend: on an island install
     * `entries/view` renders the SPA shell, which drops the reader out of the
     * interface they were using.
     *
     * @return void
     */
    public function testHashBaseUrlFollowsTheFrontend(): void
    {
        Configure::write('Saito.frontend', 'island');
        $this->assertSame('entries/htmx-posting/', (new MarkupSettings())->get('hashBaseUrl'));

        Configure::write('Saito.frontend', 'spa');
        $this->assertSame('entries/view/', (new MarkupSettings())->get('hashBaseUrl'));
    }

    /**
     * `users/name/` needs no branch: it has no view of its own, it resolves a
     * name to an ID and redirects to whichever profile the frontend uses.
     *
     * @return void
     */
    public function testAtBaseUrlIsFrontendIndependent(): void
    {
        foreach (['spa', 'island'] as $frontend) {
            Configure::write('Saito.frontend', $frontend);
            $this->assertSame('users/name/', (new MarkupSettings())->get('atBaseUrl'));
        }
    }
}
