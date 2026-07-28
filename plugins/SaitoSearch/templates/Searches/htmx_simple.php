<?php
/**
 * htmx/Alpine island shell for the simple fulltext search (strangler-fig PoC).
 *
 * The search form submits via htmx (hx-get) and swaps only the results fragment
 * into `#js-searchResults`; without JS the same form does a normal GET that
 * returns the full shell with results rendered server-side (progressive
 * enhancement). The reusable thread-list island enhances the result lines
 * (inline posting). Served standalone (no SPA) in the htmx_island layout.
 *
 * @var \App\View\AppView $this
 * @var array $searchDefaults
 */

$searchUrl = $this->Url->build([
    'plugin' => 'SaitoSearch',
    'controller' => 'Searches',
    'action' => 'htmxSimple',
]);

// Search-form styling, into the layout's <head> css block.
echo $this->Html->css('SaitoSearch.saitosearch', ['block' => true]);
?>

<div class="container search simple">
    <?= $this->element('layout/htmx_back') ?>
    <div class="searchForm card panel-form panel-center">
        <div class="card-body">
            <?php
            echo $this->Form->create(
                ['schema' => [], 'defaults' => $searchDefaults],
                [
                    'type' => 'GET',
                    'url' => $searchUrl,
                    'id' => 'search_form',
                    'class' => 'search_form',
                    // htmx enhancement; the native GET above is the no-JS fallback.
                    'hx-get' => $searchUrl,
                    'hx-target' => '#js-searchResults',
                    'hx-swap' => 'innerHTML',
                    'hx-push-url' => 'true',
                    'hx-indicator' => '#js-searchSpinner',
                ]
            );

            echo $this->Html->div(
                'form-group search_main',
                $this->Form->control('searchTerm', [
                    'id' => 'search_fulltext_textfield',
                    'class' => 'form-control search_textfield',
                    'label' => false,
                    'placeholder' => __d('saito_search', 'term.l'),
                ])
                . $this->Form->submit(__d('saito_search', 'submit.l'), [
                    'class' => 'btn btn-primary',
                ])
            );

            $sortBy = $this->Form->radio(
                'order',
                [
                    ['text' => __d('saito_search', 'Time'), 'value' => 'time'],
                    ['text' => __d('saito_search', 'Rank'), 'value' => 'rank'],
                ],
                ['class' => 'form-check-input', 'hiddenField' => false]
            );
            echo $this->Html->div(
                'form-group form-check form-check-inline',
                __d('saito_search', 'Sort by: {0}', $sortBy)
            );

            echo $this->Form->end();

            // The two searches had no way between them: the advanced one was
            // only reachable from a profile or a posting list, so anyone who
            // opened the search from the header never learnt it existed.
            echo $this->Html->div(
                'search-switch',
                $this->Html->link(
                    __d('saito_search', 'search.toAdvanced'),
                    ['plugin' => 'SaitoSearch', 'controller' => 'Searches', 'action' => 'htmxAdvanced']
                )
            );
            ?>
        </div>
    </div>

    <span id="js-searchSpinner" class="htmx-indicator">
        <i class="fa fa-spinner fa-spin"></i> <?= __('Loading') ?>&hellip;
    </span>

    <?php // The panel sits on the container, not in the fragment, so pages
          // appended by "load more" read as one continuous list. ?>
    <div id="js-searchResults" class="js-thread-island search_results panel">
        <?php
        // Initial render for a bookmarked URL / no-JS submit with a term.
        if (!empty($searchDefaults['searchTerm'])) {
            echo $this->element('SaitoSearch.search_result_lines');
            echo $this->element('SaitoSearch.htmx_search_more', compact('results'));
        }
        ?>
    </div>
</div>

<?php
// Reusable htmx + Alpine thread-list island (ENTRY=htmx-threads).
echo $this->Html->script('htmx-threads.bundle.js');
