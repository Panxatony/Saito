<?php

declare(strict_types=1);

namespace App\Test\TestCase\Error;

use App\Error\AnonymizingErrorLogger;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

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
}
