<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Plugin\BbcodeParser\src\Lib\Http;

use Composer\CaBundle\CaBundle;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\Uri;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * SSRF-hardened PSR-18 HTTP client for embed/embed (v4).
 *
 * embed v4 fetches the [embed] URL — and its preview images — through a
 * PSR-18 client injected into the Crawler. Every fetch (page and images)
 * therefore passes through this one {@see sendRequest()}, which:
 *
 *  - follows redirects *manually* (so each hop is validated), capped low;
 *  - resolves each hop's host once, requires every address to be public, and
 *    pins the connection to that validated IP via CURLOPT_RESOLVE — so neither
 *    DNS-rebinding nor a redirect to an internal host can be reached;
 *  - allows http/https only.
 *
 * A refused target raises a PSR-18 {@see SsrfBlockedException}, which embed
 * treats as a failed fetch; the [embed] handler then falls back to a plain
 * link. This is the v4 counterpart of the previous SsrfGuardedDispatcher.
 */
class SsrfGuardedClient implements ClientInterface
{
    /** @var int hard cap on redirect hops we will follow */
    private const MAX_REDIRECTS = 5;

    private ResponseFactoryInterface $responseFactory;

    /**
     * @param \Psr\Http\Message\ResponseFactoryInterface|null $responseFactory PSR-17 factory
     */
    public function __construct(?ResponseFactoryInterface $responseFactory = null)
    {
        $this->responseFactory = $responseFactory ?? new ResponseFactory();
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $pinnedIp = SsrfGuard::pinnableIpv4($uri->getHost());
            if ($pinnedIp === null) {
                // Host missing, unresolvable, or resolves to a non-public
                // address → refuse without connecting. A network exception ⇒
                // embed treats it as a failed fetch ⇒ the [embed] handler
                // falls back to a link.
                throw new SsrfBlockedException(
                    $request,
                    sprintf('Refused SSRF-unsafe embed target "%s".', (string)$uri),
                );
            }

            [$status, $headers, $body] = $this->fetch($uri, $pinnedIp, $request);

            // curl could not obtain a response (connection refused, timeout, …).
            if ($status < 100) {
                throw new SsrfBlockedException(
                    $request,
                    sprintf('No response fetching embed target "%s".', (string)$uri),
                );
            }

            $location = $this->redirectLocation($status, $headers);
            if ($location !== null) {
                $uri = $this->resolveLocation($uri, $location);
                continue;
            }

            return $this->buildResponse($status, $headers, $body);
        }

        // Too many redirects.
        throw new SsrfBlockedException(
            $request,
            sprintf('Too many redirects fetching embed target "%s".', (string)$request->getUri()),
        );
    }

    /**
     * Fetch a single hop with curl — no redirect following, http/https only,
     * pinned to the pre-validated IP.
     *
     * @param \Psr\Http\Message\UriInterface $uri the URI to fetch
     * @param string $pinnedIp validated public IPv4 to pin the host to
     * @param \Psr\Http\Message\RequestInterface $request the originating request (for method/headers)
     * @return array{0: int, 1: array<string, string[]>, 2: string} status, headers, body
     */
    private function fetch(UriInterface $uri, string $pinnedIp, RequestInterface $request): array
    {
        $scheme = strtolower($uri->getScheme());
        $isHttps = $scheme === 'https';
        $port = $uri->getPort() ?? ($isHttps ? 443 : 80);

        $headers = [];
        $body = '';

        $connection = curl_init((string)$uri);
        curl_setopt_array($connection, [
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_NOBODY => strtoupper($request->getMethod()) === 'HEAD',
            CURLOPT_HTTPHEADER => $this->flattenRequestHeaders($request),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_USERAGENT => $request->getHeaderLine('User-Agent') ?: 'Embed PHP library',
            // Pin the host to the validated IP: curl connects here instead of
            // re-resolving (which could return a rebound internal address).
            CURLOPT_RESOLVE => [$uri->getHost() . ':' . $port . ':' . $pinnedIp],
            CURLOPT_SSL_VERIFYHOST => $isHttps ? 2 : 0,
            CURLOPT_SSL_VERIFYPEER => $isHttps,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($isHttps && class_exists(CaBundle::class)) {
            curl_setopt($connection, CURLOPT_CAINFO, CaBundle::getSystemCaRootBundlePath());
        }

        curl_exec($connection);
        $status = (int)curl_getinfo($connection, CURLINFO_RESPONSE_CODE);
        curl_close($connection);

        return [$status, $headers, $body];
    }

    /**
     * The redirect target from a response, or null if it should not be followed.
     *
     * @param int $status HTTP status code
     * @param array<string, string[]> $headers lower-cased header name => values
     * @return string|null
     */
    private function redirectLocation(int $status, array $headers): ?string
    {
        if ($status < 300 || $status >= 400) {
            return null;
        }
        $location = $headers['location'][0] ?? null;

        return $location !== null && $location !== '' ? $location : null;
    }

    /**
     * Resolve a (possibly relative) Location header against the current URI.
     *
     * @param \Psr\Http\Message\UriInterface $base the URI the redirect came from
     * @param string $location the raw Location header value
     * @return \Psr\Http\Message\UriInterface the absolute target
     */
    private function resolveLocation(UriInterface $base, string $location): UriInterface
    {
        $target = new Uri($location);

        // Absolute URL (has a scheme) — use as-is.
        if ($target->getScheme() !== '') {
            return $target;
        }

        // Scheme-relative (//host/path).
        if ($target->getAuthority() !== '') {
            return $target->withScheme($base->getScheme());
        }

        // Same-origin: carry scheme + authority from the base.
        $resolved = $target
            ->withScheme($base->getScheme())
            ->withHost($base->getHost())
            ->withPort($base->getPort());
        if ($base->getUserInfo() !== '') {
            $resolved = $resolved->withUserInfo($base->getUserInfo());
        }

        // Root-relative paths are already absolute-from-root; a bare relative
        // path is resolved against the base directory.
        $path = $target->getPath();
        if ($path === '' || $path[0] !== '/') {
            $baseDir = preg_replace('#/[^/]*$#', '/', $base->getPath()) ?: '/';
            $resolved = $resolved->withPath($baseDir . $path);
        }

        return $resolved;
    }

    /**
     * Build a PSR-7 response from the captured status, headers and body.
     *
     * @param int $status HTTP status code
     * @param array<string, string[]> $headers lower-cased header name => values
     * @param string $body response body
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function buildResponse(int $status, array $headers, string $body): ResponseInterface
    {
        // sendRequest() has already rejected status < 100, so this is a real
        // HTTP status the factory accepts.
        $response = $this->responseFactory->createResponse($status);
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }
        $response->getBody()->write($body);
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * Flatten a PSR-7 request's headers into curl's "Name: value" list.
     *
     * @param \Psr\Http\Message\RequestInterface $request the request
     * @return array<string>
     */
    private function flattenRequestHeaders(RequestInterface $request): array
    {
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            // The User-Agent is set via CURLOPT_USERAGENT; skip the duplicate.
            if (strtolower($name) === 'user-agent') {
                continue;
            }
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return $lines;
    }
}
