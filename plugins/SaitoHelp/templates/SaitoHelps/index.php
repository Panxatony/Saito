<?php
/**
 * @var \App\View\AppView $this
 * @var array<array{id: string, title: string}> $topics
 */
?>
<div class="card panel-center">
    <div class="card-body richtext">
        <h1><?= h(__('Help')) ?></h1>
        <?php if (empty($topics)) : ?>
            <p><?= h(__('Currently no help pages are available.')) ?></p>
        <?php else : ?>
            <ul class="saito-help-index">
                <?php foreach ($topics as $topic) : ?>
                    <li>
                        <?= $this->Html->link(
                            $topic['title'],
                            '/help/' . rawurlencode($topic['id'])
                        ) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
