<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Cake 4 delivers file uploads as PSR-7 UploadedFileInterface objects
 * instead of the Cake-3-era $_FILES-style array. A lot of Saito's code
 * (validators, behaviors, uploaders) still keys off `tmp_name`, `name`,
 * `size` etc. — this helper converts a PSR-7 upload back into that
 * legacy array shape so the existing code keeps working.
 */
class RequestUpload
{
    /**
     * Normalize a request-data value that may be a PSR-7 UploadedFile,
     * a Cake-3-style upload array, or `null`.
     *
     * @param mixed $value
     * @return array|null Legacy `$_FILES`-style array, or null if the
     *                    value wasn't a recognizable upload.
     */
    public static function toArray($value): ?array
    {
        if ($value instanceof UploadedFileInterface) {
            return [
                'name' => $value->getClientFilename(),
                'type' => $value->getClientMediaType(),
                'tmp_name' => $value->getStream()->getMetadata('uri'),
                'error' => $value->getError(),
                'size' => $value->getSize(),
            ];
        }

        return is_array($value) ? $value : null;
    }
}
