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
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;

/**
 * Report — or set — the version the database believes it is at.
 *
 * An installation carries two version numbers. `Saito.v` comes from the code in
 * `src/Lib/version.php`; `db_version` is a row in `settings` and is what the
 * updater compares against to decide whether it should take over. They are
 * supposed to match, and after a code-only release they only do because
 * somebody remembered to change the row by hand.
 *
 *     bin/cake db_version              # what are they, and do they agree?
 *     bin/cake db_version 8.4.13       # set the row
 *
 * Written because they had quietly drifted on two of three installations: the
 * test forum ran code from `develop` while its row still said 8.4.9, and the
 * beta's row was ahead of its code. Neither is visible from the outside, and
 * the consequence of the second — the updater deciding it has nothing to do —
 * is silence rather than an error.
 *
 * Deliberately a command rather than the `UPDATE` this replaces. The SQL needs
 * credentials, and those live in a different place on every installation: an
 * FPM pool here, `config/.env` there. This runs where the application runs and
 * asks the application's own connection, so a deploy script does not have to
 * know any of that.
 */
class DbVersionCommand extends Command
{
    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Show or set the version recorded in the database.')
            ->addArgument('version', [
                'help' => 'The version to record. Omit to only report.',
                'required' => false,
            ]);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $Settings = TableRegistry::getTableLocator()->get('Settings');
        $row = $Settings->find()->where(['name' => 'db_version'])->first();
        if ($row === null) {
            $io->error('No `db_version` row in `settings` — is this a Saito database?');

            return static::CODE_ERROR;
        }

        $code = (string)Configure::read('Saito.v');
        $database = (string)$row->get('value');
        $wanted = $args->getArgument('version');

        if ($wanted === null) {
            $io->out('code:     ' . $code);
            $io->out('database: ' . $database);
            if ($code === $database) {
                $io->success('in agreement');

                return static::CODE_SUCCESS;
            }
            // Not an error: this is exactly the state between copying the files
            // and setting the row, and a deploy script wants to ask without
            // being told it has failed.
            $io->warning('they differ — the updater compares against the database value');

            return static::CODE_SUCCESS;
        }

        if ($database === $wanted) {
            $io->out(sprintf('database already at %s, nothing to do', $wanted));

            return static::CODE_SUCCESS;
        }

        $row->set('value', $wanted);
        $Settings->saveOrFail($row);
        $io->success(sprintf('database: %s → %s (code is %s)', $database, $wanted, $code));

        if ($wanted !== $code) {
            $io->warning('this does not match the code version — deliberate?');
        }

        return static::CODE_SUCCESS;
    }
}
