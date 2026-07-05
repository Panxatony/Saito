<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\Authenticator;

use ArrayObject;
use Authentication\Authenticator\AbstractAuthenticator;
use Authentication\Authenticator\Result;
use Authentication\Authenticator\ResultInterface;
use Authentication\Authenticator\StatelessInterface;
use Cake\ORM\TableRegistry;
use Feeds\Auth\FeedToken;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticates a personalized RSS feed request from a signed token in the URL
 * path: `.../feeds/f/<userId>-<signature>/postings/...`.
 *
 * A feed reader cannot run the SPA's JWT login flow, so it authenticates by the
 * signed URL the logged-in user copied from the forum. On success the user-id
 * becomes the identity (`sub`), exactly like the JWT authenticator's returned
 * payload — AuthUserComponent reloads the user from it, and the feed then shows
 * that user's readable (non-public) categories.
 *
 * Stateless: an invalid or missing token is not challenged, it simply falls
 * through to the other authenticators and the public feed. Nothing is persisted
 * to a session (readers send no cookies).
 */
class FeedTokenAuthenticator extends AbstractAuthenticator implements StatelessInterface
{
    /**
     * Matches `/feeds/f/<userId>-<signature>/` anywhere in the path so it also
     * works under a sub-directory webroot (`/forum/feeds/f/...`).
     */
    private const PATH_PATTERN = '#/feeds/f/(\d+)-([0-9a-f]+)/#';

    /**
     * @inheritDoc
     */
    public function authenticate(ServerRequestInterface $request): ResultInterface
    {
        $path = $request->getUri()->getPath();
        if (!preg_match(self::PATH_PATTERN, $path, $matches)) {
            // Not a tokenized feed URL: let the session/cookie authenticators
            // and `allowUnauthenticated` handle it (public feed for anon, or an
            // in-browser logged-in user via their session).
            return new Result(null, Result::FAILURE_CREDENTIALS_MISSING);
        }

        $userId = (int)$matches[1];
        $signature = $matches[2];

        $user = TableRegistry::getTableLocator()->get('Users')
            ->find()
            ->select(['id', 'password'])
            ->where(['Users.id' => $userId])
            ->first();
        if ($user === null) {
            return new Result(null, Result::FAILURE_IDENTITY_NOT_FOUND);
        }

        if (!FeedToken::verify($userId, (string)$user->get('password'), $signature)) {
            return new Result(null, Result::FAILURE_CREDENTIALS_INVALID);
        }

        // Mirror the JWT authenticator: the identity is a payload carrying
        // `sub`; AuthUserComponent hydrates the full user entity from it.
        return new Result(new ArrayObject(['sub' => $userId]), Result::SUCCESS);
    }

    /**
     * @inheritDoc
     */
    public function unauthorizedChallenge(ServerRequestInterface $request): void
    {
        // No challenge: the feed actions are `allowUnauthenticated`, so an
        // invalid/missing token just yields the public feed instead of a 401.
    }
}
