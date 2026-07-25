<?php
/**
 * Full thread reading view as an htmx island (strangler-fig PoC).
 *
 * Reachable at /entries/htmx-thread/<tid>. Renders the whole thread flattened
 * ("mix" renderer), standalone (no SPA). The reusable island enhances the
 * per-posting answer buttons (inline reply). Read-only otherwise.
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface $entries
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
$webroot = $this->getRequest()->getAttribute('webroot');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<p class="mix-back" style="margin: 0 0 .75rem;">
    <a href="<?= $webroot ?>entries/htmx-index" class="btn btn-link" rel="nofollow">
        <?= $this->Layout->textWithIcon(h(__('Back')), 'arrow-left') ?>
    </a>
</p>

<div class="entry mix js-thread-island">
    <?= $this->Posting->renderThread($entries, ['renderer' => 'mix']) ?>
</div>

<?php
// Reusable htmx + Alpine thread-list island (ENTRY=htmx-threads).
