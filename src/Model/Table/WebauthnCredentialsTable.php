<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Model\Table;

use App\Model\Entity\WebauthnCredential;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Saito\User\Auth\WebauthnService;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * The passkeys an account has registered.
 *
 * Storage only: every decision about whether a ceremony checked out belongs to
 * {@see WebauthnService}, which owns the library. This class knows how to put a
 * verified record away and how to find one again.
 */
class WebauthnCredentialsTable extends Table
{
    /**
     * How many devices one account may register.
     *
     * Generous enough for a laptop, a phone, a tablet and a hardware key, with
     * room to replace one before removing the old. A cap at all because the
     * list is offered back as an allow-list on every login attempt, and an
     * unbounded one is an unbounded response.
     *
     * @var int
     */
    public const MAX_CREDENTIALS = 10;

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('webauthn_credentials');
        $this->setEntityClass(WebauthnCredential::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * Put a verified registration away.
     *
     * @param int $userId account
     * @param \Webauthn\CredentialRecord $record what the ceremony produced
     * @param string|null $label what the member calls this device
     * @return \App\Model\Entity\WebauthnCredential
     */
    public function store(int $userId, CredentialRecord $record, ?string $label = null): WebauthnCredential
    {
        $service = new WebauthnService();

        /** @var \App\Model\Entity\WebauthnCredential $entity */
        $entity = $this->newEmptyEntity();
        $entity->set('user_id', $userId);
        $entity->set('credential_id', $this->encodeId($record->publicKeyCredentialId));
        $entity->set('credential', $service->serializer()->serialize($record, 'json'));
        $entity->set('sign_count', $record->counter);
        $entity->set('label', $this->cleanLabel($label));
        $this->saveOrFail($entity);

        return $entity;
    }

    /**
     * Write back what a successful login changed.
     *
     * The signature counter is one reason. An authenticator that keeps one
     * increments it on every use, so a counter that has not moved — or has gone
     * backwards — is how a cloned credential announces itself, and storing it
     * back is what makes the next comparison meaningful.
     *
     * Do not read more into it than is there. Plenty of authenticators keep no
     * counter and report zero forever; Apple's Secure Enclave is one, and the
     * specification allows it, so for most passkeys this value never leaves
     * zero and nothing is being detected. `last_used_at` is the part that
     * always says something, and it is what a member reads when deciding which
     * device on the list they no longer recognise.
     *
     * @param \App\Model\Entity\WebauthnCredential $entity the row that was used
     * @param \Webauthn\CredentialRecord $record the record as the ceremony left it
     * @return void
     */
    public function recordUse(WebauthnCredential $entity, CredentialRecord $record): void
    {
        $service = new WebauthnService();

        $entity->set('credential', $service->serializer()->serialize($record, 'json'));
        $entity->set('sign_count', $record->counter);
        $entity->set('last_used_at', new DateTime());
        $this->saveOrFail($entity);
    }

    /**
     * The stored record behind a row.
     *
     * @param \App\Model\Entity\WebauthnCredential $entity row
     * @return \Webauthn\CredentialRecord
     */
    public function toRecord(WebauthnCredential $entity): CredentialRecord
    {
        $service = new WebauthnService();

        /** @var \Webauthn\CredentialRecord $record */
        $record = $service->serializer()->deserialize(
            (string)$entity->get('credential'),
            CredentialRecord::class,
            'json',
        );

        return $record;
    }

    /**
     * One account's registered passkeys, newest last.
     *
     * @param int $userId account
     * @return list<\App\Model\Entity\WebauthnCredential>
     */
    public function listFor(int $userId): array
    {
        /** @var list<\App\Model\Entity\WebauthnCredential> $rows */
        $rows = $this->find()
            ->where(['user_id' => $userId])
            ->orderBy(['id' => 'ASC'])
            ->all()
            ->toList();

        return $rows;
    }

    /**
     * Find the row a browser says it used — but only within one account.
     *
     * Scoped to the account by design: without it, a passkey belonging to
     * anybody would be looked up happily and only the later handle check would
     * catch it. Two guards, because this one is cheap.
     *
     * @param int $userId account the pending login belongs to
     * @param string $credentialId base64url, as it came from the browser
     * @return \App\Model\Entity\WebauthnCredential|null
     */
    public function findForUser(int $userId, string $credentialId): ?WebauthnCredential
    {
        /** @var \App\Model\Entity\WebauthnCredential|null $row */
        $row = $this->find()
            ->where(['user_id' => $userId, 'credential_id' => $credentialId])
            ->first();

        return $row;
    }

    /**
     * The account's credentials as the browser wants them: an allow-list for a
     * login, or an exclude-list so a device does not register itself twice.
     *
     * @param int $userId account
     * @return list<\Webauthn\PublicKeyCredentialDescriptor>
     */
    public function descriptorsFor(int $userId): array
    {
        $descriptors = [];
        foreach ($this->listFor($userId) as $row) {
            $descriptors[] = PublicKeyCredentialDescriptor::create(
                'public-key',
                $this->decodeId((string)$row->get('credential_id')),
            );
        }

        return $descriptors;
    }

    /**
     * Does this account have a passkey at all?
     *
     * @param int $userId account
     * @return bool
     */
    public function hasAnyFor(int $userId): bool
    {
        return $this->find()->where(['user_id' => $userId])->count() > 0;
    }

    /**
     * Drop one device — the member removed it, or replaced it.
     *
     * @param int $userId account, so a request cannot name somebody else's row
     * @param int $id the row
     * @return bool whether a row went away
     */
    public function removeFor(int $userId, int $id): bool
    {
        return $this->deleteAll(['user_id' => $userId, 'id' => $id]) > 0;
    }

    /**
     * Drop an account's passkeys — the second factor was switched off, or an
     * administrator reset it.
     *
     * @param int $userId account
     * @return void
     */
    public function clearFor(int $userId): void
    {
        $this->deleteAll(['user_id' => $userId]);
    }

    /**
     * Base64url, the encoding the browser speaks.
     *
     * @param string $raw raw bytes
     * @return string
     */
    public function encodeId(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param string $encoded base64url
     * @return string raw bytes
     */
    public function decodeId(string $encoded): string
    {
        return (string)base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    /**
     * A device name the member typed, cut to something a list can show.
     *
     * @param string|null $label what they typed
     * @return string|null
     */
    private function cleanLabel(?string $label): ?string
    {
        $label = trim((string)$label);
        if ($label === '') {
            return null;
        }

        return mb_substr($label, 0, 100);
    }
}
