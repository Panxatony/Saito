<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Error;

use Cake\Controller\Exception\MissingActionException;
use Cake\Core\Configure;
use Cake\Error\ErrorLogger;
use Cake\Http\Exception\MissingControllerException;
use Cake\Log\Engine\FileLog;
use Cake\Routing\Exception\MissingRouteException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Error logger that masks the client IP address and keeps scanner probes out
 * of the error log.
 *
 * Two problems, one place to fix them.
 *
 * **The IP address.** CakePHP appends `Client IP: <address>` to every logged
 * exception, with no setting to turn it off — so an installation that has
 * deliberately switched the `store_ip` forum setting off still ends up with
 * full visitor addresses in error.log, kept for as long as the log file is.
 * They are masked the same way `store_ip_anonymized` masks them for postings.
 *
 * **The probes.** Vulnerability scanners request paths like `/config/database`
 * or `/api/…` around the clock. Each one produces a `MissingControllerException`
 * with a full stack trace, which is what makes error.log unreadable — measured
 * on one installation: `Config` 2975×, `Api` 1835×, `WpIncludes` 252×.
 *
 * Skipping those exceptions wholesale (`Error.skipLog`) would also hide a *real*
 * routing bug, so they are told apart instead: a broken link on our own pages is
 * followed from our own pages, so the referer points at this host. A probe
 * arrives without a referer, or with a foreign one.
 *
 * Probes are not discarded — they go to their own log file. What was never
 * written cannot be looked at later, and "someone reached a dead URL without a
 * referer" is occasionally worth reading. The referer is an indication, not
 * proof (it is trivially forged and some browsers omit it), which is exactly why
 * it only decides *which log* a line goes to and never grants or denies
 * anything.
 */
class AnonymizingErrorLogger extends ErrorLogger
{
    /**
     * Exceptions that a wrong URL produces — whether typed by a scanner or
     * linked by mistake from our own templates.
     *
     * @var list<class-string<\Throwable>>
     */
    private const ROUTING_EXCEPTIONS = [
        MissingControllerException::class,
        MissingActionException::class,
        MissingRouteException::class,
    ];

    /** @var \Cake\Log\Engine\FileLog|null lazily created probe log */
    private static ?FileLog $probeLog = null;

    /**
     * {@inheritDoc}
     *
     * @param \Throwable $exception the exception
     * @param \Psr\Http\Message\ServerRequestInterface|null $request the request
     * @param bool $includeTrace whether to include a stack trace
     * @return void
     */
    public function logException(
        Throwable $exception,
        ?ServerRequestInterface $request = null,
        bool $includeTrace = false,
    ): void {
        if (!static::isRoutingProbe($exception, $request)) {
            parent::logException($exception, $request, $includeTrace);

            return;
        }

        // Deliberately without a stack trace: for a probe the interesting part
        // is the URL, and the trace is what bloats the file.
        $message = $this->getMessage($exception, false, false);
        if ($request !== null) {
            $message .= $this->getRequestContext($request);
        }
        static::logProbe($message);
    }

    /**
     * Whether this looks like a scanner probe rather than a broken link of our
     * own.
     *
     * @param \Throwable $exception the exception
     * @param \Psr\Http\Message\ServerRequestInterface|null $request the request
     * @return bool true when the error log should be spared
     */
    public static function isRoutingProbe(
        Throwable $exception,
        ?ServerRequestInterface $request = null,
    ): bool {
        $isRouting = false;
        foreach (self::ROUTING_EXCEPTIONS as $class) {
            if ($exception instanceof $class) {
                $isRouting = true;
                break;
            }
        }
        if (!$isRouting || $request === null) {
            return $isRouting;
        }

        $referer = $request->getHeaderLine('Referer');
        if ($referer === '') {
            return true;
        }

        // Followed from one of our own pages → something we published links to a
        // dead route. That is a bug of ours and belongs in the error log.
        $refererHost = parse_url($referer, PHP_URL_HOST);

        return !is_string($refererHost) || !static::isOwnHost($refererHost, $request);
    }

    /**
     * Compare a referer host against this installation's own host.
     *
     * @param string $host host taken from the referer
     * @param \Psr\Http\Message\ServerRequestInterface $request the request
     * @return bool
     */
    protected static function isOwnHost(string $host, ServerRequestInterface $request): bool
    {
        $own = $request->getUri()->getHost();
        if ($own !== '' && strcasecmp($host, $own) === 0) {
            return true;
        }

        // Behind a TLS-terminating proxy the request host can differ from the
        // public origin, so the configured base URL counts as ours too.
        $base = (string)Configure::read('App.fullBaseUrl');
        $baseHost = $base !== '' ? parse_url($base, PHP_URL_HOST) : null;

        return is_string($baseHost) && strcasecmp($host, $baseHost) === 0;
    }

    /**
     * Write a probe to its own file, bypassing the Log facade so it cannot leak
     * into debug.log through a catch-all engine.
     *
     * Set `Saito.log.probes` to false to drop them instead.
     *
     * @param string $message the message
     * @return void
     */
    protected static function logProbe(string $message): void
    {
        if (Configure::read('Saito.log.probes') === false) {
            return;
        }
        // self:: statt static:: — die Eigenschaft ist privat, eine Unterklasse
        // koennte sie sonst verdecken.
        // Knapper als FileLogs Vorgabe (10 MB x 10 Archive = bis 100 MB): fuer
        // Rauschen reichen zwei Archive. Sonst waere das unbegrenzte Wachstum
        // nur aus error.log hierher verschoben.
        self::$probeLog ??= new FileLog([
            'path' => LOGS,
            'file' => 'probe',
            'size' => 5242880,
            'rotate' => 2,
        ]);
        self::$probeLog->log(LogLevel::INFO, $message);
    }

    /**
     * {@inheritDoc}
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request the request
     * @return string
     */
    public function getRequestContext(ServerRequestInterface $request): string
    {
        $context = parent::getRequestContext($request);

        return (string)preg_replace_callback(
            '/^Client IP: (.+)$/m',
            fn(array $matches): string => 'Client IP: ' . static::anonymizeIp($matches[1]),
            $context
        );
    }

    /**
     * Drop the host part of an address: the last octet of an IPv4 address, the
     * interface identifier of an IPv6 one.
     *
     * @param string $ip address to mask
     * @return string masked address
     */
    public static function anonymizeIp(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $blocks = explode(':', $ip);
            $kept = array_slice($blocks, 0, 4);

            return implode(':', $kept) . ':x';
        }

        $octets = explode('.', $ip);
        if (count($octets) !== 4) {
            // Not an address shape we recognise — drop it rather than guess.
            return 'x';
        }
        $octets[3] = 'x';

        return implode('.', $octets);
    }
}
