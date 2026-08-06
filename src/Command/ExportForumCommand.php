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
use Saito\App\Registry;
use Saito\Forum\ForumExport;

/**
 * Export the whole forum's content as JSON Lines.
 *
 * The other half of the export story: {@see \Saito\User\DataExport} is one
 * member's data through a request; this is the entire forum through the
 * console, because 680k postings do not fit in either a request or memory.
 * Everything streams — {@see \Saito\Forum\ForumExport} yields a record at a
 * time and this writes each as one JSON line, so the peak is a batch of 500
 * rows whatever the forum's size.
 *
 *     bin/cake export_forum
 *     bin/cake export_forum -o /path/to/forum.jsonl
 */
class ExportForumCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        // Loads the Saito settings ForumExport reads for the forum name and the
        // ORM the generators page through.
        Registry::initialize();
    }

    /**
     * {@inheritDoc}
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription([
                'Export the whole forum — users, categories, postings and upload',
                'metadata — as JSON Lines, for a move or a content-level backup',
                'beside the SQL dump.',
                '',
                'Not included: password hashes and activation codes (restore accounts',
                'from the SQL dump), and the upload files themselves (they live under',
                'webroot/useruploads and belong in a file-level backup).',
            ])
            ->addOption('output', [
                'short' => 'o',
                'help' => 'File to write. Defaults to tmp/export/forum-<timestamp>.jsonl.',
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $path = (string)$args->getOption('output');
        if ($path === '') {
            $dir = TMP . 'export';
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                $io->error("Could not create the export directory: $dir");

                return static::CODE_ERROR;
            }
            $path = $dir . DS . 'forum-' . date('Ymd-His') . '.jsonl';
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $io->error("Could not open for writing: $path");

            return static::CODE_ERROR;
        }
        // Owner only. This file is every member's e-mail address and, where the
        // forum stores them, their IP addresses; the default umask made it
        // world-readable (0644), which on a shared host hands the lot to any
        // local account. Set before a single record is written, not after.
        @chmod($path, 0600);

        // No pretty-print and no escaping: this is a machine file read a line at
        // a time, and unescaped UTF-8/slashes keep it small and legible.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $write = function (array $record) use ($handle, $flags): void {
            fwrite($handle, (string)json_encode($record, $flags) . "\n");
        };

        $export = new ForumExport();

        $meta = $export->meta();
        $write($meta);
        $counts = $meta['counts'];
        $io->out(sprintf(
            'Exporting %s — %d users, %d categories, %d postings, %d uploads.',
            $meta['forum'],
            $counts['users'],
            $counts['categories'],
            $counts['postings'],
            $counts['uploads'],
        ));

        $sections = [
            'users' => $export->eachUser(),
            'categories' => $export->eachCategory(),
            'postings' => $export->eachPosting(),
            'uploads' => $export->eachUpload(),
        ];

        $total = 0;
        foreach ($sections as $name => $records) {
            $written = 0;
            foreach ($records as $record) {
                $write($record);
                $written++;
                if ($written % 5000 === 0) {
                    $io->out(sprintf('  %s … %d', $name, $written));
                }
            }
            $io->out(sprintf('  %s: %d', $name, $written));
            $total += $written;
        }

        fclose($handle);
        $io->success(sprintf('Wrote %d records plus the header to %s', $total, $path));

        return static::CODE_SUCCESS;
    }
}
