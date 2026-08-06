<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TwoFactorRecoveryCodeFixture extends TestFixture
{
    public string $table = 'two_factor_recovery_codes';

    /** Empty on purpose: codes are issued by the code under test. */
    public array $records = [];
}
