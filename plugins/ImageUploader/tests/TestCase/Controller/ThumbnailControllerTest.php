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
