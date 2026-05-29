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

use Cake\Form\Schema;
use Cake\Validation\Validator;

class ContactFormOwner extends ContactForm
{

    /**
     * {@inheritdoc}
     *
     * @param \Cake\Form\Schema $schema The schema to customize.
     * @return \Cake\Form\Schema The schema to use.
     */
    protected function _buildSchema(Schema $schema): \Cake\Form\Schema
    {
        $schema = parent::_buildSchema($schema);
        $schema->addField('sender_contact', 'string');
        $schema->addField('website', 'string');

        return $schema;
    }

    /**
     * {@inheritdoc}
     *
     * @param \Cake\Validation\Validator $validator The validator to customize.
     * @return \Cake\Validation\Validator The validator to use.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator = parent::validationDefault($validator);
        $validator
            ->notEmptyString('sender_contact')
            ->add('sender_contact', [
                'isEmail' => [
                    'rule' => ['email', true],
                    'message' => __('error_email_not-valid'),
                ],
            ])
            ->allowEmptyString('website')
            ->add('website', [
                'honeypot' => [
                    'rule' => function ($value) {
                        return empty($value);
                    },
                    'message' => __('error_subject_empty'),
                ],
            ]);

        return $validator;
    }
}
