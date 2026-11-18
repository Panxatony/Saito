<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace BbcodeParser\Test\TestCase\Lib;

use Saito\Markup\MarkupSettings;

/**
 * Markup settings a test can change after the parser was built with them.
 *
 * A named class rather than an anonymous one: the tests call setSingle() on
 * it, and a static reader sees only the declared MarkupSettings type of an
 * anonymous subclass, so it reported every call as undefined.
 */
class WritableMarkupSettings extends MarkupSettings
{
    /**
     * @param string $key setting
     * @param mixed $value new value
     * @return void
     */
    public function setSingle(string $key, mixed $value): void
    {
        $this->_settings[$key] = $value;
    }
}
