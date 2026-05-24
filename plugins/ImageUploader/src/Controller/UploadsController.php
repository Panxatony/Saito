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

use Api\Controller\ApiAppController;
use Api\Error\Exception\GenericApiException;
use App\Model\Entity\User;
use Cake\Cache\Cache;
use Cake\Utility\Security;
use ImageUploader\Lib\MimeType;
use ImageUploader\Model\Entity\Upload;
use ImageUploader\Model\Table\UploadsTable;
use Saito\Exception\SaitoForbiddenException;
use Saito\User\CurrentUser\CurrentUserInterface;
use Saito\User\Permission\ResourceAI;

/**
 * Upload Controller
 *
 * @property CurrentUserInterface $CurrentUser
 * @property UploadsTable $Uploads
 */
class UploadsController extends ApiAppController
{
    public $helpers = ['ImageUploader.ImageUploader'];

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        parent::initialize();
        $this->loadModel('Users');
    }

    /**
     * View uploads
     *
     * @return void
     */
    public function index()
    {
        $userId = (int)$this->getRequest()->getQuery('id');
        /** @var User */
        $user = $this->Users->get($userId);
        $permission = $this->CurrentUser->permission(
            'saito.plugin.uploader.view',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId())
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to index uploads of "%s".', $userId),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        $images = $this->Uploads->find()
            ->where(['user_id' => $userId])
            ->order(['id' => 'DESC'])
            ->all();
        $this->set('images', $images);
    }

    /**
     * Adds a new upload
     *
     * @return void
     */
    public function add()
    {
        $submitted = $this->request->getData('upload.0.file');
        if (!is_array($submitted)) {
            throw new GenericApiException(__d('image_uploader', 'add.failure'));
        }

        $userId = (int)$this->getRequest()->getData('userId');
        /** @var User */
        $user = $this->Users->get($userId);
        $permission = $this->CurrentUser->permission(
            'saito.plugin.uploader.add',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId())
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to add uploads for "%s".', $userId),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        // Determine extension from server-detected MIME type, never from user-supplied filename
        try {
            $mime = MimeType::get($submitted['tmp_name'], $submitted['name']);
        } catch (\RuntimeException $e) {
            throw new GenericApiException(__d('image_uploader', 'add.failure'));
        }
        $ext = self::mimeToExtension($mime);
        if ($ext === null) {
            throw new GenericApiException(__d('image_uploader', 'add.failure'));
        }
        $name = $this->CurrentUser->getId() .
                '_' .
                substr(Security::hash($submitted['name'], 'sha256'), 32) .
                '.' .
                $ext;
        $data = [
            'document' => $submitted,
            'name' => $name,
            'title' => $submitted['name'],
            'size' => $submitted['size'],
            'user_id' => $userId,
        ];
        $document = $this->Uploads->newEntity($data);

        if (!$this->Uploads->save($document)) {
            $errors = $document->getErrors();
            $msg = $errors ? current(current($errors)) : null;
            throw new GenericApiException($msg);
        }

        $this->set('image', $document);
    }

    /**
     * Maps a server-detected MIME type to a safe, whitelisted file extension.
     *
     * Returns null for any MIME type not in the whitelist, causing the upload to be rejected.
     *
     * @param string $mime Server-determined MIME type
     * @return string|null Safe extension, or null if the type is not allowed
     */
    private static function mimeToExtension(string $mime): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/ogg'  => 'ogg',
            'video/ogg'  => 'ogv',
            'video/webm' => 'webm',
        ];

        return $map[$mime] ?? null;
    }

    /**
     * Deletes an upload
     *
     * @param int $imageId the ID of the image to delete
     * @return void
     */
    public function delete($imageId)
    {
        /** @var Upload */
        $upload = $this->Uploads->get($imageId, ['contain' => ['Users']]);
        $permission = $this->CurrentUser->permission(
            'saito.plugin.uploader.delete',
            (new ResourceAI())->onRole($upload->user->getRole())->onOwner($upload->user->getId())
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to delete upload "%s".', $imageId),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if (!$this->Uploads->delete($upload)) {
            $msg = __d('image_uploader', 'delete.failure');
            throw new GenericApiException($msg);
        }

        Cache::delete((string)$imageId, 'uploadsThumbnails');

        $this->autoRender = false;
        $this->response = $this->response->withStatus(204);
    }
}
