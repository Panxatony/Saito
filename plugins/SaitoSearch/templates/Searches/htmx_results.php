<?php
/**
 * htmx fragment response for {@see \SaitoSearch\Controller\SearchesController::htmxSimple()}.
 *
 * Rendered without a layout (see the controller); htmx swaps just the results
 * list into the `.js-thread-island` container. Renders exactly what a
 * "load more" click needs: the page's lines plus the button for the next page.
 *
 * @var \App\View\AppView $this
 */

echo $this->element('SaitoSearch.search_result_lines');
echo $this->element('SaitoSearch.htmx_search_more');
