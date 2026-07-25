<?php
/**
 * The current user's bookmarks as a standalone htmx island page (/users/bookmarks).
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface[] $bookmarkPostings
 * @var array<int, string|null> $bookmarkComments
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<?= $this->element('users/bookmarks_content', compact('bookmarkPostings', 'bookmarkComments')) ?>

<?php
