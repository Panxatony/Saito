<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Markup;

use Cake\Core\Configure;

class MarkupSettings
{
    protected $_defaults = [
        //= default values for app settings
        'quote_symbol' => '>',
        'smilies' => false,
        //= computed values
        // Base URLs for the @user and #posting tags in posting text. These are
        // substituted at render time, so a change here rewrites the links in
        // every existing posting at once.
        //
        // `hashBaseUrl` follows the active frontend: on an island install
        // `entries/view` renders the retired SPA shell, so a #123 tag dropped
        // the reader out of the island and into the old interface.
        // `users/name/` needs no branch — it has no view of its own, it
        // resolves a name to an ID and redirects to whichever profile the
        // frontend uses.
        'atBaseUrl' => 'users/name/', // base-URL for @ tags
        'hashBaseUrl' => null, // base-URL for # tags, see below
    ];

    protected $_settings;

    /**
     * Constructor
     *
     * @param array $settings settings
     */
    public function __construct(array $settings = [])
    {
        $this->_defaults['hashBaseUrl'] = Configure::read('Saito.frontend') === 'island'
            ? 'entries/htmx-posting/'
            : 'entries/view/';
        $this->set($settings);
    }

    /**
     * Set all settings
     *
     * @param array $settings settings
     * @return self
     */
    public function set(array $settings): self
    {
        $this->_settings = $settings + $this->_defaults;

        return $this;
    }

    /**
     * Get settings
     *
     * @param string $key key
     * @return mixed
     */
    public function get(string $key)
    {
        if (isset($this->_settings[$key])) {
            return $this->_settings[$key];
        }

        return null;
    }

    /**
     * Gets settings as array
     *
     * BC for BBCode Parser Class. Should be refactored to be not necessary.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->_settings;
    }
}
