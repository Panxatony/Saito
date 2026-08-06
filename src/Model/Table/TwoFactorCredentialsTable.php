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

use App\Model\Entity\TwoFactorCredential;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\Utility\Security;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;

/**
 * The TOTP second factor: enrolment, confirmation and verification.
 *
 * Everything that touches the shared secret lives here, so there is one place
 * to read when asking "can this be turned into valid codes by somebody with the
 * database?" — the answer being no, because the secret is encrypted with the
 * application salt before it is stored and only ever decrypted in memory to
 * check a code.
 *
 * ## The two states, and why the distinction matters
 *
 * A row with `confirmed_at` null is an enrolment somebody started and did not
 * finish. It must never gate a login: if it did, closing the browser tab
 * half-way through enrolment would lock the account behind a secret nobody
 * saved. Only {@see self::isEnabledFor()} — confirmed rows — is allowed to
 * decide that an account needs a second factor.
 */
class TwoFactorCredentialsTable extends Table
{
    /**
     * How far a code may drift, in **seconds**.
     *
     * otphp's third `verify()` argument is a leeway in seconds, not a count of
     * periods, and it must be smaller than the period (30). 29 is the largest
     * it accepts and yields the conventional "one period either side" — a phone
     * whose clock is half a minute out still works, which is the common case
     * this exists for. Measured: at 15 the previous period is accepted, at 29
     * the next one is too, at 30 it throws.
     *
     * @var int
     */
    public const LEEWAY_SECONDS = 29;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('two_factor_credentials');
        $this->setEntityClass(TwoFactorCredential::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * Start enrolment: mint a fresh secret and store it unconfirmed.
     *
     * Replaces any earlier attempt for the account, so restarting enrolment
     * cannot leave a stale secret behind that an old QR code still matches.
     * Returns the secret in plaintext because this is the one moment it may be
     * shown — after this it exists only encrypted.
     *
     * @param int $userId account enrolling
     * @return string the base32 secret, for the QR code and manual entry
     */
    public function beginEnrolment(int $userId): string
    {
        $this->deleteAll(['user_id' => $userId]);

        // 160 bits, the RFC 4226 recommendation: 32 base32 characters, short
        // enough to type by hand when a camera is not an option.
        $secret = rtrim(Base32::encodeUpper(Security::randomBytes(20)), '=');

        $entity = $this->newEmptyEntity();
        $entity->set('user_id', $userId);
        $entity->set('type', 'totp');
        $entity->set('secret', $this->encrypt($secret));
        $entity->set('confirmed_at', null);
        $this->saveOrFail($entity);

        return $secret;
    }

    /**
     * Finish enrolment: the member proves they can produce a code.
     *
     * @param int $userId account enrolling
     * @param string $code the six digits from their app
     * @return bool whether the code matched and the credential is now live
     */
    public function confirmEnrolment(int $userId, string $code): bool
    {
        $credential = $this->pendingFor($userId);
        if ($credential === null) {
            return false;
        }
        if (!$this->verifyAgainst($credential, $code)) {
            return false;
        }

        $credential->set('confirmed_at', new DateTime());
        $this->saveOrFail($credential);

        return true;
    }

    /**
     * Does this account have a *confirmed* second factor?
     *
     * The question the login flow asks. Unconfirmed rows deliberately answer no
     * — see the class comment.
     *
     * @param int $userId account
     * @return bool
     */
    public function isEnabledFor(int $userId): bool
    {
        return $this->find()
            ->where(['user_id' => $userId, 'confirmed_at IS NOT' => null])
            ->count() > 0;
    }

    /**
     * Check a code against a confirmed credential.
     *
     * @param int $userId account
     * @param string $code the six digits
     * @return bool
     */
    public function verifyCode(int $userId, string $code): bool
    {
        /** @var TwoFactorCredential|null $credential */
        $credential = $this->find()
            ->where(['user_id' => $userId, 'confirmed_at IS NOT' => null])
            ->first();
        if ($credential === null) {
            return false;
        }

        return $this->verifyAgainst($credential, $code);
    }

    /**
     * Can this account's stored secret still be read at all?
     *
     * It cannot when the application salt has changed since enrolment — the
     * secret is encrypted with a key derived from it, so rotating the salt (or
     * restoring a database into an installation with a different one) turns
     * every enrolled credential into ciphertext nobody holds the key to.
     *
     * This is asked so the member can be told *that*, instead of being shown
     * "wrong code" for a code that is perfectly correct and can never work.
     * Found the hard way: a credential written with one salt and checked with
     * another produced exactly that dead end, with recovery codes — hashed, not
     * encrypted — still working and so disguising the cause.
     *
     * @param int $userId account
     * @return bool false when there is a confirmed credential that cannot be read
     */
    public function isReadableFor(int $userId): bool
    {
        /** @var TwoFactorCredential|null $credential */
        $credential = $this->find()
            ->where(['user_id' => $userId, 'confirmed_at IS NOT' => null])
            ->first();
        if ($credential === null) {
            // Nothing enrolled is not the same as unreadable.
            return true;
        }

        return $this->decrypt((string)$credential->get('secret')) !== null;
    }

    /**
     * Turn the second factor off for an account — the member's own choice, or
     * an administrator letting somebody back in who lost their device.
     *
     * @param int $userId account
     * @return void
     */
    public function disableFor(int $userId): void
    {
        $this->deleteAll(['user_id' => $userId]);
    }

    /**
     * The `otpauth://` URI an authenticator app scans.
     *
     * The issuer and label are what the member sees in their app's list, so
     * they carry the forum's name rather than a host name.
     *
     * @param string $secret the plaintext secret from {@see self::beginEnrolment()}
     * @param string $username whose account
     * @return string
     */
    public function provisioningUri(string $secret, string $username): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($username);
        // otphp refuses an empty issuer, and a forum whose name has not been
        // set would otherwise fail at enrolment rather than in configuration.
        $forum = trim((string)Configure::read('Saito.Settings.forum_name'));
        $totp->setIssuer($forum !== '' ? $forum : 'Saito');

        return $totp->getProvisioningUri();
    }

