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
use Saito\RequestUpload;

/**
 * Column type for avatar upload fields.
 *
 * Preserves the $_FILES array through ORM marshaling so that validation rules
 * and the AvatarBehavior can access it. The behavior always replaces the array
 * with the final filename string before the SQL INSERT/UPDATE runs.
 */
class AvatarFileType extends BaseType
{
    public function marshal(mixed $value): mixed
    {
        // Cake 5 hands file uploads to the marshaller as PSR-7
        // UploadedFileInterface objects. Normalize to the $_FILES-style
        // array the validator and AvatarBehavior still operate on.
        $upload = RequestUpload::toArray($value);
        if ($upload !== null) {
            return $upload;
        }
        if ($value === null || $value === '') {
            return null;
        }

        return (string)$value;
    }

    public function toPHP(mixed $value, Driver $driver): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string)$value;
    }

    public function toDatabase(mixed $value, Driver $driver): string|int|float|bool|\Cake\Database\Expression\ExpressionInterface|null
    {
        if (is_array($value)) {
            // Behavior did not run — should not happen in practice
            return null;
        }
        if ($value === null || $value === '') {
            return null;
        }

        return (string)$value;
    }

    public function toStatement(mixed $value, Driver $driver): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }
}
