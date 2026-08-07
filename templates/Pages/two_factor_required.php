<?php
/**
 * Shown instead of the requested page when this forum requires a second factor
 * of moderators or administrators and this member has not set one up (#87).
 *
 * A page rather than a dismissible overlay, for the same reasons as the terms
 * gate it is modelled on: it cannot be clicked away, it works without
 * JavaScript, and the request pipeline already knows this shape.
 *
 * Two ways out, always. Setting the second factor up is the intended one; the
 * other is logging out, because somebody whose authenticator app is on a phone
 * in another room must not be trapped in a forum they cannot leave. Both stay
 * reachable through AppController::isExemptFromSecondFactorGate().
 *
 * The text names the console command deliberately. Whoever meets this page is
 * usually the person who switched the setting on, and the sentence they need is
 * the one that says a lockout has a way back — before they discover otherwise.
 *
 * @var \App\View\AppView $this
 */
$title = __('user.2fa.required.t');
$this->set('titleForPage', $title);
$webroot = $this->request->getAttribute('webroot');
?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content">
        <p class="lead"><?= h(__('user.2fa.required.lead')) ?></p>
        <p><?= h(__('user.2fa.required.exp')) ?></p>

        <p>
            <?= $this->Html->link(
                h(__('user.2fa.required.setup')),
                $webroot . 'users/htmx-two-factor',
                ['class' => 'btn btn-primary'],
            ) ?>
        </p>

        <p class="exp">
            <?= h(__('user.2fa.required.lockout')) ?>
        </p>

        <p class="exp">
            <?= $this->Html->link(h(__('user.2fa.required.logout')), $webroot . 'logout') ?>
        </p>
    </div>
</div>
