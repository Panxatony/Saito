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
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="entry mix js-thread-island">
    <?= $this->Posting->renderThread($entries, ['renderer' => 'mix']) ?>
</div>

<?php
// Reusable htmx + Alpine thread-list island (ENTRY=htmx-threads).
