<!-- admin -->
# Email Configuration #

## Basic Configuration ##

### Main Address ###

You have to set a main email address in the admin preferences.

### Optional Addresses ###

The main address can be overwritten by the:

- contact address: *recipient* address for the global contact form
- register address: *from* address for the register confirmation mail
- system address: *from* and *sender* for system generated messages (e.g. notifications)

## Advanced Configuration ##

Beyond the addresses above, delivery is configured in `config/app.php`. Saito
sends through the `saito` mail profile, so a `from` address set there is used as
the *sender* for everything the forum posts:

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

The values can also come from the environment — see `config/.env.default` for
the `EMAIL_*` variables the shipped configuration reads.

[cakephp-email-config]: https://book.cakephp.org/5/en/core-libraries/email.html
