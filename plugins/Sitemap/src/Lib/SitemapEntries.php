<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Sitemap\Lib;

use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;

class SitemapEntries extends SitemapGenerator
{
    protected $_type = 'entries';

    /**
     * Generate list of sitemap files
     *
     * @return array
     */
    public function files()
    {
        $Entries = TableRegistry::getTableLocator()->get('Entries');
        $entry = $Entries->find()
            ->contain(['Categories'])
            ->select(['Entries.id'])
            ->where(['Categories.accession' => 0])
            ->orderBy(['Entries.id' => 'DESC'])
            ->first();

        $count = (empty($entry)) ? 0 : $entry->get('id');
        $count = intval($count / $this->_divider) + 1;
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $i * $this->_divider + 1;
            $end = ($i + 1) * $this->_divider;
            $files[] = ['url' => $this->_filename([$start, $end])];
        }

        return $files;
    }

    /**
     * {@inheritDoc}
     *
     * @param array $params
     * @return array|mixed
     * @throws \InvalidArgumentException
     */
    protected function _parseParams($params)
    {
        $start = filter_var($params[0], FILTER_VALIDATE_INT);
        $end = filter_var($params[1], FILTER_VALIDATE_INT);
        if (
            !$start || !$end || ($end - $start) > $this->_divider ||
                // entries must be in even divider range
                // prevents maliciously spamming the server with new cache files
                ($start - 1) % $this->_divider !== 0 ||
                ($end) % $this->_divider !== 0
        ) {
            throw new \InvalidArgumentException();
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * {@inheritDoc}
     */
    protected function _content(array $params): array
    {
        $now = time();
        $filename = $this->_filename([$params['start'], $params['end']]);
        $cache = Cache::read($filename, 'sitemap');
        if ($cache) {
            return $cache;
        }

        $Entries = TableRegistry::getTableLocator()->get('Entries');
        $entries = $Entries->find()
            ->contain(['Categories'])
            ->select(['Entries.id', 'Entries.time', 'Entries.edited'])
            ->where([
                                'Categories.accession' => 0,
                                'Entries.id >=' => $params['start'],
                                'Entries.id <=' => $params['end'],
                            ])
            ->enableHydration(false)
            ->all();

        $urls = [];
        if (empty($entries)) {
            return $urls;
        }
        foreach ($entries as $entry) {
            if (!empty($entry['edited'])) {
                $lastmod = $entry['edited']->getTimestamp();
            } else {
                $lastmod = $entry['time']->getTimestamp();
            }
            if ($now > ($lastmod + (3 * 86400))) { // old entries
                $changefreq = 'monthly';
            } elseif ($now > ($lastmod + 86400)) { // recently active entries
                $changefreq = 'daily';
            } else { // currently active entries
                $changefreq = 'hourly';
            }
            $urls[] = [
                    'loc' => 'entries/view/' . $entry['id'],
                    'lastmod' => date('c', $lastmod),
                    'changefreq' => $changefreq,
            ];
        }

        Cache::write($filename, $urls, 'sitemap');

        return $urls;
    }
}
