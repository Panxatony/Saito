<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace ImageUploader\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use claviska\SimpleImage;
use ImageUploader\ImageUploaderPlugin;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;

class ThumbnailControllerTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Setting',
        'plugin.ImageUploader.Uploads',
    ];

    public function testCacheCreation()
    {
        $Uploads = TableRegistry::getTableLocator()->get('ImageUploader.Uploads');
        $upload = $Uploads->get(1);

        $filePath = Configure::read('Saito.Settings.uploadDirectory') . $upload->get('name');
        $raw = (new SimpleImage())
            ->fromNew(500, 500, 'blue')
            ->toString($upload->get('type'));
        file_put_contents($filePath, $raw);
        // pad image
        file_put_contents($filePath, str_repeat('0', $upload->get('size')), FILE_APPEND);

        ImageUploaderPlugin::configureCache(); // cache isn't bootstrapped through request yet

        $cacheKey = Configure::read('Saito.Settings.uploader')->getCacheKey();
        Cache::clear($cacheKey); // ensure no stale data from previous test runs

        $this->assertNull(Cache::read((string)$upload->get('id'), $cacheKey));

        $this->get('/api/v2/uploads/thumb/1?h=' . $upload->get('hash'));

        $cache = Cache::read((string)$upload->get('id'), $cacheKey);

        $image = imagecreatefromstring($cache['raw']);
        $this->assertSame(300, imagesx($image));
        $this->assertSame(300, imagesy($image));
        $this->assertSame($upload->get('type'), $cache['type']);
        $this->assertResponseEquals($cache['raw'], (string)$this->_response->getBody());
        $this->assertHeader('content-type', 'image/png');

        //// cleanup
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        unset($cache);
    }

    /**
     * A small image is scaled too.
     *
     * It was not: a `size > 150000` threshold sent anything under it at full
     * resolution and called it a thumbnail. On the running forum that was 2961
     * of 5542 uploads, one of them 121,874 bytes for a tile drawn at 84 pixels.
     *
     * Fixture 2 is 50,000 bytes — under the old threshold, so this test fails
     * against the previous code, which is the point of it.
     *
     * @return void
     */
    public function testASmallImageIsScaledDownToo()
    {
        $Uploads = TableRegistry::getTableLocator()->get('ImageUploader.Uploads');
        $upload = $Uploads->get(2);

        $filePath = Configure::read('Saito.Settings.uploadDirectory') . $upload->get('name');
        // 900×900: bigger than a thumbnail, small enough in bytes to have
        // slipped past the old threshold.
        $raw = (new SimpleImage())
            ->fromNew(900, 900, 'red')
            ->toString($upload->get('type'));
        file_put_contents($filePath, $raw);

        ImageUploaderPlugin::configureCache();
        $cacheKey = Configure::read('Saito.Settings.uploader')->getCacheKey();
        Cache::clear($cacheKey);

        $this->get('/api/v2/uploads/thumb/2?h=' . $upload->get('hash'));

        $cache = Cache::read((string)$upload->get('id'), $cacheKey);
        $image = imagecreatefromstring($cache['raw']);
        $this->assertSame(300, imagesx($image), 'the thumbnail was not scaled');
        $this->assertSame(300, imagesy($image));
        $this->assertLessThan(
            strlen($raw),
            strlen($cache['raw']),
            'a thumbnail that is not smaller than its original is not a thumbnail'
        );

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * An upload that is not an image must not be handed to the image library.
     *
     * The old threshold hid this by accident — a text file is small, so it
     * never reached the branch that would have thrown. Now every upload does,
     * and the type has to be checked rather than assumed.
     *
     * @return void
     */
    public function testANonImageIsServedUntouched()
    {
        $Uploads = TableRegistry::getTableLocator()->get('ImageUploader.Uploads');
        $upload = $Uploads->get(2);
        $upload->set('type', 'text/plain');
        $Uploads->saveOrFail($upload);

        $filePath = Configure::read('Saito.Settings.uploadDirectory') . $upload->get('name');
        file_put_contents($filePath, 'kein Bild, nur Text');

        ImageUploaderPlugin::configureCache();
        $cacheKey = Configure::read('Saito.Settings.uploader')->getCacheKey();
        Cache::clear($cacheKey);

        $this->get('/api/v2/uploads/thumb/2?h=' . $upload->get('hash'));

        $this->assertResponseOk();
        $this->assertResponseEquals('kein Bild, nur Text', (string)$this->_response->getBody());

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Test that an hash must be send with the thumbnail-URL
     *
     * The hash prevents reading out thumbnails by just increasing the image-id
     * in the URL.
     */
    public function testAccessFailureNoHash()
    {
        $Uploads = TableRegistry::getTableLocator()->get('ImageUploader.Uploads');
        $upload = $Uploads->get(1);

        $filePath = Configure::read('Saito.Settings.uploadDirectory') . $upload->get('name');
        $raw = (new SimpleImage())
            ->fromNew(100, 100, 'blue')
            ->toString($upload->get('type'));
        file_put_contents($filePath, $raw);

        $this->expectException(SaitoForbiddenException::class);
        $this->get('/api/v2/uploads/thumb/1');
    }
}
