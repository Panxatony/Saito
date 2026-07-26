<?php
use Cake\Core\Configure;
use Stopwatch\Lib\Stopwatch;

Stopwatch::start('layout/disclaimer.ctp');
?>
<div class="disclaimer">
    <div class="container">
        <div class="row justify-content-between">
            <div class="disclaimer-card">
                <h3><?= h(__('saito.dscl.links')) ?></h3>
                <ul>
                    <li>
                        <?php // Island: contact-owner overlay; classic: the page. ?>
                        <?php if (\Cake\Core\Configure::read('Saito.frontend') === 'island') : ?>
                            <a href="#" class="js-contactModalOpen"
                               data-modal-url="<?= $this->request->getAttribute('webroot') ?>contacts/htmx-contact-owner">
                                <?= h(__('saito.dscl.contact')) ?></a>
                        <?php else : ?>
                            <a href="<?= $this->request->getAttribute('webroot') ?>contacts/owner">
                            <?= h(__('saito.dscl.contact')) ?></a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <?php // Island: overlay with the public feeds; classic: the info page. ?>
                        <?php if (\Cake\Core\Configure::read('Saito.frontend') === 'island') : ?>
                            <a href="#" class="js-rssOpen"><?= h(__('s.rss.t')) ?></a>
                        <?php else : ?>
                            <a href="<?= $this->request->getAttribute('webroot') ?>pages/rss_feeds">
                                <?= h(__('s.rss.t')) ?>
                            </a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <?php // Island: open the help overlay; classic: the static help page. ?>
                        <?php if (\Cake\Core\Configure::read('Saito.frontend') === 'island') : ?>
                            <a href="#" class="js-helpOpen"><?= h(__('Help')) ?></a>
                        <?php else : ?>
                            <a href="<?= $this->request->getAttribute('webroot') ?>help">
                                <?= h(__('Help')) ?>
                            </a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <a href="<?= $this->request->getAttribute('webroot') ?>pages/impressum">Impressum</a>
                    </li>
                    <li>
                        <a href="<?= $this->request->getAttribute('webroot') ?>pages/privacy">
                            <?= h(__('privacy.t')) ?>
                        </a>
                    </li>
                     
                </ul>
            </div>
            <div class="disclaimer-card">
                <h3><?= h(__('saito.dscl.status')) ?></h3>
                <?= $this->cell('AppStatus', ['CurrentUser' => $CurrentUser]) ?>
            </div>
            <div class="disclaimer-card">
                <h3><?= h(__('saito.dscl.about')) ?></h3>
                <p>
                    <a href="<?= Configure::read('Saito.saitoHomepage') ?>">
                        <?= h(__(
                            'saito.dscl.v',
                            ['version' => Configure::read('Saito.v')]
                        )) ?>
                    </a>
                    <br/>
                    <?= h(__(
                        'saito.dscl.time',
                        ['time' => Stopwatch::getWallTime()]
                    )) ?>
                </p>
            </div>
        </div>
    </div>
</div>
<?php Stopwatch::stop('layout/disclaimer.ctp'); ?>
