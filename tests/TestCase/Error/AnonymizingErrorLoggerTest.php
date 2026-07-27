<?php

declare(strict_types=1);

namespace App\Test\TestCase\Error;

use App\Error\AnonymizingErrorLogger;
use Cake\Controller\Exception\MissingActionException;
use Cake\Core\Configure;
use Cake\Http\Exception\MissingControllerException;
use Cake\Http\ServerRequest;
use Cake\Routing\Exception\MissingRouteException;
use Cake\TestSuite\TestCase;
use RuntimeException;

class AnonymizingErrorLoggerTest extends TestCase
{
    /**
     * The host part goes, the network part stays — enough to tell clients in
     * different networks apart without identifying anyone.
     *
     * @return void
     */
    public function testAnonymizeIp(): void
    {
        $this->assertSame('217.250.13.x', AnonymizingErrorLogger::anonymizeIp('217.250.13.44'));
        $this->assertSame('10.0.31.x', AnonymizingErrorLogger::anonymizeIp('10.0.31.28'));
        $this->assertSame(
            '2a02:8109:abcd:1234:x',
            AnonymizingErrorLogger::anonymizeIp('2a02:8109:abcd:1234:5678:9abc:def0:1')
        );
    }

    /**
     * Anything that is not an address shape we recognise is dropped rather than
     * passed through half-masked.
     *
     * @return void
     */
    public function testAnonymizeIpRejectsUnknownShapes(): void
    {
        $this->assertSame('x', AnonymizingErrorLogger::anonymizeIp('not-an-address'));
        $this->assertSame('x', AnonymizingErrorLogger::anonymizeIp('217.250.13'));
    }

    /**
     * The point of the class: what CakePHP puts into error.log must not carry a
     * full address any more.
     *
     * @return void
     */
    public function testRequestContextMasksClientIp(): void
    {
        $request = new ServerRequest([
            'url' => '/entries/index',
            'environment' => ['REMOTE_ADDR' => '217.250.13.44'],
        ]);

        $context = (new AnonymizingErrorLogger())->getRequestContext($request);

        $this->assertStringContainsString('Client IP: 217.250.13.x', $context);
        $this->assertStringNotContainsString('217.250.13.44', $context);
    }

    /**
     * Build a request for the forum's own host, optionally with a referer.
     *
     * @param string|null $referer referer header
     * @return \Cake\Http\ServerRequest
     */
    private function requestWithReferer(?string $referer): ServerRequest
    {
        $env = ['HTTP_HOST' => 'forum.example.com'];
        if ($referer !== null) {
            $env['HTTP_REFERER'] = $referer;
        }

        return new ServerRequest(['url' => '/config/database', 'environment' => $env]);
    }

    /**
     * A scanner arrives without a referer, or from somewhere else entirely.
     * Those must not fill the error log.
     *
     * @return void
     */
    public function testProbeWithoutOrForeignReferer(): void
    {
        $exception = new MissingControllerException(['class' => 'Config']);

        $this->assertTrue(
            AnonymizingErrorLogger::isRoutingProbe($exception, $this->requestWithReferer(null)),
            'no referer'
        );
        $this->assertTrue(
            AnonymizingErrorLogger::isRoutingProbe($exception, $this->requestWithReferer('https://scanner.example.net/x')),
            'foreign referer'
        );
    }

    /**
     * The point of the whole exercise: a dead link on our own pages stays a
     * logged error.
     *
     * @return void
     */
    public function testOwnRefererIsNotAProbe(): void
    {
        $exception = new MissingControllerException(['class' => 'Config']);

        $this->assertFalse(
            AnonymizingErrorLogger::isRoutingProbe(
                $exception,
                $this->requestWithReferer('https://forum.example.com/entries/index')
            )
        );
    }

    /**
     * Behind a TLS-terminating proxy the request host differs from the public
     * origin, so App.fullBaseUrl counts as ours as well.
     *
     * @return void
     */
    public function testFullBaseUrlCountsAsOwnHost(): void
    {
        Configure::write('App.fullBaseUrl', 'https://public.example.org');
        $exception = new MissingRouteException(['url' => '/nope']);

        $this->assertFalse(
            AnonymizingErrorLogger::isRoutingProbe(
                $exception,
                $this->requestWithReferer('https://public.example.org/entries/index')
            )
        );
    }

    /**
     * Anything that is not a routing error is never treated as a probe — a real
     * failure must not vanish because it happened to arrive without a referer.
     *
     * @return void
     */
    public function testOtherExceptionsAreNeverProbes(): void
    {
        $this->assertFalse(
            AnonymizingErrorLogger::isRoutingProbe(
                new RuntimeException('database is on fire'),
                $this->requestWithReferer(null)
            )
        );
    }

    /**
     * All three routing exception types are covered, not just the common one.
     *
     * @return void
     */
    public function testAllRoutingExceptionTypesAreCovered(): void
    {
        $request = $this->requestWithReferer(null);

        foreach ([
            new MissingControllerException(['class' => 'Config']),
            new MissingActionException(['controller' => 'Entries', 'action' => 'nope']),
            new MissingRouteException(['url' => '/nope']),
        ] as $exception) {
            $this->assertTrue(
                AnonymizingErrorLogger::isRoutingProbe($exception, $request),
                get_class($exception)
            );
        }
    }
}
