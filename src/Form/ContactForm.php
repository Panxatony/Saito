<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Form;

use Cake\Form\Form;
use Cake\Form\Schema;
use Cake\Validation\Validator;

class ContactForm extends Form
{

    /**
     * {@inheritdoc}
     *
     * @param \Cake\Form\Schema $schema The schema to customize.
     * @return \Cake\Form\Schema The schema to use.
     */
    protected function _buildSchema(Schema $schema): \Cake\Form\Schema
    {
        return $schema
            ->addField('subject', 'string')
            ->addField('text', ['type' => 'text'])
            ->addField('cc', ['type' => 'boolean']);
    }

    /**
     * {@inheritdoc}
     *
     * @param \Cake\Validation\Validator $validator The validator to customize.
     * @return \Cake\Validation\Validator The validator to use.
     */
    public function validationDefault(Validator $validator): Validator
    {
        // No line breaks in a subject. It becomes a mail header, and a header
        // ends at the first CRLF -- everything after would be read as a header
        // of its own (see SaitoEmailComponent::sanitizeHeaderValue(), which
        // removes them regardless; this is here so a person gets an error
        // rather than having their text silently altered).
        $validator->add('subject', 'noLineBreaks', [
            'rule' => fn($value) => is_string($value)
                && preg_match('/[\r\n\0]/', $value) !== 1,
            'message' => __('error_subject_linebreak'),
        ]);

        return $validator->notEmptyString('subject', __('error_subject_empty'));
    }
}
