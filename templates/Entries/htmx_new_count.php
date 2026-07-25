<?php
/**
 * Live "new postings" banner fragment for the front-page island.
 *
 * Rendered by EntriesController::htmxNewCount() (no layout); the island polls it
 * and swaps the result into the banner slot. Empty output when nothing is new,
 * so the banner simply disappears. Clicking reloads the standalone page (a fresh
 * `since` marker clears the banner) — an in-place refresh is a later refinement.
 *
 * @var \App\View\AppView $this
 * @var int $newCount
 */

if ($newCount < 1) {
    return;
}

$reloadUrl = $this->Url->build(['controller' => 'Entries', 'action' => 'htmxIndex']);
?>
<a href="<?= h($reloadUrl) ?>" class="alert alert-info d-block text-center" style="margin-bottom: 1em;">
    <i class="fa fa-arrow-up"></i>
    <?= __('{0} new posting(s) since you loaded — reload', $newCount) ?>
</a>
