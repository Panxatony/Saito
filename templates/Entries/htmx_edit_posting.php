<?php
/**
 * Edit-posting standalone island page (/entries/htmx-edit/<id>).
 *
 * Pre-fills the current subject/text (and category, for a thread root) and
 * posts back to htmxEdit, which updates via PostingComponent and redirects to
 * the thread. Native form (FormHelper CSRF token in the body) so it also works
 * without JS; the editor toolbar enhances the textarea client-side. Errors
 * re-render this page.
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface $posting
 * @var bool $isRoot
 * @var array $categories
 * @var array $errors
 * @var bool $editingAsMod
 */

$id = (int)$posting->get('id');
$editUrl = $this->Url->build(['controller' => 'Entries', 'action' => 'htmxEdit', $id]);
$backUrl = $this->request->getAttribute('webroot') . 'entries/htmx-thread/' . (int)$posting->get('tid');
?>
<div class="entry edit">
    <p class="mix-back" style="margin: 0 0 .75rem;">
        <a href="<?= h($backUrl) ?>" class="btn btn-link" rel="nofollow">
            <?= $this->Layout->textWithIcon(h(__('Back')), 'arrow-left') ?>
        </a>
    </p>
    <div class="panel panel-form panel-center">
        <?= $this->Layout->panelHeading(__('edit_linkname')) ?>
        <div class="panel-content">
            <?php if (!empty($editingAsMod)) : ?>
                <div class="alert alert-warning"><?= h(__('notice_you_are_editing_as_mod')) ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)) : ?>
                <div class="alert alert-error"><?= h(__('Please check your entry.')) ?></div>
            <?php endif; ?>
            <?php
            echo $this->Form->create(null, ['url' => ['action' => 'htmxEdit', $id], 'type' => 'post']);

            if (!empty($isRoot)) {
                echo $this->Form->control('category_id', [
                    'class' => 'form-control', 'type' => 'select', 'options' => $categories,
                    'empty' => false, 'label' => __('Category'),
                    'value' => $posting->get('category_id'),
                ]);
            }
            echo $this->Form->control('subject', [
                'class' => 'form-control', 'label' => __('subject'),
                'value' => $posting->get('subject'),
            ]);
            echo $this->element('entry/htmx_editor_toolbar');
            echo $this->Form->control('text', [
                'class' => 'form-control', 'type' => 'textarea', 'rows' => 10, 'label' => __('text'),
                'value' => $posting->get('text'),
            ]);

            echo $this->Form->button(__('Submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo $this->Form->end();
            ?>
        </div>
    </div>
</div>
