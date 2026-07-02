<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Test;

use Laminas\Diactoros\UploadedFile;
use Saito\RequestUpload;

class RequestUploadTest extends SaitoTestCase
{
    /**
     * A genuine PSR-7 upload is normalized to the legacy $_FILES-style array,
     * with tmp_name taken from the server-side stream URI.
     *
     * @return void
     */
    public function testUploadedFileIsNormalized()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'saito-upload-test');
        file_put_contents($tmp, 'payload');

        try {
            $file = new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'photo.png', 'image/png');
            $result = RequestUpload::toArray($file);

            $this->assertIsArray($result);
            $this->assertSame('photo.png', $result['name']);
            $this->assertSame('image/png', $result['type']);
            $this->assertSame($tmp, $result['tmp_name']);
            $this->assertSame(UPLOAD_ERR_OK, $result['error']);
        } finally {
            unlink($tmp);
        }
    }

    /**
     * SECURITY REGRESSION: a client-forged upload array must be rejected.
     *
     * Before the fix, toArray() returned any client-supplied array verbatim,
     * so an attacker could set `tmp_name` to an arbitrary server path
     * (e.g. /etc/passwd). The downstream copy()/rename()/move_uploaded_file()
     * sinks would then read or relocate that file (arbitrary file disclosure).
     * A raw array is never a real upload, so it must yield null.
     *
     * @return void
     */
    public function testForgedUploadArrayIsRejected()
    {
        $forged = [
            'tmp_name' => '/etc/passwd',
            'name' => 'harmless.txt',
            'type' => 'text/plain',
            'size' => 1234,
            'error' => 0,
        ];

        $this->assertNull(RequestUpload::toArray($forged));
    }

    /**
     * Non-upload values (empty array, null, scalars) are not uploads.
     *
     * @return void
     */
    public function testNonUploadValuesReturnNull()
    {
        $this->assertNull(RequestUpload::toArray([]));
        $this->assertNull(RequestUpload::toArray(null));
        $this->assertNull(RequestUpload::toArray('/etc/passwd'));
    }
}
