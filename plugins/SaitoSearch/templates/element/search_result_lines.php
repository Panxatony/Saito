<?php
/**
 * The bare result lines, without the panel wrapper.
 *
 * Split out of {@see element/search_results} so the island can append page after
 * page into one continuous list: every htmx fragment renders just these lines
 * plus a fresh "load more" button.
 *
 * @var \App\View\AppView $this
 * @var mixed $results
 */

if (empty($results) || ($results->count() === 0)) {
    echo $this->element('generic/no-content-yet', ['message' => __d('saito_search', 'nothingFound.l')]);

    return;
}

foreach ($results as $result) {
    echo $this->Posting->renderThread($result->toPosting()->withCurrentUser($CurrentUser), ['rootWrap' => true]);
}
