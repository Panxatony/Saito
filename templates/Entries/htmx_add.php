<?php
/**
 * New-thread form as an htmx island page (strangler-fig PoC).
 *
 * Reachable at /entries/htmx-add. A native FormHelper form (so it carries a CSRF
 * token and works without JS); on success the controller redirects to the new
 * thread. Minimal editor — plain text, no BBCode toolbar / upload / preview.
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
            <?php
            echo $this->Form->create(null, ['url' => ['action' => 'htmxAdd'], 'type' => 'post']);

            if (!empty($errors)) {
                echo '<div class="alert alert-error">' . h(__('Please check your entry.')) . '</div>';
            }

            echo $this->Form->control('category_id', [
                'type' => 'select',
                'options' => $categories,
                'empty' => false,
                'label' => __('category'),
            ]);
            echo $this->Form->control('subject', ['label' => __('subject')]);
            echo $this->Form->control('text', [
                'type' => 'textarea',
                'rows' => 8,
                'label' => __('text'),
            ]);
            echo $this->Form->button(__('Submit'), [
                'type' => 'submit',
                'class' => 'btn btn-primary',
            ]);
            echo $this->Form->end();
            ?>
        </div>
    </div>
</div>

<?php
echo $this->Html->script('htmx-threads.bundle.js');
