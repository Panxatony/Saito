<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Command;

use App\Auth\Mlf2PasswordHasher;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Authentication\PasswordHasher\FallbackPasswordHasher;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * Emptying password hashes nothing can authenticate (#99).
 *
 * Two things have to hold, and only one of them is about the command. It must
 * empty exactly the unusable hashes and no others — and an emptied column must
 * be unable to log anyone in, including with an empty password. The second is
 * the assumption the whole change rests on, so it is measured here against the
 * real hasher chain rather than assumed.
 */
class ClearUnusablePasswordsCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public array $fixtures = ['app.Setting', 'app.User'];

    /** A 32-character md5, the shape 534 accounts on macnemo.de carry. */
    private const MD5_HASH = '5f4dcc3b5aa765d61d8327deb882cf99';

    private function users(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('Users');
    }

    private function setPassword(int $id, string $hash): void
    {
        $this->users()->updateAll(['password' => $hash], ['id' => $id]);
    }

    private function passwordOf(int $id): string
    {
        return (string)$this->users()->get($id)->get('password');
    }

    private function firstTwoIds(): array
    {
        return $this->users()->find()->select(['id'])->limit(2)
            ->orderBy(['id' => 'ASC'])->all()->extract('id')->toList();
    }

    /**
     * The chain as `AuthenticationServiceFactory` configures it.
     *
     * @return \Authentication\PasswordHasher\FallbackPasswordHasher
     */
    private function chain(): FallbackPasswordHasher
    {
        return new FallbackPasswordHasher(['hashers' => [
            DefaultPasswordHasher::class,
            Mlf2PasswordHasher::class,
        ]]);
    }

    /**
     * The premise: an emptied column authenticates nobody.
     *
     * If this ever failed, clearing would not be hygiene but a way in.
     *
     * @return void
     */
    public function testAnEmptyHashLetsNobodyIn(): void
    {
        $chain = $this->chain();

        $this->assertFalse($chain->check('', ''));
        $this->assertFalse($chain->check('password', ''));
        $this->assertFalse($chain->check(' ', ''));
    }

    /**
     * The other half of the premise: the md5 was already useless.
     *
     * Clearing takes nothing away from the member, because even the correct
     * password does not open the account.
     *
     * @return void
     */
    public function testTheCorrectPasswordDoesNotOpenAnMd5Account(): void
    {
        $this->assertFalse($this->chain()->check('password', self::MD5_HASH));
    }

    /**
     * A `$2a$` bcrypt hash must survive, and this is the test that matters
     * most in the file.
     *
     * `$2a$` is what PHP wrote for years before `$2y$`, and it verifies
     * perfectly well — but `password_get_info()` reports NULL for it. The
     * first version of this command asked that function, so it would have
     * counted every such member as legacy debt and emptied their password.
     * macnemo.de carries none; a development copy of an older database had
     * 157, and there is no reason another installation would not.
     *
     * @return void
     */
    public function testABcrypt2aHashIsUsableAndSurvives(): void
    {
        [$id] = $this->firstTwoIds();
        $salt = '$2a$10$' . substr(str_replace('+', '.', base64_encode(random_bytes(16))), 0, 22);
        $hash2a = crypt('correct horse', $salt);
        $this->assertTrue(password_verify('correct horse', $hash2a), 'premise: $2a$ verifies');
        $this->setPassword($id, $hash2a);

        $this->exec('clear_unusable_passwords --clear');

        $this->assertExitSuccess();
        $this->assertSame($hash2a, $this->passwordOf($id), 'a $2a$ hash must not be cleared');
        $this->assertTrue($this->chain()->check('correct horse', $this->passwordOf($id)));
    }

    /**
     * Without --clear it reports and stops.
     *
     * Asserted on the stored hash rather than on a count: the user fixture
     * already carries a 32-character hash of its own, commented "outdated
     * password", so an absolute number here would be describing the fixture
     * instead of the command.
     *
     * @return void
     */
    public function testItReportsWithoutChangingAnything(): void
    {
        [$id] = $this->firstTwoIds();
        $this->setPassword($id, self::MD5_HASH);

        $this->exec('clear_unusable_passwords');

        $this->assertExitSuccess();
        $this->assertOutputContains('Nothing was changed');
        $this->assertSame(self::MD5_HASH, $this->passwordOf($id));
    }

    /**
     * @return void
     */
    public function testClearEmptiesOnlyTheUnusableOne(): void
    {
        [$unusable, $keep] = $this->firstTwoIds();
        $this->setPassword($unusable, self::MD5_HASH);
        $bcrypt = (new DefaultPasswordHasher())->hash('correct horse');
        $this->setPassword($keep, $bcrypt);

        $this->exec('clear_unusable_passwords --clear');

        $this->assertExitSuccess();
        $this->assertSame('', $this->passwordOf($unusable));
        $this->assertSame($bcrypt, $this->passwordOf($keep), 'a usable hash must survive');
    }

    /**
     * The mylittleforum 2.x format is still accepted, so it must not be
     * mistaken for legacy debt and thrown away — that would lock out a member
     * who can currently log in.
     *
     * @return void
     */
    public function testASaltedSha1SurvivesBecauseItStillWorks(): void
    {
        [$id] = $this->firstTwoIds();
        $mlf2 = (new Mlf2PasswordHasher())->hash('Rosinenbrötchen');
        $this->setPassword($id, $mlf2);

        $this->exec('clear_unusable_passwords --clear');

        $this->assertExitSuccess();
        $this->assertSame($mlf2, $this->passwordOf($id));
        $this->assertTrue($this->chain()->check('Rosinenbrötchen', $this->passwordOf($id)));
    }

    /**
     * Running it twice must be as harmless as running it once: the second pass
     * sees empty columns, which are not "unusable hashes" to report again.
     *
     * @return void
     */
    public function testASecondRunFindsNothingLeftToDo(): void
    {
        [$id] = $this->firstTwoIds();
        $this->setPassword($id, self::MD5_HASH);

        $this->exec('clear_unusable_passwords --clear');
        $this->exec('clear_unusable_passwords');

        $this->assertExitSuccess();
        $this->assertOutputContains('nothing to clear');
    }
}
