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
                        <a href="#" class="js-contactModalOpen"
                           data-modal-url="<?= $this->request->getAttribute('webroot') ?>contacts/htmx-contact-owner">
                            <?= h(__('saito.dscl.contact')) ?></a>
                    </li>
                    <li>
                        <a href="#" class="js-rssOpen"><?= h(__('s.rss.t')) ?></a>
                    </li>
                    <li>
                        <a href="#" class="js-helpOpen"><?= h(__('Help')) ?></a>
                    </li>
                    <li>
                        <a href="<?= $this->request->getAttribute('webroot') ?>pages/tos">
                            <?= h(__('register_tos_linktext')) ?></a>
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
