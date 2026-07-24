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

use Plugin\BbcodeParser\src\Lib\Http\SsrfGuard;
use Saito\Test\SaitoTestCase;

/**
 * Deterministic (DNS-free) tests for the SSRF IP/host classifier. Hostname
 * resolution is intentionally not exercised here — IP literals cover the whole
 * decision logic without depending on a resolver.
 */
class SsrfGuardTest extends SaitoTestCase
{
    /**
     * @dataProvider ipProvider
     */
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        $this->assertSame($expected, SsrfGuard::isPublicIp($ip));
    }

    /**
     * @return array<array{0: string, 1: bool}>
     */
    public static function ipProvider(): array
    {
        return [
            // public
            ['8.8.8.8', true],
            ['1.1.1.1', true],
            ['93.184.216.34', true],
            ['2606:4700:4700::1111', true],
            // loopback
            ['127.0.0.1', false],
            ['::1', false],
            // RFC1918 private
            ['10.0.0.1', false],
            ['10.0.31.28', false],
            ['192.168.1.1', false],
            ['172.16.0.1', false],
            // link-local incl. the cloud-metadata endpoint
            ['169.254.169.254', false],
            ['fe80::1', false],
            // unique-local IPv6
            ['fd00::1', false],
            // not an IP at all
            ['not-an-ip', false],
            ['', false],
        ];
    }

    public function testIsPublicHostWithIpLiterals(): void
    {
        $this->assertTrue(SsrfGuard::isPublicHost('8.8.8.8'));
        $this->assertFalse(SsrfGuard::isPublicHost('127.0.0.1'));
        $this->assertFalse(SsrfGuard::isPublicHost('169.254.169.254'));
        $this->assertFalse(SsrfGuard::isPublicHost('10.0.31.28'));
        $this->assertFalse(SsrfGuard::isPublicHost('[::1]'));
        $this->assertFalse(SsrfGuard::isPublicHost(''));
    }

    public function testPinnableIpv4(): void
    {
        // A public IPv4 literal is returned verbatim for pinning.
        $this->assertSame('8.8.8.8', SsrfGuard::pinnableIpv4('8.8.8.8'));
        // Private / loopback → refuse.
        $this->assertNull(SsrfGuard::pinnableIpv4('127.0.0.1'));
        $this->assertNull(SsrfGuard::pinnableIpv4('10.0.0.1'));
        // IPv6-only literal → null (the embed fetch is IPv4-only).
        $this->assertNull(SsrfGuard::pinnableIpv4('2606:4700:4700::1111'));
        $this->assertNull(SsrfGuard::pinnableIpv4(''));
    }

    public function testResolveHostAcceptsIpLiteral(): void
    {
        $this->assertSame(['8.8.8.8'], SsrfGuard::resolveHost('8.8.8.8'));
        $this->assertSame(['::1'], SsrfGuard::resolveHost('[::1]'));
        $this->assertSame([], SsrfGuard::resolveHost(''));
    }
}
