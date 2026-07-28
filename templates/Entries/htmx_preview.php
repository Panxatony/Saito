<?php
/**
 * Preview fragment for the htmx editor (EntriesController::htmxPreview()).
 *
 * Rendered into the preview panel that sits *above* the editor, and shaped like
 * the real thing: `article.postingBody` with the same heading and info line a
 * posting gets, so what the writer checks is the posting itself one step early
 * rather than a differently-styled approximation of it.
 *
 * @var \App\View\AppView $this
 * @var string $previewText
 * @var string $previewSubject
 * @var \App\Model\Entity\User $previewAuthor
 * @var \Cake\Datasource\EntityInterface|null $previewCategory
 * @var int $previewViews
 */

// Nothing written yet — return an empty fragment; the island hides the panel
// rather than showing an empty frame.
if (trim($previewText) === '' && trim($previewSubject) === '') {
    return;
}

// Saito's own convention for a posting with a subject but no text: " n/t" is
// appended to the subject when it is rendered. Shown here too, or the preview
// would promise a shape the posting will not have.
$subject = trim($previewSubject);
if ($subject !== '' && trim($previewText) === '') {
    $subject .= ' n/t';
}
?>
<article class="postingBody htmx-editor-previewBox">
    <?php if ($subject !== '') : ?>
        <header>
            <h2 class="postingBody-heading"><?= h($subject) ?></h2>
        </header>
    <?php endif; ?>
    <aside class="postingBody-info">
        <?php if ($previewCategory !== null) : ?>
            <span class="c-category acs-<?= (int)$previewCategory->get('accession') ?>"
                  title="<?= h((string)$previewCategory->get('description')) ?>"><?=
                h((string)$previewCategory->get('category'))
            ?></span>
            –
        <?php endif; ?>
        <?php // Derselbe Helfer wie im echten Beitrag, damit Markup und Ziel
              // identisch sind — der Name führt aufs Profil. ?>
        <span class="c-username"><?= $this->User->linkToUserProfile($previewAuthor, $CurrentUser) ?></span>,
        <?php // Same wrapper and same order as a real posting: place, time,
              // views. The place is only shown when the member has set one,
              // exactly as the posting views do it. ?>
        <span class="meta">
            <?php if ($previewAuthor->get('user_place')) : ?>
                <?= h((string)$previewAuthor->get('user_place')) ?>,
            <?php endif; ?>
            <?= $this->TimeH->formatTime(new \Cake\I18n\DateTime()) ?>,
            <?= h(__('views_headline')) ?>: <?= (int)$previewViews ?>
        </span>
    </aside>
    <?php if (trim($previewText) !== '') : ?>
        <?= $this->Parser->parse($previewText) ?>
    <?php endif; ?>
</article>
