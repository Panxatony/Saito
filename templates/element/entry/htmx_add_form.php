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
    echo $this->element('entry/htmx_editor_preview');
    echo $this->Form->create(null, [
        'url' => ['action' => 'htmxAdd'],
        'type' => 'post',
        'hx-post' => $addUrl,
        'hx-target' => 'closest .js-addForm-wrap',
        'hx-swap' => 'innerHTML',
    ]);

    if (!empty($errors)) {
        // Bisher stand hier nur "Bitte überprüfe deine Eingabe" — die konkreten
        // Meldungen des Validators wurden verworfen. Wer einen zu langen Betreff
        // eintippte, erfuhr nicht, was zu lang war, und suchte den Fehler beim
        // Text. Jetzt steht dort, was der Validator tatsächlich bemängelt.
        echo '<div class="alert alert-error"><ul style="margin:0; padding-left:1.2em;">';
        foreach ($errors as $messages) {
            foreach ((array)$messages as $message) {
                echo '<li>' . h($message) . '</li>';
            }
        }
        echo '</ul></div>';
    }

    echo $this->Form->control('category_id', [
        'class' => 'form-control', 'type' => 'select', 'options' => $categories,
        'empty' => false, 'label' => __('Category'),
    ]);
    // maxlength aus der Admin-Einstellung "Betrefflänge": so laesst sich die
    // Grenze gar nicht erst ueberschreiten, statt sie erst beim Absenden zu
    // erfahren.
    $subjectMax = (int)(\Cake\Core\Configure::read('Saito.Settings.subject_maxlength') ?: 100);
    echo $this->Form->control('subject', [
        'class' => 'form-control js-subject', 'label' => __('subject'),
        'maxlength' => $subjectMax,
    ]);
    // The NSFW toggle lives in the toolbar; hand it what was submitted so a
    // form re-rendered with validation errors does not lose the tick.
    echo $this->element('entry/htmx_editor_toolbar', [
        'nsfwValue' => (bool)($this->getRequest()->getData('nsfw')),
    ]);
    // Der Platzhalter ist der einzige Hinweis darauf, dass das Feld leer bleiben
    // darf — ohne ihn ist "n/t" zwar moeglich, aber unauffindbar.
    echo $this->Form->control('text', [
        'class' => 'form-control', 'type' => 'textarea', 'rows' => 6, 'label' => __('text'),
        'placeholder' => __('entry.text.ph.nt'),
    ]);

    if ($inline) {
        echo $this->Form->hidden('inline', ['value' => 1]);
    }

    echo $this->Form->button(__('Submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo $this->Form->end();
    ?>
</div>
