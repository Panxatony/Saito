<?php
$this->start('headerSubnavLeft');
echo $this->Layout->navbarBack();
$this->end();

$title = __('s.rss.t');
$this->set('titleForPage', $title);
?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title) ?>
    </div>
    <div class="card-body panel-content richtext">
        <?= $this->cell('Feeds.FeedLinks', [$CurrentUser]) ?>
    </div>
</div>
