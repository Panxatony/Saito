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

use Cake\Error\ErrorLogger;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Error logger that masks the client IP address.
 *
 * CakePHP appends `Client IP: <address>` to every logged exception, with no
 * setting to turn it off — so an installation that has deliberately switched
 * the `store_ip` forum setting off still ends up with full visitor addresses in
 * error.log, kept for as long as the log file is.
 *
 * This masks the address the same way the forum setting `store_ip_anonymized`
 * does for postings, so the log stays useful for telling different clients
 * apart within a subnet without identifying anyone.
 */
class AnonymizingErrorLogger extends ErrorLogger
{
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
