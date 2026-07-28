<?php
/**
 * A single help topic, without the page around it.
 *
 * Swapped into the accordion panel on /help. The panel's own heading already
 * carries the title — extractTitle() reads it from the first Markdown heading —
 * so that heading is dropped here rather than printed twice.
 *
 * @var \App\View\AppView $this
 * @var \Cake\ORM\Entity $help
 * @var \Saito\User\CurrentUser\CurrentUserInterface $CurrentUser
 */

$text = (string)$help->get('text');
$text = (string)preg_replace('/\A\s*#{1,6}[^\n]*\n/', '', $text, 1);
?>
<div class="richtext saito-help-body">
    <?= $this->SaitoHelp->parse($text, $CurrentUser) ?>
</div>
