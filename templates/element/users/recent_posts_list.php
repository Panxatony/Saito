<?php
/**
 * Recent-postings thread list — server-rendered fragment.
 *
 * Extracted from the `tpl-recentposts` client template in Users/view.php so the
 * same markup can be delivered directly as an htmx fragment.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array $lastEntries
 * @var bool $hasMoreEntriesThanShownOnPage
 */

// "Show all" leads to the advanced search pre-filled with this member. On an
// island install point at the island action: the classic one renders in the SPA
// shell, which would drop the reader out of the island mid-journey.
$urlToHistory = [
    'controller' => 'searches',
    'action' => \Cake\Core\Configure::read('Saito.frontend') === 'island' ? 'htmxAdvanced' : 'advanced',
    '?' => ['name' => $user->get('username')],
];
?>
<div class="panel">
    <div class="panel-content">
        <?php
        if (empty($lastEntries)) {
            echo $this->element(
                'generic/no-content-yet',
                ['message' => __('No entries created yet.')]
            );
        } else {
            $threads = [];
            foreach ($lastEntries as $entry) {
                $threads[] = $this->Posting->renderThread($entry, ['ignore' => false]);
            }
            echo $this->Html->nestedList(
                $threads,
                ['class' => 'threadCollection-node root']
            );
        }
        ?>
    </div>
    <?php
    if ($hasMoreEntriesThanShownOnPage) {
        echo $this->Html->div(
            '',
            $this->Html->link(
                __('Show all'),
                $urlToHistory,
                ['class' => 'panel-footer-form-bnt']
            )
        );
    }
    ?>
</div>
