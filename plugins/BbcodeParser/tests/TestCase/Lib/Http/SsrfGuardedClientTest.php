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
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\BbcodeParser\src\Lib\Http\SsrfBlockedException;
use Plugin\BbcodeParser\src\Lib\Http\SsrfGuardedClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Saito\Test\SaitoTestCase;

/**
 * The PSR-18 client must refuse to fetch an internal target *before* opening
 * any connection. These hosts resolve to a private/loopback/link-local address,
 * so the guard short-circuits to a status-0 response with no network access —
 * the assertions are deterministic and offline.
 */
class SsrfGuardedClientTest extends SaitoTestCase
{
    #[DataProvider('blockedUrlProvider')]
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
            ['http://10.20.30.40/'],
            ['http://192.168.1.1/'],
            ['https://[::1]/'],
        ];
    }

    /**
     * The client hands the URL to curl, which also speaks `file:` and a
     * dozen other protocols. Nothing but http and https may be fetched, and
     * the client says so itself, before it resolves a host: the message is
     * its own, not the one for a fetch curl gave up on.
     *
     * The URI is a stub because the one Saito uses (laminas-diactoros)
     * refuses such a scheme on its own; the client must not depend on that.
     */
    #[DataProvider('otherSchemeProvider')]
    public function testOnlyHttpAndHttpsAreFetched(string $scheme, string $host, string $url): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn($scheme);
        $uri->method('getHost')->willReturn($host);
        $uri->method('getPort')->willReturn(null);
        $uri->method('__toString')->willReturn($url);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaders')->willReturn([]);
        $request->method('getHeaderLine')->willReturn('');

        $this->expectException(SsrfBlockedException::class);
        $this->expectExceptionMessage('not http or https');
        (new SsrfGuardedClient())->sendRequest($request);
    }

    /**
     * @return array<array{0: string, 1: string, 2: string}>
     */
    public static function otherSchemeProvider(): array
    {
        return [
            ['file', '', 'file:///etc/hostname'],
            ['file', '93.184.216.34', 'file://93.184.216.34/etc/hostname'],
            ['gopher', '93.184.216.34', 'gopher://93.184.216.34/_ping'],
            ['dict', '93.184.216.34', 'dict://93.184.216.34/stats'],
        ];
    }
}
