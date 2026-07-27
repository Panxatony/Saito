<?php
/**
 * "Back to the forum" link for standalone island pages.
 *
 * Some island pages had one and some did not, which made the frontend feel
 * inconsistent — the header logo was the only way back from, say, the member
 * list. This element is that link in one place.
 *
 * @var \App\View\AppView $this
 * @var string|null $url    where "back" leads; the forum index by default
 * @var string|null $label  link text; "Zurück zum Forum" by default
 */

$url = $url ?? ($this->request->getAttribute('webroot') . 'entries/htmx-index');
$label = $label ?? __('back_to_forum_linkname');
?>
<p class="island-back">
    <a href="<?= h($url) ?>" class="btn btn-link" rel="nofollow">
        <?= $this->Layout->textWithIcon(h($label), 'arrow-left') ?>
    </a>
</p>
