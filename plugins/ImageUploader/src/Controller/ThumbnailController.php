<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace ImageUploader\Controller;

use Cake\Cache\Cache;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use claviska\SimpleImage;
use Saito\Exception\SaitoForbiddenException;

/**
 * Thumbnail Controller
 *
 * Extends raw Controller for performance
 */
class ThumbnailController extends Controller
{
    public bool $autoRender = false;

    /**
     * Thumb Image Generator
     *
     * @return Response
     */
    public function thumb(): Response
    {
        $id = (int)$this->request->getParam('id');

        try {
            ['hash' => $fingerprint, 'type' => $type, 'raw' => $raw] = Cache::remember(
                (string)$id,
                function () use ($id) {
                    $Uploads = $this->fetchTable('ImageUploader.Uploads');
                    $document = $Uploads->get($id);

                    $hash = $document->get('hash');
                    $type = $document->get('type');
                    $filePath = $document->get('file');

                    if (!file_exists($filePath)) {
                        throw new NotFoundException("Upload file for id $id not found on disk.");
                    }

                    $raw = file_get_contents($filePath);

                    // Every image gets scaled, not only the large ones.
                    //
                    // There used to be a `size > 150000` threshold here, and
                    // anything under it was served at full resolution as a
                    // "thumbnail". Measured on the running forum: 2961 of 5542
                    // uploads fell under it, one of them delivering 121,874
                    // bytes for a tile drawn at 84 pixels, and a page of sixty
                    // came to about 8 MB. The threshold was never a decision
                    // about what a thumbnail should be — it was an assumption
                    // from a time when uploads were smaller. The largest file
                    // in that archive is 57 MB.
                    //
                    // Only images: an upload can be a video, an audio file or
                    // plain text, and handing those to an image library is how
                    // a listing turns into a 500. The old threshold hid this by
                    // accident, because those files were usually small enough
                    // to skip the branch.
                    if (str_starts_with((string)$type, 'image/')) {
                        try {
                            $raw = (new SimpleImage())
                                ->fromFile($filePath)
                                ->bestFit(300, 300)
                                ->toString();
                        } catch (\Throwable $e) {
                            // A file the library cannot read — truncated, an
                            // exotic variant, an extension that lies about its
                            // contents. Serve what is on disk rather than
                            // leaving a hole in the grid; the browser will
                            // either render it or not, and either way the rest
                            // of the archive still shows.
                            $raw = file_get_contents($filePath);
                        }
                    }

                    return compact('hash', 'raw', 'type');
                },
                Configure::read('Saito.Settings.uploader')->getCacheKey()
            );
        } catch (NotFoundException $e) {
            // File missing on disk (e.g. after server migration) — return 404 silently
            return $this->response->withStatus(404);
        }

        $hash = (string)$this->request->getQuery('h');
        if ($hash !== $fingerprint) {
            throw new SaitoForbiddenException(
                "Attempt to access image-thumbnail $id."
            );
        }

        return $this->response
            ->withHeader('Content-Type', $type)
            // Defense-in-depth: never let an image response be sniffed into
            // something else, and sandbox it so any (legacy) SVG that still
            // carries inline script cannot execute in our origin.
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', 'sandbox; default-src \'none\'')
            ->withStringBody($raw)
            ->withCache('-1 minute', '+1 year');
    }
}
