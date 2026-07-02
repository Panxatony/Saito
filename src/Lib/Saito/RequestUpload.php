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
     * Normalize a request-data value into a legacy `$_FILES`-style array.
     *
     * Only a genuine PSR-7 UploadedFile is accepted as an upload. Any other
     * value — including a client-supplied array — yields null, because a real
     * multipart upload always arrives as an UploadedFileInterface; a plain
     * array is an attacker-forged payload (see the security note below).
     *
     * @param mixed $value
     * @return array|null Legacy `$_FILES`-style array, or null if the
     *                    value wasn't a genuine upload.
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

        // SECURITY: never trust a client-supplied upload array. A genuine
        // multipart upload is delivered by the PSR-7 layer as an
        // UploadedFileInterface whose `tmp_name` is a server-generated temp
        // path. A plain array reaching this point means the client hand-crafted
        // the request body (JSON or nested form fields like
        // `upload[0][file][tmp_name]=…`) to forge `tmp_name` — which the
        // downstream copy()/rename()/move_uploaded_file() sinks would otherwise
        // read or move from an attacker-chosen server path (arbitrary file
        // disclosure / relocation). Reject anything that is not a real upload.
        return null;
    }
}
