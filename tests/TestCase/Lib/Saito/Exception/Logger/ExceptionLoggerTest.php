<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Test\Exception\Logger;

use Cake\TestSuite\TestCase;
use Saito\Exception\Logger\ExceptionLogger;

/**
 * What a logged exception says about the request that caused it (#169).
 */
class ExceptionLoggerTest extends TestCase
{
    /**
     * Only "password" used to be masked: a failed contact mail logged the
     * sender's address and message, a failed second factor the code.
     *
     * @return void
     */
    public function testRequestDataIsMaskedExceptForAShortList(): void
    {
        $logger = new class extends ExceptionLogger {
            /**
             * @param mixed $data request data
             * @return mixed
             */
            public function filter($data)
            {
                return $this->_filterData($data);
            }
        };

        $filtered = $logger->filter([
            'do' => 'confirm',
            'code' => '123456',
            'user_email' => 'someone@example.com',
            'message' => 'private words',
            'password' => 'secret',
            'nested' => ['token' => 'abc', 'id' => '7'],
        ]);

        $this->assertSame([
            'do' => 'confirm',
            'code' => '***',
            'user_email' => '***',
            'message' => '***',
            'password' => '***',
            'nested' => ['token' => '***', 'id' => '7'],
        ], $filtered);
    }
}
