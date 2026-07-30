<?php
/**
 * The note on one bookmark: either as displayed, or as an edit form.
 *
 * Both states swap themselves out via htmx into the same slot, so the rest of
 * the bookmarks card is untouched. Rendered inline by the bookmarks card for the
 * display state and returned by UsersController::htmxBookmarkComment() for both.
 *
 * The CSRF token rides in the request header (see runtime.ts), which is why
 * there is no hidden field here — same as every other island write form.
 *
 * @var \App\View\AppView $this
 * @var int $entryId
 * @var string $comment
 * @var bool $editing
 */

$url = $this->request->getAttribute('webroot') . 'users/htmx-bookmark-comment/' . (int)$entryId;
?>
<?php if (!empty($editing)) : ?>
    <form class="bookmarkComment bookmarkComment-form"
          hx-post="<?= h($url) ?>" hx-target="this" hx-swap="outerHTML">
        <input type="text" name="comment" class="form-control" maxlength="255"
               value="<?= h($comment) ?>" autofocus
               placeholder="<?= h(__('bkm.comment.exp')) ?>"
               aria-label="<?= h(__('bkm.comment.t')) ?>">
        <button type="submit" class="btn btn-sm btn-primary"><?= h(__('Save')) ?></button>
        <?php // Cancel posts the unchanged note back rather than hiding the form
              // locally, so what is shown afterwards is what is actually stored. ?>
        <button type="button" class="btn btn-sm btn-link"
                hx-post="<?= h($url) ?>" hx-target="closest form" hx-swap="outerHTML"
                hx-vals="<?= h((string)json_encode(['comment' => $comment])) ?>">
            <?= h(__('Cancel')) ?>
        </button>
    </form>
<?php else : ?>
    <div class="bookmarkComment infoText">
        <?php if ($comment !== '') : ?>
            <i class="fa fa-bookmark"></i> <?= h($comment) ?>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-link"
                hx-get="<?= h($url) ?>" hx-target="closest .bookmarkComment" hx-swap="outerHTML">
            <?= h($comment === '' ? __('bkm.comment.add') : __('Edit')) ?>
        </button>
    </div>
<?php endif; ?>
