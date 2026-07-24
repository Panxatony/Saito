<?php
/**
 * htmx fragment response for {@see \App\Controller\UsersController::recentPosts()}.
 *
 * Rendered without a layout (see the controller) so htmx swaps just the list.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array $lastEntries
 * @var bool $hasMoreEntriesThanShownOnPage
 */

echo $this->element(
    'users/recent_posts_list',
    compact('user', 'lastEntries', 'hasMoreEntriesThanShownOnPage')
);
