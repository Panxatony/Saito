<?php
/**
 * htmx fragment: just the bookmarks card, for the inline header panel.
 * (EllipsisController -> UsersController::bookmarks() on HX-Request.)
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface[] $bookmarkPostings
 * @var array<int, string|null> $bookmarkComments
 */

echo $this->element('users/bookmarks_content', compact('bookmarkPostings', 'bookmarkComments'));
