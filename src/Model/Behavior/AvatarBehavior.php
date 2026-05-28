<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\Utility\Text;
use claviska\SimpleImage;
use Saito\RequestUpload;

/**
 * Handles avatar file uploads: moving files, generating thumbnails, and
 * deleting old files. Replaces the davidyell/proffer dependency.
 *
 * Configuration keys:
 *  - field:         entity field holding the upload array / stored filename
 *  - dirField:      entity field holding the storage sub-directory (user ID)
 *  - root:          absolute base upload directory
 *  - thumbnailSizes: ['<prefix>' => ['w' => int, 'h' => int], …]
 */
class AvatarBehavior extends Behavior
{
    protected array $_defaultConfig = [
        'field' => 'avatar',
        'dirField' => 'avatar_dir',
        'root' => null,
        'thumbnailSizes' => [],
    ];

    /**
     * Process avatar upload or deletion before the row is written to the DB.
     *
     * @param EventInterface $event
     * @param Entity $entity
     * @param ArrayObject $options
     * @return bool
     */
    public function beforeSave(EventInterface $event, Entity $entity, ArrayObject $options): bool
    {
        $field = $this->getConfig('field');
        $dirField = $this->getConfig('dirField');

        if (!$entity->isDirty($field)) {
            return true;
        }

        $value = $entity->get($field);

        if ($value === null) {
            $this->_deleteUserDir((string)$entity->getOriginal($dirField));
            return true;
        }

        $upload = RequestUpload::toArray($value);
        if ($upload === null || empty($upload['tmp_name'])) {
            return true;
        }

        $this->_processUpload($entity, $upload);

        return true;
    }

    /**
     * Move the uploaded file into place, generate thumbnails, and update the
     * entity fields so ORM saves the filename string (not the raw array).
     *
     * @param Entity $entity
     * @param array $upload $_FILES-style array
     * @return void
     */
    private function _processUpload(Entity $entity, array $upload): void
    {
        $field = $this->getConfig('field');
        $dirField = $this->getConfig('dirField');
        $root = $this->getConfig('root');

        $userId = (string)$entity->get('id');
        $targetDir = $root . DS . 'users' . DS . 'avatar' . DS . $userId;

        // Delete existing files (replacing an old avatar)
        $existingDir = (string)$entity->getOriginal($dirField);
        if ($existingDir !== '') {
            $this->_deleteUserDir($existingDir);
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $ext = $this->_mimeToExt($upload['type']);
        $filename = Text::uuid() . '.' . $ext;
        $targetPath = $targetDir . DS . $filename;

        // move_uploaded_file works for real HTTP uploads; rename is the fallback
        // used in test environments where files are created with file_put_contents.
        if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
            rename($upload['tmp_name'], $targetPath);
        }

        foreach ($this->getConfig('thumbnailSizes') as $prefix => $size) {
            (new SimpleImage())
                ->fromFile($targetPath)
                ->thumbnail($size['w'], $size['h'])
                ->toFile($targetDir . DS . $prefix . '_' . $filename);
        }

        $entity->set($field, $filename);
        $entity->set($dirField, $userId);
    }

    /**
     * Delete all files inside a user's avatar sub-directory.
     *
     * @param string $dir sub-directory name (the user's ID)
     * @return void
     */
    private function _deleteUserDir(string $dir): void
    {
        if ($dir === '') {
            return;
        }
        $root = $this->getConfig('root');
        $dirPath = $root . DS . 'users' . DS . 'avatar' . DS . $dir;
        if (is_dir($dirPath)) {
            foreach (glob($dirPath . DS . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Map a server-reported MIME type to a safe file extension.
     *
     * @param string $mime
     * @return string
     */
    private function _mimeToExt(string $mime): string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? 'jpg';
    }
}
