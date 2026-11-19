<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Exception\Logger;

use Cake\Log\Log;

class ForbiddenLogger extends ExceptionLogger
{
    /**
     * Write, with "Forbidden" standing in for a missing message.
     *
     * @param string|null $message message
     * @param array|null $data data, see the parent
     * @return void
     */
    public function write($message = null, $data = null)
    {
        if (empty($message)) {
            $message = 'Forbidden';
        }

        parent::write($message, $data);
    }

    /**
     * {@inheritDoc}
     */
    protected function _write()
    {
        Log::write(
            'error',
            $this->_message(),
            ['scope' => ['saito.forbidden']]
        );
    }
}
