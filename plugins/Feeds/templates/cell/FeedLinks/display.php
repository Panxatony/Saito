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
                    onclick="this.select()"
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
// Copy-to-clipboard for the feed URL fields. Delegated + guarded so it binds
// once even if the cell renders more than once on a page. The readonly input's
// onclick=select() is the no-JS fallback (select then Ctrl+C).
echo $this->Html->scriptBlock(
    <<<'JS'
    (function () {
        if (window.__saitoFeedCopyInit) { return; }
        window.__saitoFeedCopyInit = true;
        document.addEventListener('click', function (event) {
            var btn = event.target.closest('.js-feed-copy');
            if (!btn) { return; }
            var group = btn.closest('.feed-links-actions');
            var field = group && group.querySelector('.js-feed-url');
            if (!field) { return; }
            field.select();
            var done = function () {
                var original = btn.textContent;
                btn.textContent = btn.getAttribute('data-copied-label') || original;
                window.setTimeout(function () { btn.textContent = original; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, function () {
                    try { document.execCommand('copy'); done(); } catch (e) {}
                });
            } else {
                try { document.execCommand('copy'); done(); } catch (e) {}
            }
        });
    })();
    JS
);
?>
