<?php

/**
 * Email Config
 *
 * For config options see CakePHP 3 email configuration.
 *
 * @see http://book.cakephp.org/3.0/en/core-libraries/email.html#configuring-transports
 */

$smtpHost = env('EMAIL_SMTP_HOST', null);

$config = [
    'Email' => [
        'saito' => [
            'transport' => 'saito',
            'from' => [env('EMAIL_FROM_ADDRESS', 'noreply@localhost') => env('EMAIL_FROM_NAME', 'Forum')],
        ]
    ],
    'EmailTransport' => [
        'saito' => $smtpHost ? [
            'className' => 'Smtp',
            'host' => $smtpHost,
            'port' => (int)env('EMAIL_SMTP_PORT', 587),
            'username' => env('EMAIL_SMTP_USERNAME', null),
            'password' => env('EMAIL_SMTP_PASSWORD', null),
            'tls' => filter_var(env('EMAIL_SMTP_TLS', 'true'), FILTER_VALIDATE_BOOLEAN),
            'timeout' => 30,
        ] : [
            // Fallback: local PHP mailer
            'className' => 'Mail',
        ]
    ]
];

return $config;
