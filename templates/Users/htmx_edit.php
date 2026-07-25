<?php
/**
 * User settings as an htmx island page (strangler-fig PoC).
 *
 * Reachable at /users/htmx-edit. A focused, island-styled version of the main
 * settings form (the most-edited fields); saved via the same allowed-field
 * patch as edit(). Native FormHelper form (CSRF token in the body). The
 * `form-control` / `form-check-input` classes make the shared theme style the
 * inputs (the Saito convention).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array $availableThemes
 */
?>
<div class="users edit">
    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('Settings')) ?>
        </div>
        <div class="card-body">
            <?php
            echo $this->Form->create($user, ['url' => ['action' => 'htmxEdit'], 'type' => 'post']);

            echo $this->Form->control('user_email', ['class' => 'form-control', 'label' => __('userlist_email')]);
            echo $this->Form->control('user_real_name', ['class' => 'form-control', 'label' => __('user_real_name')]);
            echo $this->Form->control('user_hp', ['class' => 'form-control', 'label' => __('user_hp')]);
            echo $this->Form->control('user_place', ['class' => 'form-control', 'label' => __('user_place')]);
            echo $this->Form->control('profile', [
                'class' => 'form-control', 'type' => 'textarea', 'rows' => 3, 'label' => __('user_profile'),
            ]);
            echo $this->Form->control('signature', [
                'class' => 'form-control', 'type' => 'textarea', 'rows' => 3, 'label' => __('user_signature'),
            ]);

            if (!empty($availableThemes)) {
                echo $this->Form->control('user_theme', [
                    'class' => 'form-control', 'type' => 'select', 'options' => $availableThemes, 'label' => __('user_theme'),
                ]);
            }

            // --- Preferences (parity with the classic settings page) -----------

            // Thread sort order (start time vs. last answer).
            echo $this->Form->control('user_sort_last_answer', [
                'type' => 'radio',
                'label' => __('user_sort_last_answer'),
                'options' => [
                    '0' => __('user_sort_last_answer_time'),
                    '1' => __('user_sort_last_answer_last_answer'),
                ],
            ]);

            // Auto-refresh interval (minutes; optional).
            echo $this->Form->control('user_forum_refresh_time', [
                'class' => 'form-control', 'type' => 'number', 'min' => 0,
                'label' => __('user_forum_refresh_time'),
            ]);

            // Custom thread-line colours (classic view).
            echo '<div class="input"><label>' . h(__('user_colors')) . '</label>';
            foreach ([
                'user_color_new_postings' => 'user_color_new_postings_exp',
                'user_color_old_postings' => 'user_color_old_postinings_exp',
                'user_color_actual_posting' => 'user_color_actual_posting_exp',
            ] as $colourField => $expKey) {
                echo '<div style="display:flex;align-items:center;gap:.5rem;margin:.2rem 0;">';
                echo $this->Form->control($colourField, [
                    'type' => 'color', 'label' => false,
                    'style' => 'width:3rem;height:2rem;padding:2px;',
                ]);
                echo '<span>' . h(__($expKey)) . '</span></div>';
            }
            echo '</div>';

            // Checkbox preferences. Clean Bootstrap-4 rows (`.form-check > input +
            // label`); escape=false so a label's literal `&nbsp;` is a space.
            $checkboxes = [
                'user_automaticaly_mark_as_read' => 'user_automaticaly_mark_as_read',
                'user_signatures_hide' => 'user_signatures_hide_exp',
                'user_signatures_images_hide' => 'user_signatures_images_hide_exp',
                'inline_view_on_click' => 'inline_view_on_click',
                'user_show_thread_collapsed' => 'user_show_thread_collapsed_exp',
                'personal_messages' => 'user_pers_msg',
                'user_category_override' => 'user_category_override_exp',
            ];
            foreach ($checkboxes as $field => $labelKey) {
                echo '<div class="form-check">';
                echo $this->Form->checkbox($field, ['class' => 'form-check-input', 'id' => $field]);
                echo $this->Form->label($field, __($labelKey), [
                    'class' => 'form-check-label', 'for' => $field, 'escape' => false,
                ]);
                echo '</div>';
            }

            echo $this->Form->button(__('Submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo $this->Form->end();
            ?>
            <p style="margin-top: 1rem;">
                <a href="<?= $this->request->getAttribute('webroot') ?>users/htmx-change-password">
                    <?= h(__('change_password_link')) ?>
                </a>
            </p>
        </div>
    </div>

    <?php // Display preference (stored per browser like the night/day theme). ?>
    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('Font size')) ?>
        </div>
        <div class="card-body">
            <div class="btn-group js-font-scale-group" role="group" aria-label="<?= h(__('Font size')) ?>">
                <?php foreach (['90' => 'A', '100' => 'A', '112' => 'A', '125' => 'A'] as $scale => $glyph) : ?>
                    <button type="button" class="btn btn-outline-secondary js-font-scale" data-scale="<?= $scale ?>"
                            style="font-size: <?= (int)$scale ?>%;"><?= $glyph ?></button>
                <?php endforeach; ?>
            </div>
            <p class="text-muted" style="margin-top: .5rem; font-size: .9rem;">
                <?= h(__('Applies to this browser.')) ?>
            </p>
        </div>
    </div>
</div>

<?php
