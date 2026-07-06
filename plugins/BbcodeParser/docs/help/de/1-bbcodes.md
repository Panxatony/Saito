# Saito-BBCode #

Die Verfügbarkeit einiger BBCode-Tags hängt von der Forenkonfiguration (z. B. Multimedia-Einstellungen) oder vom Ort (neuer Beitrag, Signatur, …) ab.

## Fett ##

	[b]Text[/b]

Gibt fetten (wichtigen) Text aus.

## Kursiv ##

	[i]Text[/i]

Gibt kursiven (betonten) Text aus.

## Durchgestrichen ##

	[s]Text[/s]

oder

	[strike]Text[/strike]

Gibt durchgestrichenen Text aus.

## Liste ##

	[list]
	[*] Punkt 1
	[*] Punkt 2
	[/list]

## Horizontale Linie ##

`[hr]` oder `[---]` erzeugt eine horizontale Trennlinie.

## Bearbeitungsmarkierung ##

`[e]` erzeugt eine Bearbeitungsmarkierung.

## Links ##

### Automatischer Link ###

Einfache URLs (`http://example.com/foo`), die als Text vorkommen, werden automatisch in einen anklickbaren Link umgewandelt.

### Explizite Links ###

	[url]http://example.com/[/url]

### Links mit Linktext ###

	[url=http://example.com/  <label=none>]Link[/url]

Normalerweise wird die Top-Level-Domain an einen `[url]`-Link angehängt. Das lässt sich über den Parameter `label` steuern.


### E-Mail-Link ###

	[email]mail@tosomeone.com[/email]

oder

	[email=mail@tosomeone.com]Mail[/email]


### Interne Kurzlinks ###

	#123

ist ein Link zum Beitrag mit der ID `123`.

	@Alex

ist ein Link zu Alex' Profilseite.


## Spoiler ##

	[spoiler]Inhalt[/spoiler]

Der Inhalt wird erst angezeigt, wenn auf einen verdeckenden Spoiler-Text geklickt wird.

## Code ##


	[code=<Sprache>]<Quelltext>[/code]

Wörtlich wiedergegebener Quelltext.

Ist `<Sprache>` nicht `text` (Standard), wird der `<Quelltext>` in der jeweiligen Sprache hervorgehoben, z. B. `[code PHP]…`. Die verfügbaren Sprachen findest du in der [GeSHI-Dokumentation](http://qbnz.com/highlighter/).

## Zitat ##

	> Habt ihr noch Milch?

Ein besonderes Zitatzeichen (abhängig von den Foren-Einstellungen) am Zeilenanfang, gefolgt von einem Leerzeichen, markiert den folgenden Text in dieser Zeile als Zitat aus dem Elternbeitrag.

## Multimedia ##

Einige Multimedia-Tags sind aufwendig zu erstellen. Es wird empfohlen, Multimedia-Inhalte über die Medien-Schaltflächen im Beitragsformular einzufügen, das die Tags automatisch ergänzt.

### Bild ###

	[img]http://example.com/image.png[/img]


### HTML5-Audio ###

	[audio]http://example.com/audio.ogg[/audio]

Wähle ein [passendes Dateiformat][Audio] für dein Publikum.

[Audio]: http://de.wikipedia.org/wiki/HTML5_Audio


### HTML5-Video ###

	[video]http://example.com/audio.webm[/video]

Wähle ein [passendes Dateiformat][Video] für dein Publikum.

[Video]: http://de.wikipedia.org/wiki/HTML5-Video


### Uploads ###

Der BBCode-Tag für Uploads wird automatisch erzeugt, wenn eine Datei über den Uploader eingefügt wird.

### Weitere externe Inhalte ###

    [embed]http://example.com/content.something[/embed]

Versucht, den Inhalt, einen kurzen Auszug davon oder einen passenden Link einzubetten.

## Layout ##

### Umfließen ###

	[float]Inhalt[/float]

Lässt den Inhalt seitlich umfließen.
