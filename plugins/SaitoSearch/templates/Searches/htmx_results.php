<?php
/**
 * htmx fragment response for {@see \SaitoSearch\Controller\SearchesController::htmxSimple()}.
 *
 * Rendered without a layout (see the controller); htmx swaps just the results
 * list into the `.js-thread-island` container. Reuses the same results element
 * as the SPA search page, so the thread-list island enhances its lines.
 *
 * @var \App\View\AppView $this
 */

echo $this->element('SaitoSearch.search_results');
