<?php
declare(strict_types=1);

namespace Webhooks\Test\TestCase\Lib;

use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use Webhooks\Lib\UserEventPayload;

/**
 * The deprecated `legacyContactFields`, and the promises it still has to keep.
 *
 * Off by default is the important one, and it is the first test here: a
 * transfer of somebody's email address to an outside system must never begin
 * because a key was left out of a configuration file.
 *
 * @covers \Webhooks\Lib\UserEventPayload
 */
class LegacyContactFieldsTest extends TestCase
{
    use LocatorAwareTrait;

    protected array $fixtures = ['app.User', 'app.Entry', 'app.Category', 'app.Setting'];

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        Configure::delete('Saito.webhooks.user.legacyContactFields');
        Configure::delete('Saito.Settings.store_ip');
        Configure::delete('Saito.Settings.store_ip_anonymized');
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(): array
    {
        $user = $this->getTableLocator()->get('Users')->get(3);

        return UserEventPayload::fromUser('register', $user, '2026-08-03T10:00:00Z')
            ->toArray()['user'];
    }

    /**
     * **The one that matters.** An installation that never heard of this setting
     * sends id and username, and nothing else.
     *
     * @return void
     */
    public function testNothingExtraWithoutTheSetting(): void
    {
        $this->assertSame(['id', 'username'], array_keys($this->payloadFor()));
    }

    /**
     * @return void
     */
    public function testExplicitFalseIsAlsoOff(): void
    {
        Configure::write('Saito.webhooks.user.legacyContactFields', false);

        $this->assertSame(['id', 'username'], array_keys($this->payloadFor()));
    }

    /**
     * @return void
     */
    public function testEmailIsCarriedWhenAsked(): void
    {
        Configure::write('Saito.webhooks.user.legacyContactFields', true);

        $user = $this->payloadFor();

        $this->assertArrayHasKey('email', $user);
        $this->assertNotSame('', $user['email']);
    }

    /**
     * A forum that has decided not to keep IP addresses does not start posting
     * them to an outside system because a webhook was switched on. Sending is
     * more exposing than storing, so the stricter setting wins.
     *
     * @return void
     */
    public function testNoIpWhenTheForumDoesNotStoreIps(): void
    {
        Configure::write('Saito.webhooks.user.legacyContactFields', true);
        Configure::write('Saito.Settings.store_ip', false);

        $this->assertArrayNotHasKey('ip', $this->payloadFor());
    }

    /**
     * @return void
     */
    public function testIpIsCarriedWhenTheForumStoresIt(): void
    {
        Configure::write('Saito.webhooks.user.legacyContactFields', true);
        Configure::write('Saito.Settings.store_ip', true);
        Configure::write('Saito.Settings.store_ip_anonymized', false);

        $this->assertSame('198.51.100.7', $this->payloadFor()['ip']);
    }

    /**
     * And an installation that keeps them shortened sends them shortened — the
     * webhook must not be the way around the forum's own anonymisation.
     *
     * @return void
     */
    public function testIpIsAnonymisedWhenTheForumAnonymisesIt(): void
    {
        Configure::write('Saito.webhooks.user.legacyContactFields', true);
        Configure::write('Saito.Settings.store_ip', true);
        Configure::write('Saito.Settings.store_ip_anonymized', true);

        $ip = $this->payloadFor()['ip'];

        $this->assertNotSame('198.51.100.7', $ip, 'the full address must not go out');
        $this->assertStringContainsString('…', $ip);
    }
}
