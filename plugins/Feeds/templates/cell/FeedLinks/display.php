<?php
/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 *
 * @var \App\View\AppView $this
 * @var array<array{label: string, url: string}> $feeds
 * @var bool $personalized
 */
?>
<ul class="feed-links">
    <?php foreach ($feeds as $feed) : ?>
        <li class="feed-links-item">
            <div class="feed-links-title">
                <?= $this->Html->link($feed['label'], $feed['url']) ?>
            </div>
            <div class="feed-links-actions input-group">
                <input
                    type="text"
                    class="form-control js-feed-url"
                    value="<?= h($feed['url']) ?>"
                    readonly
                    aria-label="<?= h($feed['label']) ?>">
                <button
                    type="button"
                    class="btn btn-secondary js-feed-copy"
                    data-copied-label="<?= h(__d('feeds', 'feeds.copied.btn')) ?>">
                    <?= h(__d('feeds', 'feeds.copy.btn')) ?>
                </button>
                <!-- The `feed:` scheme hands the URL to the OS-registered RSS
                     reader so it can subscribe in one click. -->
                <a
                    class="btn btn-secondary"
                    href="feed:<?= h($feed['url']) ?>"
                    title="<?= h(__d('feeds', 'feeds.subscribe.title')) ?>">
                    <?= h(__d('feeds', 'feeds.subscribe.btn')) ?>
                </a>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<p class="feed-links-hint exp">
    <?= h($personalized ? __d('feeds', 'feeds.personalized.hint') : __d('feeds', 'feeds.public.hint')) ?>
</p>
<?php
// The copy button and the select-on-click used to live here, as an inline
// script block and an onclick attribute. Both moved into the island bundle
// (features/feedLinks.ts): this cell only renders on island pages, so that
// bundle is already loaded, and inline script is what the content-security
// policy stops allowing once 'unsafe-inline' goes.
?>
