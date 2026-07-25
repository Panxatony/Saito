<?php
/**
 * New-thread form — shared by the standalone page (htmx_add.php) and the inline
 * editor on the front page. Submits via htmx (hx-post to htmxAdd); the native
 * action is the no-JS fallback. `$inline` adds a hidden flag so the controller
 * keeps the result on the page (refresh the list) instead of navigating.
 *
 * @var \App\View\AppView $this
 * @var array $categories
 * @var array $errors
 * @var bool $inline
 */

$inline = $inline ?? false;
$addUrl = $this->Url->build(['controller' => 'Entries', 'action' => 'htmxAdd'], ['escape' => false]);
?>
<div class="js-addForm-wrap">
    <?php
    echo $this->Form->create(null, [
        'url' => ['action' => 'htmxAdd'],
        'type' => 'post',
        'hx-post' => $addUrl,
        'hx-target' => 'closest .js-addForm-wrap',
        'hx-swap' => 'innerHTML',
    ]);

    if (!empty($errors)) {
        echo '<div class="alert alert-error">' . h(__('Please check your entry.')) . '</div>';
    }

    echo $this->Form->control('category_id', [
        'class' => 'form-control', 'type' => 'select', 'options' => $categories,
        'empty' => false, 'label' => __('Category'),
    ]);
    echo $this->Form->control('subject', ['class' => 'form-control', 'label' => __('subject')]);
    echo $this->element('entry/htmx_editor_toolbar');
    echo $this->Form->control('text', [
        'class' => 'form-control', 'type' => 'textarea', 'rows' => 6, 'label' => __('text'),
    ]);

    if ($inline) {
        echo $this->Form->hidden('inline', ['value' => 1]);
    }

    echo $this->Form->button(__('Submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo $this->Form->end();
    ?>
</div>
