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
use Cake\Cache\Cache;
use ImageUploader\Model\Entity\Upload;
use RuntimeException;
use Saito\Exception\SaitoForbiddenException;
use Saito\RequestUpload;
use Saito\User\Permission\ResourceAI;

/**
 * Upload Controller
 *
 * @property \Saito\User\CurrentUser\CurrentUserInterface $CurrentUser
 * @property \ImageUploader\Model\Table\UploadsTable $Uploads
 */
class UploadsController extends ApiAppController
{
    public $helpers = ['ImageUploader.ImageUploader'];

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Users = $this->fetchTable('Users');
    }

    /**
     * View uploads
     *
     * @return void
     */
    public function index(): void
    {
        $userId = (int)$this->getRequest()->getQuery('id');
        /** @var \App\Model\Entity\User */
        $user = $this->Users->get($userId);
        $permission = $this->CurrentUser->permission(
            'saito.plugin.uploader.view',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId()),
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to index uploads of "%s".', $userId),
                ['CurrentUser' => $this->CurrentUser],
            );
        }

        $images = $this->Uploads->find()
            ->where(['user_id' => $userId])
            ->orderBy(['id' => 'DESC'])
            ->all();
        $this->set('images', $images);
    }

    /**
     * Adds one or more uploads.
     *
     * The request may carry several files (`upload[0][file]`, `upload[1][file]`,
     * …). Each is stored independently: valid files are saved and returned,
     * while a file that fails (unsupported type, duplicate, too large, …) is
     * collected as an error without aborting the rest of the batch. Only when
     * *nothing* could be stored is the request treated as a failure — which
     * preserves the single-file behaviour.
     *
     * @return void
     */
    public function add(): void
    {
        $userId = (int)$this->getRequest()->getData('userId');
        /** @var \App\Model\Entity\User */
        $user = $this->Users->get($userId);
        $permission = $this->CurrentUser->permission(
            'saito.plugin.uploader.add',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId()),
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to add uploads for "%s".', $userId),
                ['CurrentUser' => $this->CurrentUser],
            );
        }

        $images = [];
        $errors = [];
        foreach ((array)$this->getRequest()->getData('upload') as $submission) {
            $file = RequestUpload::toArray($submission['file'] ?? null);
            if ($file === null || empty($file['tmp_name'])) {
                $errors[] = (string)__d('image_uploader', 'add.failure');
                continue;
            }
            try {
                $images[] = $this->saveUpload($file, $userId);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage() ?: (string)__d('image_uploader', 'add.failure');
            }
        }

        if (!$images) {
            // Nothing stored — surface the first reason, matching the previous
            // single-file failure response.
            throw new GenericApiException($errors[0] ?? (string)__d('image_uploader', 'add.failure'));
        }

        $this->set('images', $images);
        $this->set('uploadErrors', $errors);
    }

    /**
     * Validates and stores a single submitted file.
     *
     * @param array $file normalized upload (name/type/tmp_name/error/size)
     * @param int $userId owner the upload is stored for
     * @return \ImageUploader\Model\Entity\Upload the saved upload entity
     * @throws \RuntimeException with a user-facing reason if the file is rejected
     */
    private function saveUpload(array $file, int $userId): Upload
    {
        // Storage/validation now lives on the table so the session htmx editor
        // upload can reuse the exact same secure path.
        return $this->Uploads->createFromUpload($file, $userId);
    }

    /**
     * Deletes an upload
     *
     * @param int $imageId the ID of the image to delete
     * @return void
     */
    public function delete(int $imageId): void
    {
        /** @var \ImageUploader\Model\Entity\Upload */
        $upload = $this->Uploads->get($imageId, contain: ['Users']);
        $permission = $this->CurrentUser->permission(
            'saito.plugin.uploader.delete',
            (new ResourceAI())->onRole($upload->user->getRole())->onOwner($upload->user->getId()),
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to delete upload "%s".', $imageId),
                ['CurrentUser' => $this->CurrentUser],
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
