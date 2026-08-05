<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\PasswordResetTokensTable;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * @covers \App\Model\Table\PasswordResetTokensTable
 */
class PasswordResetTokensTableTest extends TestCase
{
    protected PasswordResetTokensTable $Table;

    public array $fixtures = [
        'app.PasswordResetToken',
    ];

    public function setUp(): void
    {
        parent::setUp();
        /** @var PasswordResetTokensTable $table */
        $table = TableRegistry::getTableLocator()->get('PasswordResetTokens');
        $this->Table = $table;
    }

    public function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    /**
     * The raw token is returned, and only its SHA-256 is stored.
     */
    public function testIssueStoresOnlyTheHash(): void
    {
        $token = $this->Table->issueFor(42);

        // 32 random bytes, hex-encoded.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        $row = $this->Table->find()->where(['user_id' => 42])->firstOrFail();
        // The raw token is nowhere in the row; the stored hash is its SHA-256.
        $this->assertSame(hash('sha256', $token), $row->get('token_hash'));
        $this->assertNotSame($token, $row->get('token_hash'));
    }

    /**
     * A valid token resolves to its member; a wrong one resolves to nothing.
     */
    public function testValidTokenResolvesToUser(): void
    {
        $token = $this->Table->issueFor(7);

        $this->assertSame(7, $this->Table->userIdForToken($token));
        $this->assertNull($this->Table->userIdForToken('deadbeef'));
        $this->assertNull($this->Table->userIdForToken(''));
    }

    /**
     * Only one token per member survives — a fresh request clears the old one,
     * so an inbox full of links cannot be walked back.
     */
    public function testIssuingAgainInvalidatesTheEarlierToken(): void
    {
        $first = $this->Table->issueFor(7);
        $second = $this->Table->issueFor(7);

        $this->assertNull($this->Table->userIdForToken($first), 'the first token must stop working');
        $this->assertSame(7, $this->Table->userIdForToken($second));
        $this->assertSame(1, $this->Table->find()->where(['user_id' => 7])->count());
    }

    /**
     * An expired token resolves to nothing.
     */
    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->Table->issueFor(7);

        // Backdate the row past its lifetime.
        $this->Table->updateAll(
            ['expires' => DateTime::now()->subMinutes(1)],
            ['user_id' => 7],
        );

        $this->assertNull($this->Table->userIdForToken($token));
    }

    /**
     * Consuming clears the member's tokens so the link cannot be replayed.
     */
    public function testClearForRemovesTheToken(): void
    {
        $token = $this->Table->issueFor(7);
        $this->Table->clearFor(7);

        $this->assertNull($this->Table->userIdForToken($token));
        $this->assertSame(0, $this->Table->find()->where(['user_id' => 7])->count());
    }

    /**
     * The garbage collector removes only tokens whose lifetime has passed.
     */
    public function testDeleteExpiredSweepsOnlyStaleRows(): void
    {
        $live = $this->Table->issueFor(1);
        $this->Table->issueFor(2);
        $this->Table->updateAll(
            ['expires' => DateTime::now()->subMinutes(1)],
            ['user_id' => 2],
        );

        $removed = $this->Table->deleteExpired();

        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->Table->userIdForToken($live));
        $this->assertNull($this->Table->userIdForToken(''));
    }
}
