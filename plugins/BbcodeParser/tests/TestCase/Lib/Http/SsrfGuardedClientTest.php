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

use Laminas\Diactoros\Request;
use Plugin\BbcodeParser\src\Lib\Http\SsrfBlockedException;
use Plugin\BbcodeParser\src\Lib\Http\SsrfGuardedClient;
use Saito\Test\SaitoTestCase;

/**
 * The PSR-18 client must refuse to fetch an internal target *before* opening
 * any connection. These hosts resolve to a private/loopback/link-local address,
 * so the guard short-circuits to a status-0 response with no network access —
 * the assertions are deterministic and offline.
 */
class SsrfGuardedClientTest extends SaitoTestCase
{
    /**
     * @dataProvider blockedUrlProvider
     */
    public function testInternalTargetsAreBlockedWithoutFetching(string $url): void
    {
        $client = new SsrfGuardedClient();

        // A refused host raises a PSR-18 network exception before any
        // connection — embed treats it as a failed fetch and the [embed]
        // handler falls back to a plain link.
        $this->expectException(SsrfBlockedException::class);
        $client->sendRequest(new Request($url, 'GET'));
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
}
