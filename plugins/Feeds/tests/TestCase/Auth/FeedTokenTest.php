<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\Test\TestCase\Auth;

use Cake\TestSuite\TestCase;
use Cake\Utility\Security;
use Feeds\Auth\FeedToken;

class FeedTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Security::setSalt(str_repeat('a', 32));
    }

    public function testBuildFormatIsUserIdDashHexSignature()
    {
        $token = FeedToken::build(42, 'password-hash');
        $this->assertMatchesRegularExpression('/^42-[0-9a-f]{32}$/', $token);
    }

    public function testVerifyAcceptsMatchingSignature()
    {
        $token = FeedToken::build(42, 'password-hash');
        [$userId, $signature] = explode('-', $token, 2);
        $this->assertTrue(FeedToken::verify((int)$userId, 'password-hash', $signature));
    }

    public function testVerifyRejectsTamperedSignature()
    {
        $this->assertFalse(FeedToken::verify(42, 'password-hash', str_repeat('0', 32)));
    }

    public function testTokenIsBoundToUser()
    {
        // The signature for user 42 must not validate for user 43 (would let a
        // reader swap the id in the URL and read another user's categories).
        $token = FeedToken::build(42, 'password-hash');
        [, $signature] = explode('-', $token, 2);
        $this->assertFalse(FeedToken::verify(43, 'password-hash', $signature));
    }

    public function testTokenChangesWhenPasswordChanges()
    {
        // Binding to the password hash gives free revocation: after a password
        // change the old feed URL no longer validates.
        $token = FeedToken::build(42, 'old-hash');
        [, $signature] = explode('-', $token, 2);
        $this->assertFalse(FeedToken::verify(42, 'new-hash', $signature));
    }

    public function testTokenDependsOnAppSalt()
    {
        $token = FeedToken::build(42, 'password-hash');
        [, $signature] = explode('-', $token, 2);

        Security::setSalt(str_repeat('b', 32));
        $this->assertFalse(FeedToken::verify(42, 'password-hash', $signature));
    }
}
