<?php
/**
 * Humorous, self-contained forum help — rendered inside the island help overlay
 * (#js-helpModal). Pure static content (no assets, no JS); the icons are the
 * theme's Font-Awesome set. German, matching the forum's audience.
 *
 * @var \App\View\AppView $this
 */
?>
<div class="island-help">
    <p class="island-help-lead">
        Willkommen! Dieses Forum ist wie eine Kneipe, nur mit weniger verschütteten
        Getränken und einem <em>Ungelesen-Strich</em>. Hier ein kurzer Rundgang –
        keine Sorge, es gibt keine Prüfung am Ende. Wahrscheinlich.
    </p>

    <div class="island-help-item">
        <i class="fa fa-list island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Die Threadliste</strong>
            Alles Wichtige steht vorne. Ein <span class="island-help-mark">oranger Strich</span>
            links heißt „hier ist was Neues“ – sobald du reinschaust, verduftet er
            beleidigt. Über <em>„Mehr laden“</em> holst du dir Nachschub aus dem Archiv.
            Wuchert ein Thread mit hundert Antworten? Der kleine Klapp-Pfeil links
            daneben faltet ihn zusammen, bis nur das Eröffnungsposting übrig ist –
            und in den Einstellungen lässt sich das gleich für alle Threads
            voreinstellen. Daneben sitzt die <em>Mix-Ansicht</em>: sie legt alle
            Beiträge des Threads am Stück untereinander. Hast du in den
            Einstellungen <em>„Posting aufklappen“</em> an, passiert das direkt an
            Ort und Stelle, ohne Seitenwechsel – noch ein Klick, und der Baum ist
            zurück.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-pencil island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Beiträge schreiben</strong>
            Klick auf <em>„Neuer Beitrag“</em> und der Editor klappt gleich an Ort und
            Stelle auf – kein Seitenwechsel, kein Ladebalken-Kino. Die Knöpfchen oben
            machen <strong>fett</strong>, <em>kursiv</em> und allerlei Formatierung,
            das Smiley-Gesicht öffnet die Sammlung zum Anklicken. Die Vorschau zeigt
            dir vorher, ob’s hübsch aussieht. Und wenn sich doch ein Tippfehler
            eingeschlichen hat: <em>„Bearbeiten“</em> am eigenen Beitrag richtet das –
            der Beitrag trägt danach einen dezenten Hinweis, dass er angefasst wurde.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-image island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Bilder &amp; Medien</strong>
            Über <em>„Medien einfügen“</em> wirfst du eine URL rein – das Forum erkennt
            von selbst, ob es ein Bild, ein Video oder ein YouTube-Clip ist. Noch fauler?
            Einfach einen Link ins Editor-Feld <em>einfügen</em> (Strg/Cmd&nbsp;+&nbsp;V),
            der Rest passiert automatisch. Der Upload-Manager zeigt dein Bilderarchiv –
            aussuchen, „Auswahl einfügen“, fertig. Aufräumen geht in deinem Profil:
            dort liegen alle Uploads, und was weg soll, darf weg.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-filter island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Kategorien filtern</strong>
            Nur an einem Thema interessiert? Über die Kategorie-Auswahl oben blendest du
            den Rest aus – digitaler Scheuklappen-Modus, ganz ohne schlechtes Gewissen.
            Dauerhaft geht es auch: In den Einstellungen hakst du unter
            <em>„Angezeigte Kategorien“</em> an, was dich überhaupt interessiert. Die
            Auswahl oben bietet dann nur noch genau das an.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-bookmark island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Lesezeichen &amp; „hilfreich“</strong>
            Ein Beitrag zu schade zum Vergessen? Setz ein <em>Lesezeichen</em> und finde
            ihn später über das Lesezeichen-Symbol im Kopf wieder. Und hat dir eine
            Antwort weitergeholfen, darfst du sie per Haken als <em>hilfreich</em>
            auszeichnen – ein kleines Dankeschön an den Verfasser.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-check island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Gelesen-Markierung</strong>
            Zu viele orange Striche? Der <em>„Alles gelesen“</em>-Knopf macht mit einem
            Klick reinen Tisch – der Reset-Knopf für dein schlechtes Gewissen.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-columns island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Die Widgets rechts</strong>
            Rechts (am Handy weiter unten) plaudern kleine Kästchen darüber, wer gerade
            online ist und was zuletzt geschrieben wurde. Das Wort <em>„Benutzer“</em>
            in der Überschrift führt zur vollständigen Mitgliederliste – die lädt
            in Hundertergruppen nach, das Forum hat mehr Leute, als auf eine Seite
            passen. Zu neugierig? Kopf antippen und das Widget klappt diskret
            zusammen (auf den Link natürlich nicht, der will woanders hin).
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-search island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Suche</strong>
            Die Lupe im Kopf durchwühlt das Forum nach Stichworten – schneller, als du
            „Wo war das nochmal?“ tippen kannst. In der erweiterten Suche schränkst du
            zusätzlich auf Betreff, Verfasser, Kategorie und einen Monat ein, ab dem
            gesucht wird. Findet sie mehr, als auf eine Seite passt, holst du dir den
            Rest unten über <em>„Mehr laden“</em> – so lange, bis nichts mehr kommt.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-adjust island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Tag &amp; Nacht</strong>
            Der Halbmond-/Kontrast-Knopf schaltet zwischen hell und dunkel um. Für die
            frühen Vögel und die späten Eulen gleichermaßen.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-user island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Profil &amp; Einstellungen</strong>
            Hinter <em>„Profil“</em> im Kopf wohnt alles Persönliche: dein
            <em>Avatar</em>, Signatur, Farben, Sortierung – und die
            <em>Schriftgröße</em>, falls die Buchstaben mal Fangen spielen. Bei den
            Farben gibt es neben dem Farbtopf jeweils ein <em>„Standard“</em>-Häkchen,
            falls dir dein eigener Geschmack doch zu bunt wurde. Das Passwort änderst
            du dort ebenfalls – es klappt als Overlay auf, ohne dass du die Seite
            verlässt. Auf der
            Profilseite selbst findest du außerdem deine Lesezeichen, deine Uploads
            und deine persönlichen RSS-Adressen, die auch die Bereiche mitliefern,
            die nur angemeldet sichtbar sind.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-envelope island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Miteinander reden – oder eben nicht</strong>
            Etwas, das nicht alle lesen müssen? Auf dem Profil eines Mitglieds
            steht <em>„Kontakt“</em> und öffnet ein Fenster, das eine E-Mail schickt,
            ohne dass Adressen herumgereicht werden. Ein Postfach im Forum gibt es
            nicht – was hier rausgeht, landet im Mailprogramm. Und wenn jemand dauerhaft an deinen Nerven sägt:
            <em>„Ignorieren“</em> auf demselben Profil, dann ist Ruhe. Den Betreiber
            erreichst du über <em>„Kontakt“</em> unten im Fuß der Seite.
        </div>
    </div>

    <div class="island-help-item">
        <i class="fa fa-wrench island-help-icon" aria-hidden="true"></i>
        <div>
            <strong>Das Werkzeug-Menü</strong>
            Wenn du Moderationsrechte hast, sitzt an Beiträgen ein Schraubenschlüssel.
            Dahinter liegt das schwere Gerät: <em>anpinnen</em> (Thread bleibt oben),
            <em>sperren</em> (keine neuen Antworten), <em>zusammenführen</em> (hängt
            einen ganzen Thread als Antwort unter einen anderen – praktisch bei
            Doppelposts) und <em>löschen</em>. Mit Bedacht, das meiste davon merkt man.
        </div>
    </div>

    <p class="island-help-outro">
        Das war’s im Groben. Jetzt aber los – die Threads schreiben sich nicht von
        selbst. <i class="fa fa-coffee" aria-hidden="true"></i>
    </p>
</div>
