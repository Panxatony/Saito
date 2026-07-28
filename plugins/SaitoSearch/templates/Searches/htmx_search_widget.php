<?php
/**
 * Compact search widget loaded into the header-actions slot when "Search" is
 * clicked. Its own form htmx-gets the simple-search results fragment into the
 * widget's results slot (no `widget` param, so htmxSimple returns just results).
 *
 * @var \App\View\AppView $this
 */

$searchUrl = $this->Url->build(
    ['plugin' => 'SaitoSearch', 'controller' => 'Searches', 'action' => 'htmxSimple'],
    ['escape' => false]
);
?>
<div class="card" style="margin: 1rem 0;">
    <div class="card-body">
        <form style="display:flex; gap:.4rem; margin:0;"
              hx-get="<?= h($searchUrl) ?>"
              hx-target="#js-widgetResults"
              hx-swap="innerHTML">
            <input type="search" name="searchTerm" class="form-control" style="margin:0;" autofocus
                   placeholder="<?= h(__('Search')) ?>&hellip;">
            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i></button>
        </form>
        <?php // This box is where most people start searching, so it is the place
              // the advanced search has to be reachable from — it was only linked
              // from a profile or a posting list before. ?>
        <div class="search-switch">
            <?= $this->Html->link(
                __d('saito_search', 'search.toAdvanced'),
                ['plugin' => 'SaitoSearch', 'controller' => 'Searches', 'action' => 'htmxAdvanced']
            ) ?>
        </div>
        <div id="js-widgetResults" class="js-thread-island" style="margin-top: 1rem;"></div>
    </div>
</div>
