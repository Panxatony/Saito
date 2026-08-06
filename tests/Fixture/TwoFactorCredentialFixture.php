<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TwoFactorCredentialFixture extends TestFixture
{
    public string $table = 'two_factor_credentials';

    /** Empty on purpose: enrolment is what the tests exercise. */
    public array $records = [];
}
