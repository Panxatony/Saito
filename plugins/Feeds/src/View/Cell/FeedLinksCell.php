<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\View\Cell;

use Cake\Routing\Router;
use Cake\View\Cell;
use Feeds\Auth\FeedToken;
use Saito\User\CurrentUser\CurrentUserInterface;

/**
 * Renders the RSS feed links (on the RSS-feeds page and in the user profile).
 *
 * For a logged-in user the links carry their personal signed token, so an RSS
 * reader that subscribes to them sees the same non-public categories the user
 * can read in the browser. Anonymous visitors get the plain public feed links.
 */
class FeedLinksCell extends Cell
{
    /**
     * @param \Saito\User\CurrentUser\CurrentUserInterface $currentUser current user
     * @return void
     */
    public function display(CurrentUserInterface $currentUser): void
    {
        $personalized = false;
        $prefix = 'feeds/postings/';

        if ($currentUser->isLoggedIn()) {
            $userId = (int)$currentUser->getId();
            $user = $this->fetchTable('Users')
                ->find()
                ->select(['id', 'password'])
                ->where(['Users.id' => $userId])
                ->first();
            if ($user !== null) {
                $token = FeedToken::build($userId, (string)$user->get('password'));
                $prefix = 'feeds/f/' . $token . '/postings/';
                $personalized = true;
            }
        }

        // Full URLs so the user can copy them straight into their reader.
        $feeds = [
            [
                'label' => __d('feeds', 'postings.new.t'),
                'url' => Router::url('/' . $prefix . 'new.rss', true),
            ],
            [
                'label' => __d('feeds', 'threads.new.t'),
                'url' => Router::url('/' . $prefix . 'threads.rss', true),
            ],
        ];

        $this->set('feeds', $feeds);
        $this->set('personalized', $personalized);
    }
}
