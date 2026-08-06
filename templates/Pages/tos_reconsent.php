<?php
/**
 * Shown instead of the requested page when the terms have changed and this
 * member has not agreed to the new version yet (issue #80, § 7 of the terms).
 *
 * A page rather than a dismissible overlay on purpose: it cannot be clicked
 * away, it works without JavaScript, and it is the same shape as the
 * "forum closed" interstitial the request pipeline already knows.
 *
 * Two ways out are always offered, because a member who does not want to agree
 * must not be trapped: accept, or log out. Reading the terms and taking one's
 * own data stay reachable too — see AppController::requireTermsAcceptance().
 *
 * @var \App\View\AppView $this
 */
$title = __('tos.reconsent.t');
$this->set('titleForPage', $title);
$webroot = $this->request->getAttribute('webroot');
?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content">
        <p class="lead"><?= h(__('tos.reconsent.lead')) ?></p>

        <div class="richtext tos-reconsent-terms">
            <?= $this->element('pages/tos_body') ?>
        </div>

        <?= $this->Form->create(null, [
            'url' => ['controller' => 'Users', 'action' => 'tosAccept'],
            'class' => 'tos-reconsent-form',
        ]) ?>
        <?= $this->Form->button(h(__('tos.reconsent.accept')), ['class' => 'btn btn-primary']) ?>
        <?= $this->Form->end() ?>

        <p class="tos-reconsent-decline">
            <?= $this->Html->link(h(__('tos.reconsent.decline')), $webroot . 'logout') ?>
        </p>
    </div>
</div>
