<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TwoFactorTrustedDeviceFixture extends TestFixture
{
    public string $table = 'two_factor_trusted_devices';

    /** Empty on purpose: devices are trusted by the code under test. */
    public array $records = [];
}
