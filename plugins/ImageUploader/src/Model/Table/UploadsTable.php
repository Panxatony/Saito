<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace ImageUploader\Model\Table;

use App\Lib\Model\Table\AppTable;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\I18n\Number;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validation;
use Cake\Validation\Validator;
use claviska\SimpleImage;
use ImageUploader\Lib\MimeType;
use ImageUploader\Model\Entity\Upload;

/**
 * Uploads
 *
 * Indeces:
 * - user_id, title - Combined used for uniqueness test. User_id for user's
 *   upload overview page.
 */
class UploadsTable extends AppTable
{
    /**
     * Max filename length.
     *
     * Constrained to 191 due to InnoDB index max-length on MySQL 5.6.
     */
    public const FILENAME_MAXLENGTH = 191;

    protected $UploaderConfig;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        $this->addBehavior('Timestamp');
        $this->setEntityClass(Upload::class);

        $this->belongsTo('Users', ['foreignKey' => 'user_id']);
        $this->UploaderConfig = Configure::read('Saito.Settings.uploader');
    }

    /**
     * {@inheritDoc}
     */
    public function validationDefault(Validator $validator): \Cake\Validation\Validator
    {
        $validator
            ->add('id', 'valid', ['rule' => 'numeric'])
            ->allowEmptyString('id', 'create')
            ->notBlank('name')
            ->notBlank('size')
            ->notBlank('type')
            ->notBlank('user_id')
            ->requirePresence(['name', 'size', 'type', 'user_id'], 'create');

        $validator->add(
            'document',
            [
                'file' => [
                    'rule' => [$this, 'validateFile'],
                ],
            ]
        );

        $validator->add(
            'title',
            [
                'maxLength' => [
                    'rule' => ['maxLength', self::FILENAME_MAXLENGTH],
                    'message' => __('vld.uploads.title.maxlength', self::FILENAME_MAXLENGTH),
                ],
            ]
        );

        return $validator;
    }

    /**
     * {@inheritDoc}
     */
    public function buildRules(RulesChecker $rules): \Cake\ORM\RulesChecker
    {
        $nMax = $this->UploaderConfig->getMaxNumberOfUploadsPerUser();
        $rules->add(
            function (Upload $entity, array $options) use ($nMax) {
                $count = $this->findByUserId($entity->get('user_id'))->count();

                return $count < $nMax;
            },
            'maxAllowedUploadsPerUser',
            [
                'errorField' => 'user_id',
                'message' => __d('image_uploader', 'validation.error.maxNumberOfItems', $nMax),
            ]
        );

        // check that user exists
        $rules->add($rules->existsIn('user_id', 'Users'));

        // check that same user can't have two items with the same name
        $rules->add(
            $rules->isUnique(
                // Don't use a identifier like "name" which changes (jpg->png).
                ['title', 'user_id'],
                __d('image_uploader', 'validation.error.fileExists')
            )
        );

        return $rules;
    }

    /**
     * {@inheritDoc}
     */
    public function beforeMarshal(Event $event, \ArrayObject $data)
    {
        if (!empty($data['document'])) {
            /// Set mime/type by what is determined on the server about the file.
            $data['type'] = MimeType::get($data['document']['tmp_name'], $data['name']);
            $data['document']['type'] = $data['type'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function beforeSave(\Cake\Event\EventInterface $event, Upload $entity, \ArrayObject $options)
    {
        if (!$entity->isDirty('name') && !$entity->isDirty('document')) {
            return true;
        }
        try {
            $this->moveUpload($entity);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function beforeDelete(\Cake\Event\EventInterface $event, Upload $entity, \ArrayObject $options)
    {
        $filePath = $entity->get('file');
        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    /**
     * Puts uploaded file into upload folder
     *
     * @param Upload $entity upload
     * @return void
     */
    private function moveUpload(Upload $entity): void
    {
        $filePath = $entity->get('file');
        try {
            $tmpName = $entity->get('document')['tmp_name'];
            if (!file_exists($tmpName)) {
                throw new \RuntimeException('Uploaded file not found.');
            }

            if (!copy($tmpName, $filePath)) {
                throw new \RuntimeException('Uploaded file could not be moved');
            }

            $mime = mime_content_type($filePath) ?: '';
            switch ($mime) {
                case 'image/png':
                    $filePath = $this->convertToJpeg($filePath);
                    $entity->set('type', mime_content_type($filePath) ?: '');
                    // fall through: png is further processed as jpeg
                    // no break
                case 'image/jpeg':
                    $this->fixOrientation($filePath);
                    $this->resize($filePath, $this->UploaderConfig->getMaxResize());
                    $entity->set('size', filesize($filePath));
                    break;
                default:
            }

            $entity->set('name', basename($filePath));
        } catch (\Throwable $e) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            throw new \RuntimeException('Moving uploaded file failed.');
        }
    }

    /**
     * Convert image file to jpeg
     *
     * @param string $filePath path to non-jpeg image
     * @return string path to jpeg file
     */
    private function convertToJpeg(string $filePath): string
    {
        $jpegPath = dirname($filePath) . DS . pathinfo($filePath, PATHINFO_FILENAME) . '.jpg';

        try {
            (new SimpleImage())
                ->fromFile($filePath)
                ->toFile($jpegPath, 'image/jpeg', 100);
        } catch (\Throwable $e) {
            if (file_exists($jpegPath)) {
                unlink($jpegPath);
            }
            throw new \RuntimeException('Converting file to jpeg failed.');
        } finally {
            unlink($filePath);
        }

        return $jpegPath;
    }

    /**
     * Fix image orientation according to image exif data
     *
     * @param string $filePath path to image file
     * @return void
     */
    private function fixOrientation(string $filePath): void
    {
        (new SimpleImage())
            ->fromFile($filePath)
            ->autoOrient()
            ->toFile($filePath, null, 100);
    }

    /**
     * Resizes a file
     *
     * @param string $filePath path to file to resize
     * @param int $target size in bytes
     * @return void
     */
    private function resize(string $filePath, int $target): void
    {
        $size = filesize($filePath);
        if ($size < $target) {
            return;
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException();
        }

        list($width, $height) = getimagesizefromstring($raw);
        $ratio = $size / $target;
        $qualityImprovementFactor = 1.2;
        $ratio = sqrt($ratio) / $qualityImprovementFactor;

        $newwidth = (int)($width / $ratio);
        $newheight = (int)($height / $ratio);
        $destination = imagecreatetruecolor($newwidth, $newheight);
        if ($destination === false) {
            throw new \RuntimeException();
        }

        $source = imagecreatefromstring($raw);
        if ($source === false) {
            throw new \RuntimeException();
        }
        $success = imagecopyresampled($destination, $source, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
        if ($success === false) {
            throw new \RuntimeException();
        }

        $type = mime_content_type($filePath) ?: '';
        switch ($type) {
            case 'image/jpeg':
                imagejpeg(
                    $destination,
                    $filePath,
                    $this->UploaderConfig->getJpegCompressionFactor()
                );
                break;
            case 'image/png':
                imagepng($destination, $filePath);
                break;
            default:
                throw new \RuntimeException();
        }
    }

    /**
     * Validate file by size
     *
     * @param mixed $check value
     * @param array $context context
     * @return string|bool
     */
    public function validateFile($check, array $context)
    {
        /** @var \ImageUploader\Lib\UploaderConfig */
        $UploaderConfig = Configure::read('Saito.Settings.uploader');

        /// Check file type
        if (!$UploaderConfig->hasType($check['type'])) {
            return __d('image_uploader', 'validation.error.mimeType', $check['type']);
        }

        /// Check file size
        $size = $UploaderConfig->getSize($check['type']);
        $filePath = $check instanceof \Psr\Http\Message\UploadedFileInterface
            ? $check->getStream()->getMetadata('uri')
            : ($check['tmp_name'] ?? null);
        if (!Validation::fileSize($filePath ?? $check, '<', $size)) {
            return __d(
                'image_uploader',
                'validation.error.fileSize',
                Number::toReadableSize($size)
            );
        }

        return true;
    }
}
