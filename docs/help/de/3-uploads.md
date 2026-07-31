## Bilder und Dateien

Angemeldete Mitglieder können eigene Dateien hochladen und sie in Beiträge
einfügen. Was erlaubt ist, legt der Betreiber fest — üblicherweise Bilder,
Videos, Audiodateien und einfache Textdateien. SVG-Dateien sind bewusst
ausgeschlossen, weil sie Skripte enthalten können.

### Hochladen

Im Editor öffnet der Knopf mit dem Bild-Symbol einen Bereich, der beides kann:
neue Dateien aufnehmen und das eigene Archiv zeigen. Dateien lassen sich
auswählen oder in die markierte Fläche ziehen. Mehrere auf einmal sind möglich;
zu jeder Datei erscheint anschließend, ob sie angenommen wurde.

Große Bilder werden beim Hochladen verkleinert, damit Beiträge auch über eine
langsame Verbindung schnell laden. Sehr hoch auflösende Bilder lehnt das Forum
ab — nicht aus Strenge, sondern weil das Entpacken solcher Dateien den Server
lahmlegen kann.

### Einfügen

Im Archiv genügt ein Klick auf eine Kachel, um sie auszuwählen; mehrere
gleichzeitig sind möglich. *Auswahl einfügen* setzt sie an die Cursorposition im
Beitrag. Je nach Art der Datei entsteht dabei die passende Auszeichnung, ein
Bild also als Bild und ein Video als abspielbares Video.

### Heikles verdecken

Neben *Auswahl einfügen* steht ein Häkchen **Als NSFW einfügen**. Ist es gesetzt,
erscheint das Eingefügte im Beitrag zunächst verdeckt: unscharf, mit einem
Hinweis darüber. Erst ein Klick zeigt es, ein weiterer verdeckt es wieder. Bei
Videos bleibt auch der Abspielknopf gesperrt, solange die Decke liegt.

Das Häkchen gilt für **diese Einfügung**, nicht für die Datei. Dasselbe Bild kann
also in einem Beitrag verdeckt und in einem anderen offen stehen. Umgekehrt
heißt das: eine Datei nachträglich zu kennzeichnen ändert nichts an Beiträgen,
die schon geschrieben sind — dort muss die Auszeichnung im Text ergänzt werden.

Wer den Beitragstext direkt bearbeitet, schreibt es selbst:

```
[img src=upload nsfw=1]dateiname.jpg[/img]
```

Das funktioniert genauso für `[video]`, `[audio]` und `[file]`.

Ein Wort zur Ehrlichkeit: die Decke hält das Bild vom Bildschirm fern, nicht vom
Betrachter. Die Datei selbst liegt unverändert auf dem Server und ist über ihre
Adresse jederzeit erreichbar. Sie schützt davor, dass etwas unverhofft im
Großraumbüro auftaucht — sie ist kein Zugriffsschutz.

### Als NBS kennzeichnen

Beim Eröffnen eines neuen Themas gibt es ein Häkchen **Nicht bürosicher (NBS)**.
Ist es gesetzt, trägt der Beitrag ein rotes Abzeichen — in der Themenliste und
auf der Beitragsseite — und **alle Bilder, Videos und Dateien darin erscheinen
verdeckt**, ohne dass an den einzelnen Einfügungen etwas geändert werden muss.

Das ist der bequemere Weg als das Häkchen im Upload-Dialog: einmal am Beitrag
statt einmal pro Bild. Beides greift, und beides lässt sich mit einem Klick
aufdecken.

Eine **Antwort erbt die Kennzeichnung nicht**. Sie ist ein eigener Beitrag und
wird für sich beurteilt.

Das Abzeichen gab es in Saito schon einmal und ging bei einem Umbau verloren —
die alten Kennzeichnungen lagen die ganze Zeit in der Datenbank und sind damit
wieder sichtbar.

### Verwalten

Im eigenen Profil liegt das vollständige Archiv. Dort lassen sich Dateien
auswählen und gemeinsam löschen. Vor dem Löschen wird einmal für die ganze
Auswahl nachgefragt, denn rückgängig machen lässt es sich nicht.

Eine gelöschte Datei verschwindet auch aus Beiträgen, die sie eingebunden haben
— dort bleibt dann eine Fundstelle ohne Inhalt zurück.
