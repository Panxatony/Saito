<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class WebauthnCredentialFixture extends TestFixture
{
    public string $table = 'webauthn_credentials';

    /** Empty on purpose: credentials only exist after a real ceremony. */
    public array $records = [];
}
