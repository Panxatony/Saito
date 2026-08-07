## Anmelden und Passwort

Lesen kann im Forum meist jeder; schreiben, hochladen und die eigenen
Einstellungen ändern nur, wer angemeldet ist. Konten legt in der Regel der
Betreiber an oder eine Registrierung, wenn sie freigeschaltet ist.

### Anmelden

Oben rechts führt *Anmelden* zu Benutzername und Passwort. Ein Haken hält die
Anmeldung über den Besuch hinaus, sodass der nächste Aufruf nicht wieder danach
fragt — an einem fremden oder geteilten Gerät besser nicht setzen.

### Passwort vergessen?

Wer sein Passwort nicht mehr weiß, muss nicht mehr den Betreiber bitten. Auf der
Anmeldeseite führt **Passwort vergessen?** zu einem Formular, das nach der
hinterlegten E-Mail-Adresse fragt. Ist sie im Forum bekannt, kommt eine
Nachricht mit einem einmaligen Link, über den sich ein neues Passwort setzen
lässt; danach geht es direkt zur Anmeldung.

Ein paar Dinge dazu:

- Der Link gilt **60 Minuten** und lässt sich nur **einmal** benutzen.
- Wird erneut angefordert, wird der vorherige Link ungültig — es zählt immer nur
  der letzte.
- Das Formular antwortet **gleich, ob die Adresse bekannt ist oder nicht**. Das
  ist Absicht: So lässt sich darüber nicht herausfinden, wer im Forum ein Konto
  hat. Kommt keine Mail an, lohnt der Blick in den Spam-Ordner und die Kontrolle,
  ob es wirklich die im Forum hinterlegte Adresse ist.

### Passwort ändern

Wer sein Passwort kennt und es wechseln will, tut das in den eigenen
Einstellungen unter *Passwort ändern*. Zur Sicherheit wird dabei einmal das
bisherige Passwort abgefragt.

### Andere Geräte werden abgemeldet

Wird das Passwort geändert oder zurückgesetzt, enden **alle anderen Anmeldungen
des Kontos**. Auf dem gerade genutzten Gerät bleibt man angemeldet; jede andere
noch offene Sitzung — das alte Handy, der Rechner in der Bücherei — wird beim
nächsten Aufruf abgemeldet. So beendet ein zurückgesetztes Passwort auch genau
den Zugriff, vor dem man sich schützen wollte.

### 2 Faktor Authentifizierung

Du kannst dein Konto zusätzlich zum Passwort mit einem Code aus einer
Authenticator-App absichern (Aegis, 1Password, Google Authenticator und andere).
Das ist freiwillig und standardmäßig aus.

**Einrichten** in den eigenen Einstellungen unter *2 Faktor Authentifizierung*:
Das Forum zeigt einen QR-Code, den du mit der App scannst; danach gibst du
einmal einen Code ein, um zu bestätigen, dass es funktioniert. Erst dann ist der
Schutz aktiv — wer zwischendurch abbricht, sperrt sich also nicht aus.

**Beim Anmelden** fragt das Forum nach dem Code, gleich im Anmeldefenster. Ohne
ihn kommt niemand hinein, auch nicht mit deinem Passwort.

**Wiederherstellungs-Codes.** Beim Einrichten bekommst du zehn Stück, jeweils
einmal verwendbar, und sie werden **nur dieses eine Mal angezeigt**. Bewahre sie
sicher auf — sie sind dein Weg zurück, wenn das Handy weg ist. Statt des
App-Codes gibst du dann einen davon ein. Neue Codes kannst du jederzeit in den
Einstellungen erzeugen; die alten verfallen dabei.

**Ganz ausgesperrt?** Wenn Gerät *und* Codes fehlen, kann ein Administrator die
2 Faktor Authentifizierung für dein Konto zurücksetzen. Danach meldest du dich
wieder allein mit dem Passwort an und richtest sie neu ein.

### Passkeys: mit Touch ID, Face ID oder Windows Hello bestätigen

Statt den sechsstelligen Code zu tippen, kannst du den zweiten Schritt auch mit
dem Gerät bestätigen, das du gerade in der Hand hast. Einrichten in denselben
Einstellungen unter *Passkeys*, sobald die 2 Faktor Authentifizierung an ist.

**Dein Fingerabdruck verlässt das Gerät nie.** Das Betriebssystem prüft dich vor
Ort und schickt dem Forum nur eine Unterschrift. Das Forum sieht weder
Fingerabdruck noch Gesicht — und kann es auch gar nicht.

Drei Dinge, die überraschen, wenn man sie nicht weiß:

- **Ein Passkey gehört zum Gerät.** Der auf dem Mac liegt nicht auf dem Handy.
  Apple und Google gleichen Passkeys inzwischen ab, aber verlassen solltest du
  dich nicht darauf: richte jedes Gerät ein, das du nutzt, und **behalte deine
  Wiederherstellungs-Codes**.
- **Ein Passkey gilt nur für diese Adresse.** Einer, der auf einem Testforum
  eingerichtet wurde, funktioniert im echten Forum nicht. Das ist kein Fehler,
  sondern genau der Schutz: deshalb kann eine gefälschte Seite deinen Passkey
  nicht abgreifen.
- **Der Code bleibt.** Passkeys brauchen JavaScript und einen passenden Sensor.
  Fehlt eines von beidem, ist das Codefeld unverändert da — der Knopf erscheint
  gar nicht erst, wenn dein Gerät nicht mitspielt.

Ein Gerät, das du nicht mehr nutzt, entfernst du in den Einstellungen. Schaltest
du die 2 Faktor Authentifizierung ab, verschwinden alle Passkeys mit.

**„Angemeldet bleiben" funktioniert weiterhin.** Setzt du das Häkchen beim
Passwort, merkt sich das Forum nach dem Code *dieses Gerät* für 30 Tage — du
kommst dann ohne Passwort und ohne Code wieder herein. Das gilt nur für Geräte,
auf denen du den zweiten Faktor tatsächlich eingegeben hast; ein alter
Dauer-Zugang von vor der Einrichtung wird abgewiesen und würde den zweiten
Faktor sonst umgehen.

Meldest du dich irgendwo bewusst ab, vergisst das Forum nur *dieses* Gerät —
die anderen bleiben angemeldet. Schaltest du die 2 Faktor Authentifizierung ab,
änderst dein Passwort, oder setzt ein Administrator den zweiten Faktor zurück,
verlieren **alle** Geräte ihr Vertrauen und müssen sich neu ausweisen.

### Geänderte Nutzungsbedingungen

Ändern sich die Nutzungsbedingungen wesentlich, wirst du beim nächsten Aufruf
danach gefragt: statt der gewünschten Seite erscheinen die neuen Bedingungen mit
einem Knopf, um ihnen zuzustimmen. Erst danach geht es normal weiter — das ist
keine Störung, sondern die Zustimmung, die § 7 der Bedingungen verlangt.

Du bleibst dabei angemeldet, und gefragt wirst du **einmal pro Änderung**, nicht
bei jedem Besuch.

Wer nicht zustimmen möchte, ist nicht eingesperrt: Die Bedingungen selbst,
Impressum und Datenschutzerklärung bleiben lesbar, der Download der eigenen
Daten bleibt möglich, und abmelden kannst du dich jederzeit — der Hinweis
enthält dafür einen eigenen Link.
