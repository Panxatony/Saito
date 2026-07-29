<!-- admin -->
# E-Mail einrichten #

## Grundeinstellungen ##

### Hauptadresse ###

In den Foreneinstellungen muss eine Hauptadresse hinterlegt sein.

### Weitere Adressen ###

Die Hauptadresse lässt sich für einzelne Zwecke überschreiben:

- Kontaktadresse: *Empfänger* des allgemeinen Kontaktformulars
- Registrierungsadresse: *Absender* der Bestätigungsmail bei der Anmeldung
- Systemadresse: *Absender* aller vom Forum erzeugten Nachrichten,
  etwa Benachrichtigungen

## Weitergehende Einstellungen ##

Über die Adressen hinaus wird der Versand in `config/app.php` festgelegt. Saito
verschickt über das Mail-Profil `saito`; eine dort gesetzte `from`-Adresse gilt
deshalb als *Absender* für alles, was das Forum verschickt:

    'EmailTransport' => [
        'saito' => [
            'className' => 'Smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => '…',
            'password' => '…',
        ],
    ],

    'Email' => [
        'saito' => [
            'transport' => 'saito',
            'from' => 'contact@example.com',
        ],
    ],

Die Werte können auch aus der Umgebung stammen — welche `EMAIL_*`-Variablen die
mitgelieferte Konfiguration liest, steht in `config/.env.default`.

[cakephp-email-config]: https://book.cakephp.org/5/en/core-libraries/email.html
