<?php
/**
 * htmx response for UsersController::htmxBookmarkComment(): the note on a
 * bookmark, in whichever state was asked for. The markup itself lives in an
 * element, because the bookmarks card renders the same display state inline.
 *
 * @var \App\View\AppView $this
 * @var int $entryId
 * @var string $comment
 * @var bool $editing
 */

echo $this->element('users/bookmark_comment', compact('entryId', 'comment', 'editing'));
