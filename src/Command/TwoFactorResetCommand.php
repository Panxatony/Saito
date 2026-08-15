<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;
use Saito\App\Registry;

/**
 * Take an account's second factor away from the console.
 *
 * The way back in when nobody is left to help. An administrator can already
 * reset another member's second factor from the admin screen — but that needs
 * an administrator who is signed in, and on a forum run by one person there may
 * be no second administrator to ask. Locked out of your own forum, the console
 * is what remains.
 *
 *     bin/cake two_factor_reset Panxatony
 *
 * Deliberately a command rather than a paragraph of SQL in the documentation. A
 * reset means four tables today, and that list grew twice in three days;
 * documented SQL would have been wrong twice in that time and wrong *silently*,
 * because clearing only the credential still restores the sign-in and leaves
 * the recovery codes and the passkey standing. The list lives in
 * {@see \App\Model\Table\UsersTable::resetSecondFactor()}, once, and this
 * command calls it — as does the admin screen.
 *
 * Access is the shell, and that is the honest boundary: whoever can run this
 * can already read the database it would otherwise be done in. It is not a
 * backdoor, it is the same door with a handle on it — and unlike the SQL, it
 * leaves a log entry saying who lost their second factor and when.
 */
class TwoFactorResetCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        Registry::initialize();
    }

    /**
     * {@inheritDoc}
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription([
                'Turn two-factor authentication off for one account, from the',
                'console — for when the device and the recovery codes are both',
                'gone and there is no other administrator to ask.',
                '',
                'Clears the authenticator secret, the recovery codes, the trusted',
                'devices and the passkeys. The account itself is untouched: it can',
                'sign in with its password again and set the second factor up anew.',
            ])
            ->addArgument('username', [
                'help' => 'The account to reset.',
                'required' => true,
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $username = (string)$args->getArgument('username');
        $Users = $this->fetchTable('Users');

        $user = $Users->find()->where(['username' => $username])->first();
        if ($user === null) {
            // By name and not by id, because a name is what somebody locked out
            // actually knows about themselves. Say plainly that it did not
            // match: there is nothing to leak here — this runs on the server,
            // by someone who can already read the table.
            $io->error(sprintf('No account named "%s".', $username));

            return static::CODE_ERROR;
        }

        $userId = (int)$user->get('id');
        $wasEnabled = $this->fetchTable('TwoFactorCredentials')->isEnabledFor($userId);

        $Users->resetSecondFactor($userId);

        // Logged like the administrator's reset, and for the same reason: this
        // is exactly the step somebody who reached the server would take, so it
        // has to leave a trace that can be read afterwards. "console" rather
        // than a name because the console has none — the shell account is in
        // the system's own logs.
        Log::write(
            'info',
            sprintf('Second factor reset for user "%s" (id %d) from the console.', $username, $userId),
            ['scope' => ['saito.info']],
        );

        if ($wasEnabled) {
            $io->success(sprintf('Two-factor authentication is off for "%s".', $username));
        } else {
            // Not an error. Somebody locked out for another reason may well try
            // this first, and "there was nothing to reset" is the useful answer
            // — it says the second factor is not what is keeping them out.
            $io->out(sprintf('"%s" had no second factor enabled; nothing was in the way.', $username));
        }
        $io->out('Any passkeys and trusted devices for the account were cleared as well.');

        return static::CODE_SUCCESS;
    }
}
