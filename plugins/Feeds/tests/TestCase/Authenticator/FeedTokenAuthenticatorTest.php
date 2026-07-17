<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\Test\TestCase\Authenticator;

use Authentication\Authenticator\Result;
use Authentication\Identifier\IdentifierCollection;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Cake\Utility\Security;
use Feeds\Auth\FeedToken;
use Feeds\Authenticator\FeedTokenAuthenticator;

class FeedTokenAuthenticatorTest extends TestCase
{
    public array $fixtures = ['app.User'];

    private FeedTokenAuthenticator $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Security::setSalt(str_repeat('a', 32));
        $this->auth = new FeedTokenAuthenticator(new IdentifierCollection());
    }

    private function tokenFor(int $userId): string
    {
        $user = TableRegistry::getTableLocator()->get('Users')
            ->find()->select(['id', 'password'])->where(['Users.id' => $userId])->first();

        return FeedToken::build($userId, (string)$user->get('password'));
    }

    public function testAuthenticatesOnGenuineFeedEndpoint(): void
    {
        $token = $this->tokenFor(3);
        foreach (["/feeds/f/$token/postings/new.rss", "/feeds/f/$token/postings/threads.rss"] as $url) {
            $result = $this->auth->authenticate(new ServerRequest(['url' => $url]));
            $this->assertTrue($result->isValid(), "should authenticate: $url");
            $this->assertSame(3, $result->getData()['sub']);
        }
    }

    public function testAuthenticatesUnderSubdirectoryWebroot(): void
    {
        $token = $this->tokenFor(3);
        $result = $this->auth->authenticate(
            new ServerRequest(['url' => "/forum/feeds/f/$token/postings/new.rss"]),
        );
        $this->assertTrue($result->isValid());
    }

    /**
     * SECURITY regression: a valid token must only authenticate on the two feed
     * endpoints, never merely because it appears somewhere in the path. The old
     * substring match would have granted the user's full identity to any route
     * whose URL contained the token.
     */
    public function testDoesNotAuthenticateWhenTokenIsNotTheFeedEndpoint(): void
    {
        $token = $this->tokenFor(3);
        $urls = [
            "/entries/index/feeds/f/$token/x",
            "/feeds/f/$token/postings/delete",
            "/feeds/f/$token/admin",
            "/feeds/f/$token/postings/new/extra",
        ];
        foreach ($urls as $url) {
            $result = $this->auth->authenticate(new ServerRequest(['url' => $url]));
            $this->assertFalse($result->isValid(), "must NOT authenticate: $url");
            $this->assertSame(Result::FAILURE_CREDENTIALS_MISSING, $result->getStatus());
        }
    }

    public function testTamperedTokenOnFeedEndpointFails(): void
    {
        $result = $this->auth->authenticate(
            new ServerRequest(['url' => '/feeds/f/3-' . str_repeat('0', 32) . '/postings/new.rss']),
        );
        $this->assertFalse($result->isValid());
        $this->assertSame(Result::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
    }
}
