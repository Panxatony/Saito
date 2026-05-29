<?php
$this->start('headerSubnavLeft');
echo $this->Layout->navbarBack();
$this->end();

$title = 'Impressum';
$this->set('titleForPage', $title);
?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content richtext">
        <p>
            Lars Vonhof-Hunold IT-Consulting<br>
            (Einzelunternehmen)<br>
            Terrassenweg 6<br>
            34212 Melsungen
        </p>
        <p>
            Tel: +49 (0) 5661 91999210<br>
            Fax: +49 (0) 5661 91999219<br>
            Mobile: +49 (0) 160 1122006
        </p>
        <p>
            E-Mail: <a href="mailto:kontakt@vonhof-hunold.de">kontakt@vonhof-hunold.de</a>
        </p>
    </div>
</div>
