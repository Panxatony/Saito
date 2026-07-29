<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Smiley;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;

class SmileyLoader
{

    protected $_smilies;

    /**
     * Get all smilies.
     *
     * @return array|mixed
     */
    public function get()
    {
        if ($this->_smilies !== null) {
            return $this->_smilies;
        }
        $this->_smilies = Cache::remember('Saito.Smilies.data', function () {
            $Smilies = TableRegistry::getTableLocator()->get('Smilies');
            // enableHydration(false) below means these are plain arrays, not
            // entities — which the static analysis cannot see through the query
            // builder, so it is stated here. It matters beyond tidiness: the
            // loop appends `$smiley` once per code and relies on arrays being
            // copied on assignment. With entities every appended smiley would
            // be the same object and end up carrying the last code.
            /** @var list<array<string, mixed>> $smiliesRaw */
            $smiliesRaw = $Smilies->find()
                ->contain(['SmileyCodes'])
                ->orderBy(['sort' => 'ASC'])
                ->enableHydration(false)
                ->all()
                ->toArray();

            $smilies = [];
            foreach ($smiliesRaw as $smiley) {
                // 'image' defaults to 'icon'
                if (empty($smiley['image'])) {
                    $smiley['image'] = $smiley['icon'];
                }
                // @bogus: if title is unknown it should be a problem
                $title = $smiley['title'];
                if ($title === null) {
                    $smiley['title'] = '';
                }
                // set type
                $smiley['type'] = $this->_getType($smiley);

                //= adds smiley-data to every smiley-code
                if (isset($smiley['smiley_codes'])) {
                    $codes = $smiley['smiley_codes'];
                    unset($smiley['id'], $smiley['smiley_codes']);
                    foreach ($codes as $code) {
                        $smiley['code'] = $code['code'];
                        $smilies[] = $smiley;
                    }
                }
            }

            return $smilies;
        });

        return $this->_smilies;
    }

    /**
     * detects smiley type
     *
     * @param array $smiley smiley
     * @return string image|font
     */
    protected function _getType(array $smiley)
    {
        if (preg_match('/^.*\.[\w]{3,4}$/i', $smiley['image'])) {
            return 'image';
        } else {
            return 'font';
        }
    }
}
