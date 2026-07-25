<?php
/**
 * New-thread standalone page (/entries/htmx-add). Uses the shared form element;
 * on success the controller navigates to the new thread.
 *
 * @var \App\View\AppView $this
 * @var array $categories
 * @var array $errors
 */
?>
<div class="entry add">
    <div class="panel panel-form panel-center">
        <?= $this->Layout->panelHeading(__('Write a New Posting')) ?>
        <div class="panel-content">
            <?= $this->element('entry/htmx_add_form', ['categories' => $categories, 'errors' => $errors ?? [], 'inline' => false]) ?>
        </div>
    </div>
</div>

<?php
echo $this->Html->script('htmx-threads.bundle.js');
