<?php
/**
 * The live "new postings" poller slot for the front-page island.
 *
 * Polls htmxNewCount every 30s and swaps the banner (if any) into itself. With
 * `$oob = true` it carries `hx-swap-oob` so the thread-list refresh fragment can
 * replace the whole slot out-of-band — resetting the `since` marker to the fresh
 * id and clearing the banner in one swap.
 *
 * @var \App\View\AppView $this
 * @var int $newestEntryId
 * @var bool $oob
 */

$oob = $oob ?? false;
$newCountUrl = $this->Url->build([
    'controller' => 'Entries',
    'action' => 'htmxNewCount',
    '?' => ['since' => $newestEntryId],
]);
?>
<div id="js-newPostsBanner"<?= $oob ? ' hx-swap-oob="true"' : '' ?>
     hx-get="<?= h($newCountUrl) ?>"
     hx-trigger="every 30s"
     hx-swap="innerHTML"></div>
