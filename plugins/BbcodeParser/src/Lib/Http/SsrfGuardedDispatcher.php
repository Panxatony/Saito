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
use Embed\Http\CurlDispatcher;
use Embed\Http\CurlResult;
use Embed\Http\DispatcherInterface;
use Embed\Http\Response;
use Embed\Http\Url;
use stdClass;

/**
 * SSRF-hardened replacement for embed/embed's CurlDispatcher.
 *
 * embed/embed fetches the [embed] URL server-side. The default CurlDispatcher
 * lets curl follow redirects itself (CURLOPT_FOLLOWLOCATION) and re-resolves
 * DNS for every hop, so the caller's up-front host check can be defeated by
 * DNS-rebinding (public IP at check time, internal IP at fetch time) or by a
 * public URL that 302-redirects to http://169.254.169.254/ or an intranet
 * host. This dispatcher closes both:
 *
 *  - Redirects are followed *manually* (curl's own following is disabled), so
 *    every hop passes through the same validation.
 *  - Each hop's host is resolved once, every resolved address must be public,
 *    and the connection is then pinned to that validated IP via CURLOPT_RESOLVE
 *    — curl cannot silently connect to a re-resolved (rebound) address.
 *  - Only http/https is allowed (no file://, gopher://, dict:// gadgets).
 *
 * A blocked target yields an empty (status 0) response, which embed treats as a
 * failed fetch; the [embed] handler then falls back to a plain link.
 */
class SsrfGuardedDispatcher implements DispatcherInterface
{
    /** @var int hard cap on redirect hops we will follow */
    private const MAX_REDIRECTS = 5;

    /** @var Response[] every response produced, as the interface implies */
    private $responses = [];

    /**
     * {@inheritDoc}
     */
    public function dispatch(Url $url)
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $host = (string)$current->getHost();
            $pinnedIp = SsrfGuard::pinnableIpv4($host);
            if ($pinnedIp === null) {
                // Host missing, unresolvable, or resolves to a non-public
                // address → refuse. Empty response ⇒ embed finds no code ⇒
                // the [embed] handler falls back to a link.
                return $this->responses[] = $this->blockedResponse($url, $current);
            }

            $result = $this->fetch($current, $pinnedIp);
            $statusCode = (int)$result['statusCode'];
            $location = $this->redirectLocation($statusCode, $result['headers']);

            if ($location !== null) {
                // Resolve a (possibly relative) Location against the current
                // URL and re-validate it on the next iteration.
                $current = $current->createAbsolute($location);
                continue;
            }

            return $this->responses[] = new Response(
                $url,
                Url::create($result['url'] ?? (string)$current),
                $result['statusCode'],
                $result['contentType'],
                $result['content'],
                $result['headers'],
                $result['info'],
            );
        }

        // Too many redirects.
        return $this->responses[] = $this->blockedResponse($url, $current);
    }

    /**
     * {@inheritDoc}
     *
     * Images are fetched server-side too (to read their dimensions). Drop any
     * whose host is not public; hand the safe remainder to the stock dispatcher.
     */
    public function dispatchImages(array $urls)
    {
        $safe = [];
        foreach ($urls as $url) {
            if (SsrfGuard::isPublicHost((string)$url->getHost())) {
                $safe[] = $url;
            }
        }
        if (!$safe) {
            return [];
        }

        return (new CurlDispatcher())->dispatchImages($safe);
    }

    /**
     * Fetch a single hop with curl — no redirect following, http/https only,
     * pinned to the pre-validated IP.
     *
     * @param Url $url the URL to fetch
     * @param string $pinnedIp validated public IPv4 to pin the host to
     * @return array embed CurlResult array (url/statusCode/contentType/…)
     */
    private function fetch(Url $url, string $pinnedIp): array
    {
        $scheme = strtolower((string)$url->getScheme());
        $isHttps = $scheme === 'https';
        $port = $isHttps ? 443 : 80;

        $connection = curl_init((string)$url);
        curl_setopt_array($connection, [
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            // Restrict both the initial request and (defensively) any redirect
            // curl might otherwise attempt to http/https only.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_USERAGENT => 'Embed PHP library',
            // Pin the host to the validated IP: curl connects here instead of
            // re-resolving (which could return a rebound internal address).
            CURLOPT_RESOLVE => [$url->getHost() . ':' . $port . ':' . $pinnedIp],
            CURLOPT_SSL_VERIFYHOST => $isHttps ? 2 : 0,
            CURLOPT_SSL_VERIFYPEER => $isHttps,
        ]);
        if ($isHttps && class_exists(CaBundle::class)) {
            curl_setopt($connection, CURLOPT_CAINFO, CaBundle::getSystemCaRootBundlePath());
        }

        // Mirror CurlDispatcher: collect only textual bodies.
        $curl = new CurlResult($connection);
        $curl->onHeader(function ($name, $value, $data) {
            if ($name === 'content-type') {
                $data->isBinary = !preg_match('/(text|html|json)/', strtolower($value));
            }
        });
        $curl->onBody(function ($string, stdClass $data) {
            return empty($data->isBinary);
        });

        curl_exec($connection);
        $result = $curl->getResult();
        curl_close($connection);

        return $result;
    }

    /**
     * Return the redirect target from a response, or null if it is not a
     * redirect we should follow.
     *
     * @param int $statusCode HTTP status code
     * @param array $headers lower-cased header name => list of values
     * @return string|null the Location value, or null
     */
    private function redirectLocation(int $statusCode, array $headers): ?string
    {
        if ($statusCode < 300 || $statusCode >= 400) {
            return null;
        }
        $location = $headers['location'][0] ?? null;

        return ($location !== null && $location !== '') ? $location : null;
    }

    /**
     * A synthetic empty response for a refused target.
     *
     * @param Url $startingUrl the original requested URL
     * @param Url $url the (possibly redirected) URL that was refused
     * @return Response
     */
    private function blockedResponse(Url $startingUrl, Url $url): Response
    {
        return new Response($startingUrl, $url, 0, null, '', [], []);
    }
}
