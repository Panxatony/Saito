<?php
/**
 * The help page: every topic as a heading that opens its text underneath.
 *
 * One topic is open at a time — opening another closes the one before, and
 * clicking the open one closes it again. The text is fetched the first time a
 * topic is opened (`hx-trigger="click once"`) and stays in the page afterwards,
 * so opening it again costs no request.
 *
 * Without JavaScript every heading is still a plain link to the topic's own
 * page, which renders in full.
 *
 * @var \App\View\AppView $this
 * @var array<array{id: string, title: string, admin: bool}> $topics
 * @var string $lang
 */
?>
<?= $this->Html->css('SaitoHelp.saitohelp') ?>
<div class="card panel-center">
    <div class="card-body richtext">
        <h1><?= h(__('Help')) ?></h1>
        <?php if (empty($topics)) : ?>
            <p><?= h(__('Currently no help pages are available.')) ?></p>
        <?php else : ?>
            <div class="saito-help-index" x-data="{ open: null }">
                <?php foreach ($topics as $topic) : ?>
                    <?php $id = h($topic['id']); ?>
                    <section class="saito-help-topic">
                        <h2 class="saito-help-topic-head">
                            <a href="<?= $this->Url->build('/help/' . rawurlencode($topic['id'])) ?>"
                               hx-get="<?= $this->Url->build(
                                   '/help/' . rawurlencode($lang) . '/' . rawurlencode($topic['id'])
                               ) ?>"
                               hx-target="#saito-help-body-<?= $id ?>"
                               hx-swap="innerHTML"
                               hx-trigger="click once"
                               x-on:click.prevent="open = (open === '<?= $id ?>' ? null : '<?= $id ?>')"
                               x-bind:aria-expanded="open === '<?= $id ?>' ? 'true' : 'false'"
                               aria-controls="saito-help-body-<?= $id ?>">
                                <i class="fa fa-chevron-right saito-help-caret" aria-hidden="true"
                                   x-bind:class="open === '<?= $id ?>' && 'is-open'"></i>
                                <?= h($topic['title']) ?>
                                <?php if (!empty($topic['admin'])) : ?>
                                    <small>(<?= h(__('Admin')) ?>)</small>
                                <?php endif; ?>
                            </a>
                        </h2>
                        <div class="saito-help-topic-body"
                             id="saito-help-body-<?= $id ?>"
                             x-show="open === '<?= $id ?>'"
                             x-cloak></div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
