<?php
/**
 * Confirmation fragment after a successful inline reply
 * (EntriesController::htmxReply()). Swapped into the reply slot.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Entry $posting
 */
?>
<?php // data-refresh-tid: die Insel laedt danach den Thread neu, sonst bliebe
      // die eigene Antwort unsichtbar — man saehe nur die Bestaetigung und
      // hielte den Beitrag fuer verloren. ?>
<div class="alert alert-success js-replyDone" data-refresh-tid="<?= (int)$posting->get('tid') ?>"
     style="margin-top: 0.75em;">
    <i class="fa fa-check"></i>
    <?= h(__('Your reply has been saved.')) ?>
    <?php // Auf einer Insel-Installation darf dieser Link nicht in die SPA
          // fuehren — er ist der haeufigste Weg dorthin, direkt nach dem
          // Antworten. htmxThread nimmt auch eine Beitrags-ID und leitet auf
          // den Thread um. ?>
    <?php $threadUrl = '/entries/htmx-thread/' . $posting->get('id'); ?>
    <a href="<?= h($this->Url->build($threadUrl)) ?>">
        <?= h(__('forum_show_thread')) ?>
    </a>
</div>
