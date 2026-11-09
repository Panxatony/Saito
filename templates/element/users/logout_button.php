<?php

declare(strict_types=1);

/**
 * Logout as a POST form. Logout used to be a GET link, so an `[img]/logout[/img]`
 * in any posting logged its readers out. A button styled like the link it
 * replaces; CSRF comes from the token field, FormProtection is unlocked for the
 * action (there are no fields to tamper with).
 *
 * @var \App\View\AppView $this
 * @var string $label visible text or markup (already escaped by the caller)
 * @var string|null $redirect local path to land on afterwards
 * @var string|null $title tooltip
 * @var string|null $class button classes
 */

$webroot = $this->request->getAttribute('webroot');
$action = $webroot . 'logout';
if (!empty($redirect)) {
    $action .= '?redirect=' . urlencode($redirect);
}
?>
<form method="post" action="<?= h($action) ?>" class="d-inline">
    <input type="hidden" name="_csrfToken" value="<?= h((string)$this->request->getAttribute('csrfToken')) ?>">
    <button type="submit" class="<?= h($class ?? 'btn btn-link') ?>"<?= !empty($title) ? ' title="' . h($title) . '"' : '' ?>><?= $label ?></button>
</form>
