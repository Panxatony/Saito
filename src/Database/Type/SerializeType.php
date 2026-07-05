<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Database\Type;

use Cake\Database\Driver;
use Cake\Database\Type\BaseType;
use PDO;

class SerializeType extends BaseType
{
    /**
     * {@inheritDoc}
     */
    public function marshal(mixed $value): mixed
    {
        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function toPHP(mixed $value, Driver $driver): mixed
    {
        if ($value === null) {
            return null;
        }
        if (empty($value)) {
            return [];
        }

        // Never instantiate objects from stored data (object-injection hardening).
        return unserialize($value, ['allowed_classes' => false]);
    }

    /**
     * {@inheritDoc}
     */
    public function toDatabase(mixed $value, Driver $driver): string|int|float|bool|\Cake\Database\ExpressionInterface|null
    {
        return serialize($value);
    }

    /**
     * {@inheritDoc}
     */
    public function toStatement(mixed $value, Driver $driver): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }
}