    /**
     * The unconfirmed credential for an account, if enrolment is under way.
     *
     * @param int $userId account
     * @return TwoFactorCredential|null
     */
    public function pendingFor(int $userId): ?TwoFactorCredential
    {
        /** @var TwoFactorCredential|null $credential */
        $credential = $this->find()
            ->where(['user_id' => $userId, 'confirmed_at IS' => null])
            ->first();

        return $credential;
    }

    /**
     * @param TwoFactorCredential $credential the stored credential
     * @param string $code the six digits
     * @return bool
     */
    private function verifyAgainst(TwoFactorCredential $credential, string $code): bool
    {
        $code = trim($code);
        // Authenticator apps and password managers happily paste "123 456".
        $code = (string)preg_replace('/\s+/', '', $code);
        if ($code === '') {
            return false;
        }

        $secret = $this->decrypt((string)$credential->get('secret'));
        if ($secret === null) {
            return false;
        }

        return TOTP::createFromSecret($secret)->verify($code, null, self::LEEWAY_SECONDS);
    }

    /**
     * @param string $secret plaintext base32 secret
     * @return string storable ciphertext
     */
    private function encrypt(string $secret): string
    {
        return base64_encode(Security::encrypt($secret, $this->key()));
    }

    /**
     * @param string $stored ciphertext as stored
     * @return string|null the secret, or null if it cannot be read
     */
    private function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false) {
            return null;
        }
        // Returns null on a failed decrypt (wrong key, tampered value) rather
        // than throwing — a credential that cannot be read must fail the login,
        // not the request.
        return Security::decrypt($raw, $this->key());
    }

    /**
     * The encryption key: 32 raw bytes derived from the application salt.
     *
     * Not the salt itself, for two reasons. `Security::encrypt()` demands at
     * least 256 bits and a salt shorter than that would throw at enrolment
     * — on some installations, and only there, which is the worst way to find
     * out. And deriving with a label keeps this key distinct from every other
     * use the salt is put to, so one of them leaking does not hand over the
     * others.
     *
     * @return string
     */
    private function key(): string
    {
        return hash('sha256', 'saito.2fa.secret.v1|' . Security::getSalt(), true);
    }
}
