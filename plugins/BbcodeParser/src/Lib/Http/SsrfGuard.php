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

/**
 * SSRF classification helpers shared by the [embed] pre-check and the guarded
 * HTTP dispatcher.
 *
 * The rule everywhere is the same: a host is only safe to fetch server-side if
 * it resolves — and resolves *exclusively* to routable public IP addresses.
 * Anything that resolves to a loopback, private, link-local (incl. the cloud
 * metadata endpoint 169.254.169.254) or otherwise reserved range is refused.
 */
class SsrfGuard
{
    /**
     * Is a single IP a routable public address?
     *
     * Pure and deterministic (no DNS) — this is the unit-testable core.
     *
     * @param string $ip IPv4 or IPv6 literal
     * @return bool true only for a public, non-reserved address
     */
    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Resolve a host to its IP addresses, or accept an IP literal as-is.
     *
     * @param string $host hostname or IP literal (surrounding [] on IPv6 ok)
     * @return string[] resolved addresses, empty if the host cannot be resolved
     */
    public static function resolveHost(string $host): array
    {
        $host = trim($host, '[]');
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        // dns_get_record() emits a warning (and returns false) when the host
        // does not resolve. Swallow just that warning with a scoped error
        // handler — rather than the `@` operator — and treat false as "no
        // records"; the handler is always restored via finally.
        set_error_handler(static fn (): bool => true);
        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } finally {
            restore_error_handler();
        }
        foreach ($records ?: [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            $resolved = gethostbyname($host);
            if ($resolved && $resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    /**
     * Does the host resolve, and do *all* of its addresses point to public IPs?
     *
     * @param string $host hostname or IP literal
     * @return bool
     */
    public static function isPublicHost(string $host): bool
    {
        $ips = self::resolveHost($host);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A public IPv4 address to pin the connection to, defeating DNS-rebinding
     * between this check and the actual fetch.
     *
     * Returns null — refusing the fetch — if the host does not resolve, if any
     * of its addresses is non-public, or if it has no IPv4 address (the embed
     * fetch is IPv4-only, mirroring embed/embed's CurlDispatcher).
     *
     * @param string $host hostname or IP literal
     * @return string|null pinnable public IPv4, or null to refuse
     */
    public static function pinnableIpv4(string $host): ?string
    {
        $ips = self::resolveHost($host);
        if (!$ips) {
            return null;
        }
        // Any non-public address anywhere → refuse outright.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return null;
    }
}
