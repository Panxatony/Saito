<?php
/**
 * The current user's bookmarks as an htmx/Alpine island (strangler-fig PoC).
 *
 * Reachable at /users/bookmarks. Renders the bookmarked postings server-side as
 * thread lines inside a `.js-thread-island` container, so the shared thread-list
 * island enhances them (inline posting). Each bookmark's optional note is shown
 * above its line. Served standalone (no SPA) in the htmx_island layout.
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface[] $bookmarkPostings
 * @var array<int, string|null> $bookmarkComments
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="users bookmarks">
    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('bkm.title.pl')) ?>
        </div>
        <div class="card-body js-thread-island">
            <?php if (empty($bookmarkPostings)) : ?>
                <?= $this->element('generic/no-content-yet', ['message' => __('No bookmarks yet.')]) ?>
            <?php else : ?>
                <?php foreach ($bookmarkPostings as $posting) : ?>
                    <?php $comment = $bookmarkComments[$posting->get('id')] ?? ''; ?>
                    <?php if (!empty($comment)) : ?>
                        <div class="bookmarkComment infoText">
                            <i class="fa fa-bookmark"></i> <?= h($comment) ?>
                        </div>
                    <?php endif; ?>
                    <?= $this->Html->nestedList(
                        [$this->Posting->renderThread($posting, ['rootWrap' => true])],
                        ['class' => 'threadCollection-node root']
                    ) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Reusable htmx + Alpine thread-list island (ENTRY=htmx-threads).
echo $this->Html->script('htmx-threads.bundle.js');
