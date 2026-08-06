<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Model\Behavior;

use App\Model\Behavior\IpLoggingBehavior;
use Cake\Core\Configure;
use Saito\Test\Model\Table\SaitoTableTestCase;

/**
 * Whether the forum keeps IP addresses, and in what shape.
 *
 * This decides what a forum stores about the people writing in it, and it is
 * driven entirely by two settings — which is exactly the kind of thing that
 * keeps working after somebody breaks it, because nothing visibly fails when an
 * address is written that should not have been. Until now nothing tested it at
 * all.
 */
class IpLoggingBehaviorTest extends SaitoTableTestCase
{
    public $tableClass = 'Entries';

    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
    ];

    private \App\Model\Table\EntriesTable $Entries;

    public function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\EntriesTable $entries */
        $entries = $this->Table;
        $this->Entries = $entries;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
    }

    public function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    /**
     * @return string|null the ip stored on a freshly written posting
     */
    private function ipOfANewPosting(): ?string
    {
        $parent = $this->Entries->get(1);
        // createEntry(), not newEntity(): a posting carries server-set fields
        // (time, tid) that only this path fills in.
        $saved = $this->Entries->createEntry([
            'pid' => $parent->get('id'),
            'tid' => $parent->get('tid'),
            'subject' => 'ip logging',
            'text' => 'x',
            'name' => 'Ulysses',
            'user_id' => 3,
            'category_id' => $parent->get('category_id'),
        ]);
        $this->assertNotNull($saved, 'the posting had to save for this to mean anything');
        $this->assertEmpty($saved->getErrors());

        return $this->Entries->get($saved->get('id'))->get('ip');
    }

    /**
     * The setting is off on the installations this ships to, and off has to
     * mean *nothing stored* — not "stored but shortened".
     *
     * @return void
     */
    public function testNoAddressIsStoredWhenTheSettingIsOff(): void
    {
        Configure::write('Saito.Settings.store_ip', false);
        Configure::write('Saito.Settings.store_ip_anonymized', false);

        $this->assertEmpty($this->ipOfANewPosting());
    }

    public function testTheFullAddressIsStoredWhenAskedFor(): void
    {
        Configure::write('Saito.Settings.store_ip', true);
        Configure::write('Saito.Settings.store_ip_anonymized', false);

        $this->assertSame('198.51.100.42', $this->ipOfANewPosting());
    }

    /**
     * Anonymised means the stored value can no longer be read back as the
     * address — the point of the setting, and the part worth asserting rather
     * than the exact shape of the masking.
     *
     * @return void
     */
    public function testTheAddressIsMaskedWhenAnonymisationIsOn(): void
    {
        Configure::write('Saito.Settings.store_ip', true);
        Configure::write('Saito.Settings.store_ip_anonymized', true);

        $stored = (string)$this->ipOfANewPosting();

        $this->assertNotSame('198.51.100.42', $stored);
        $this->assertNotEmpty($stored, 'anonymised is not the same as absent');
        $this->assertStringNotContainsString('198.51.100.42', $stored);
        // The tail that identifies the host is what has to be gone.
        $this->assertStringNotContainsString('100.42', $stored);
    }

    /**
     * Anonymising is not reversible into the original, and it does not blank
     * short inputs it cannot meaningfully mask.
     *
     * Asserted through the public helper because the Webhooks plugin calls it
     * directly — two implementations of "anonymised" would be one too many, so
     * this is the single definition and deserves its own coverage.
     *
     * @return void
     */
    public function testTheAnonymiserMasksTheMiddleAndLeavesShortInputAlone(): void
    {
        $masked = IpLoggingBehavior::anonymizeIp('198.51.100.42');
        $this->assertNotSame('198.51.100.42', $masked);
        $this->assertStringContainsString('…', $masked);
        $this->assertStringStartsWith('198', $masked);

        // IPv6 is masked too rather than passed through.
        $v6 = IpLoggingBehavior::anonymizeIp('2001:db8::dead:beef');
        $this->assertStringNotContainsString('dead:beef', $v6);

        // Too short to mask usefully: returned as it is, not mangled.
        $this->assertSame('1.2.3', IpLoggingBehavior::anonymizeIp('1.2.3'));
    }

    /**
     * Editing a posting must not stamp it with the editor's address: the
     * behaviour writes on creation only, and a moderator tidying somebody
     * else's post is not the person whose address belongs there.
     *
     * @return void
     */
    public function testAnEditDoesNotOverwriteTheStoredAddress(): void
    {
        Configure::write('Saito.Settings.store_ip', true);
        Configure::write('Saito.Settings.store_ip_anonymized', false);

        $parent = $this->Entries->get(1);
        $saved = $this->Entries->createEntry([
            'pid' => $parent->get('id'),
            'tid' => $parent->get('tid'),
            'subject' => 'first',
            'text' => 'x',
            'name' => 'Ulysses',
            'user_id' => 3,
            'category_id' => $parent->get('category_id'),
        ]);
        $this->assertNotNull($saved);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->Entries->save($this->Entries->patchEntity(
            $this->Entries->get($saved->get('id')),
            ['subject' => 'edited'],
        ));

        $this->assertSame('198.51.100.42', $this->Entries->get($saved->get('id'))->get('ip'));
    }
}
