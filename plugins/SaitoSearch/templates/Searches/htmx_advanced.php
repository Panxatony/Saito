<?php
/**
 * Advanced search as an htmx island shell (strangler-fig PoC).
 *
 * The form submits via htmx (hx-get) and swaps the results fragment into
 * #js-searchResults; without JS the same form does a normal GET returning the
 * full shell with results. The shared thread-list island enhances the result
 * lines. Standalone (no SPA) in the htmx_island layout.
 *
 * @var \App\View\AppView $this
 * @var array $categories
 * @var int $month
 * @var int $year
 * @var int $startYear
 * @var mixed $results
 */

$searchUrl = $this->Url->build([
    'plugin' => 'SaitoSearch',
    'controller' => 'Searches',
    'action' => 'htmxAdvanced',
]);
$csrfToken = $this->getRequest()->getAttribute('csrfToken');
echo $this->Html->css('SaitoSearch.saitosearch', ['block' => true]);
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="container search advanced">
    <div class="searchForm card panel-form panel-center">
        <div class="card-body">
            <?php
            echo $this->Form->create(null, [
                'valueSources' => 'query',
                'type' => 'GET',
                'url' => $searchUrl,
                'hx-get' => $searchUrl,
                'hx-target' => '#js-searchResults',
                'hx-swap' => 'innerHTML',
                'hx-push-url' => 'true',
                'hx-indicator' => '#js-searchSpinner',
            ]);

            echo $this->Form->control('subject', ['class' => 'form-control', 'label' => __d('saito_search', 'subject')]);
            echo $this->Form->control('text', ['class' => 'form-control', 'label' => __d('saito_search', 'text')]);
            echo $this->Form->control('name', ['class' => 'form-control', 'label' => __d('saito_search', 'name')]);

            echo $this->Form->label(__d('saito_search', 'lbl.categories'));
            echo $this->Form->select('category_id', $categories, [
                'empty' => __('all'),
                'class' => 'form-control mb-3',
            ]);

            echo $this->Form->label(__d('saito_search', 'since.l'));
            echo $this->Form->month('month', ['class' => 'form-control mb-3', 'value' => $month]);
            echo $this->Form->year(
                'year',
                ['class' => 'form-control mb-3', 'minYear' => $startYear, 'maxYear' => date('Y'), 'value' => $year]
            );

            echo $this->Form->button(__d('saito_search', 'submit.l'), [
                'type' => 'submit',
                'class' => 'btn btn-primary',
            ]);
            echo $this->Form->end();
            ?>
        </div>
    </div>

    <span id="js-searchSpinner" class="htmx-indicator">
        <i class="fa fa-spinner fa-spin"></i> <?= __('Loading') ?>&hellip;
    </span>

    <div id="js-searchResults" class="js-thread-island">
        <?php
        if (!empty($results)) {
            echo $this->element('SaitoSearch.search_results');
        }
        ?>
    </div>
</div>

<?php
echo $this->Html->script('htmx-threads.bundle.js');
