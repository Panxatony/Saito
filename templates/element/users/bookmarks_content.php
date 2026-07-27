<?php
/**
 * Bookmarks list card — shared by the standalone page (bookmarks.php) and the
 * inline header panel (bookmarks_fragment.php). Renders the bookmarked postings
 * as thread lines in a .js-thread-island (so the island enhances them).
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface[] $bookmarkPostings
 * @var array<int, string|null> $bookmarkComments
 */
?>
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
