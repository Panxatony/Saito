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
use Cake\ORM\TableRegistry;

/**
 * Report — and on request remove — password hashes nothing can authenticate.
 *
 *     bin/cake clear_unusable_passwords            # count them, change nothing
 *     bin/cake clear_unusable_passwords --clear    # empty those columns
 *
 * A forum grown from mylittleforum still carries hashes in formats the login
 * stopped accepting years ago. `AuthenticationServiceFactory` configures two
 * hashers and no more: bcrypt, and the salted sha1 mylittleforum 2.x wrote.
 * A plain md5 or sha1 from before that matches neither and never will — see
 * that file for why re-admitting them would be the wrong repair.
 *
 * Such a hash cannot log anybody in, so it does nothing. It is still thirteen
 * years of reused passwords sitting in a table, and people use the same
 * password in more than one place: if the database is ever disclosed, the
 * exposure is not to this forum but to whatever else those members used it
 * for. Emptying the column removes that at no functional cost — the password
 * reset issues a bcrypt hash without reading the old value, so a returning
 * member recovers by e-mail exactly as before.
 *
 * Measured on the macnemo.de installation in August 2026: 534 accounts, none
 * used since 2013, against 287 on bcrypt. 374 of them had written postings,
 * which is why this empties a column and does not delete accounts. Whether an
 * account dormant for thirteen years should be *kept* is a separate question,
 * and a data-protection one rather than a security one.
 *
 * Deliberately a command and not a migration. What is in a `users` table
 * differs from installation to installation, and emptying a member's password
 * is the operator's decision on their own data, not something a release should
 * do to them while they are reading the CHANGELOG.
 */
class ClearUnusablePasswordsCommand extends Command
{
    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Report password hashes no configured hasher can accept.')
            ->addOption('clear', [
                'help' => 'Empty the password column of the accounts listed. Without this nothing is changed.',
                'boolean' => true,
            ]);
    }

    /**
     * Can any configured hasher ever return true for this hash?
     *
     * Asked structurally, because the plaintext is unknown and there is nothing
     * to try. Two shapes are accepted, matching the two hashers in the chain:
     *
     *   - anything `crypt()` recognises, which is what `password_verify()` and
     *     therefore `DefaultPasswordHasher` run underneath. Handed a valid hash
     *     as its salt, `crypt()` reproduces that hash's own format, so the
     *     answer comes back the same length and with the same `$` prefix. A
     *     string it cannot parse yields a short failure marker instead.
     *   - 50 hex characters: `Mlf2PasswordHasher` reads them as a 40-character
     *     sha1 followed by a 10-character salt. crypt knows nothing of this
     *     format, so it needs its own test.
     *
     * The obvious version of this used `password_get_info()`, and it was
     * wrong in the worst direction. That function reports NULL for a `$2a$`
     * bcrypt hash — the prefix PHP wrote for years before `$2y$` — while
     * `password_verify()` accepts it perfectly well. Every such member would
     * have been counted as legacy debt and had their password emptied by
     * `--clear`. macnemo.de happens to carry none, but a development copy of
     * an older database had 157, and there is no reason another installation
     * would not.
     *
     * Deliberately conservative in the other direction too: a hash this cannot
     * classify is treated as usable and left alone.
     *
     * @param string $hash the stored hash
     * @return bool
     */
    protected function isUsable(string $hash): bool
    {
        if ($hash === '') {
            // Already cleared, or never set. Nothing to do either way.
            return true;
        }

        $probe = @crypt('probe', $hash);
        if (is_string($probe) && strlen($probe) === strlen($hash) && str_starts_with($hash, '$')) {
            return true;
        }

        return (bool)preg_match('/^[0-9a-f]{50}$/i', $hash);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $Users = TableRegistry::getTableLocator()->get('Users');
        $rows = $Users->find()
            ->select(['id', 'username', 'password', 'last_login'])
            ->all();

        $unusable = [];
        $usable = 0;
        $newestLogin = null;
        foreach ($rows as $row) {
            if ($this->isUsable((string)$row->get('password'))) {
                $usable++;
                continue;
            }
            $unusable[] = (int)$row->get('id');
            $login = $row->get('last_login');
            if ($login !== null && ($newestLogin === null || $login > $newestLogin)) {
                $newestLogin = $login;
            }
        }

        $io->out(sprintf('accounts:            %d', $usable + count($unusable)));
        $io->out(sprintf('usable hashes:       %d', $usable));
        $io->out(sprintf('unusable hashes:     %d', count($unusable)));
        if ($newestLogin !== null) {
            $io->out(sprintf('newest login among them: %s', $newestLogin->format('Y-m-d')));
        }

        if (!$unusable) {
            $io->success('nothing to clear');

            return static::CODE_SUCCESS;
        }

        if (!$args->getOption('clear')) {
            $io->out('');
            $io->info('Nothing was changed. Re-run with --clear to empty these columns.');
            $io->out('Those members keep their accounts and their postings, and recover');
            $io->out('through the password reset, which does not read the old value.');

            return static::CODE_SUCCESS;
        }

        // updateAll rather than saving entities: this must not run validation,
        // fire events, or move `modified` on several hundred member records. It
        // changes one column and leaves every other fact about them alone.
        $affected = $Users->updateAll(['password' => ''], ['id IN' => $unusable]);
        $io->success(sprintf('cleared %d unusable hashes', $affected));

        return static::CODE_SUCCESS;
    }
}
