<?php
/**
 * Island-styled login page (strangler-fig PoC).
 *
 * Reachable at /users/htmx-login. A clean standalone form that posts to the real
 * /login action (its authentication logic is untouched — login() unloads
 * FormProtection, so only the CSRF token FormHelper adds is needed).
 *
 * @var \App\View\AppView $this
 */

$webroot = $this->request->getAttribute('webroot');
?>
<div class="users login">
    <div class="card panel-form panel-center" style="max-width: 24rem; margin: 2rem auto;">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('login_btn')) ?>
        </div>
        <div class="card-body">
            <?php
            // The shared element, not a second hand-rolled form. It carries the
            // autocomplete hints password managers rely on, `required`, and the
            // tab order — all of which the island copy had quietly dropped.
            echo $this->element('users/login_form');
            ?>
            <p style="margin-top: 1rem; font-size: .9rem;">
                <a href="<?= $webroot ?>users/htmx-register"><?= h(__('register_linkname')) ?></a>
            </p>
            <p style="font-size: .9rem;">
                <a href="<?= $webroot ?>users/htmx-forgot-password"><?= h(__('user.pwreset.request.link')) ?></a>
            </p>
        </div>
    </div>
</div>

<?php
