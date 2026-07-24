<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace BbcodeParser\Test\Lib\Http;

use Embed\Http\Url;
use Plugin\BbcodeParser\src\Lib\Http\SsrfGuardedDispatcher;
use Saito\Test\SaitoTestCase;

/**
 * The dispatcher must refuse to fetch an internal target *before* opening any
 * connection. These cases resolve to a private/loopback/link-local address, so
 * the guard short-circuits to an empty response with no network access — the
 * assertions below are deterministic and offline.
 */
class SsrfGuardedDispatcherTest extends SaitoTestCase
{
    /**
     * @dataProvider blockedUrlProvider
     */
    public function testInternalTargetsAreBlockedWithoutFetching(string $url): void
    {
        $dispatcher = new SsrfGuardedDispatcher();
        $response = $dispatcher->dispatch(Url::create($url));

        // Status 0 + empty body is embed's "failed fetch" — the [embed] handler
        // then falls back to a plain link instead of rendering remote content.
        $this->assertSame(0, $response->getStatusCode(), "must not fetch $url");
        $this->assertSame('', $response->getContent());
    }

    /**
     * @return array<array{0: string}>
     */
    public static function blockedUrlProvider(): array
    {
        return [
            ['http://127.0.0.1/'],
            ['http://127.0.0.1:6379/'],
            ['http://169.254.169.254/latest/meta-data/'],
            ['http://10.0.31.28/'],
            ['http://192.168.1.1/'],
            ['https://[::1]/'],
        ];
    }

    public function testDispatchImagesDropsInternalHosts(): void
    {
        $dispatcher = new SsrfGuardedDispatcher();
        $images = $dispatcher->dispatchImages([
            Url::create('http://127.0.0.1/a.png'),
            Url::create('http://169.254.169.254/b.png'),
        ]);

        $this->assertSame([], $images);
    }
}
