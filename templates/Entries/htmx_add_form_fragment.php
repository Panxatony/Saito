<?php
/**
 * htmx fragment: just the new-thread form (for inline loading on the front page
 * and for re-rendering with validation errors). See EntriesController::htmxAdd().
 *
 * @var \App\View\AppView $this
 * @var array $categories
 * @var array $errors
 * @var bool $inline
 */

echo $this->element('entry/htmx_add_form', [
    'categories' => $categories,
    'errors' => $errors ?? [],
    'inline' => $inline ?? false,
]);
