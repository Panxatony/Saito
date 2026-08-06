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

use App\Model\Entity\TwoFactorTrustedDevice;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * Devices that have already proved the second factor.
 *
 * The remember-me cookie carries no server-side state — it validates against a
 * username and a password hash — so there is no way to tell one minted before
 * an account enrolled in 2FA from one minted after, and no way to revoke
 * either. That left only a blunt answer: refuse them all for enrolled accounts,
 * which quietly took "stay signed in" away from anybody who turned the second
 * factor on. On a phone, where sessions are evicted freely, that means logging
 * in over and over.
 *
 * A row here is the missing state. It is written only after a second factor has
 * actually been proved, and its token travels in a cookie of its own; a
 * remember-me cookie is honoured for an enrolled account only when a matching,
 * unexpired row exists. Cookies from before enrolment have no row and stay
 * refused. Because the trust is now a row rather than a signature, it can be
 * withdrawn — which is what {@see self::clearFor()} is for.
 *
 * The token is 256 bits from the CSPRNG, so it is stored as a plain SHA-256
 * rather than bcrypt: bcrypt's work factor buys resistance to guessing, and
 * there is nothing here to guess. What matters is that a database read yields
 * no usable cookie, and a hash gives that.
 */
class TwoFactorTrustedDevicesTable extends Table
{
    /**
     * How long a device stays trusted, in days.
     *
     * Comfortably longer than the remember-me cookie's own ten days, so the
     * cookie is always what expires first. A device record outliving its cookie
     * grants nothing on its own — it only ever permits a cookie that is still
     * valid — whereas the reverse would log people out for no visible reason.
     *
     * @var int
     */
    public const TRUST_DAYS = 30;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('two_factor_trusted_devices');
        $this->setEntityClass(TwoFactorTrustedDevice::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * Trust a device, and hand back the token that proves it.
     *
     * Returns the token in the clear — the only time it exists that way. The
     * caller puts it in a cookie; nothing can read it back out of here.
     *
     * @param int $userId account
     * @return string the token to put in the cookie
     */
    public function issueFor(int $userId): string
    {
        $this->deleteExpired();

        $token = bin2hex(random_bytes(32));

        $entity = $this->newEmptyEntity();
        $entity->set('user_id', $userId);
        $entity->set('token_hash', $this->hash($token));
        $entity->set('expires', new DateTime('+' . self::TRUST_DAYS . ' days'));
        $this->saveOrFail($entity);

        return $token;
    }

    /**
     * Does this token still vouch for this account's device?
     *
     * The account is part of the question, not an afterthought: a token is only
     * ever an answer for the account it was issued to, so a valid token from
     * one member cannot admit a cookie naming another.
     *
     * @param int $userId account the cookie claims
     * @param string|null $token what the device cookie carried
     * @return bool
     */
    public function isTrusted(int $userId, ?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        return $this->find()
            ->where([
                'user_id' => $userId,
                'token_hash' => $this->hash($token),
                'expires >' => new DateTime(),
            ])
            ->count() > 0;
    }

    /**
     * Withdraw the trust in a single device — the one logging out.
     *
     * Logging out on a phone should not un-trust the laptop, so this takes the
     * token rather than the account.
     *
     * @param string|null $token the device cookie's token
     * @return void
     */
    public function forgetToken(?string $token): void
    {
        if (empty($token)) {
            return;
        }
        $this->deleteAll(['token_hash' => $this->hash($token)]);
    }

    /**
     * Withdraw the trust in every one of an account's devices.
     *
     * For the moments that mean the earlier proof no longer stands: the second
     * factor switched off, an administrator resetting it, the password changed.
     * After this, every device has to prove itself again.
     *
     * @param int $userId account
     * @return void
     */
    public function clearFor(int $userId): void
    {
        $this->deleteAll(['user_id' => $userId]);
    }

    /**
     * Drop rows nobody can use any more.
     *
     * Expired rows are already refused by {@see self::isTrusted()}; clearing
     * them out just keeps a table that only ever grows from doing so.
     *
     * @return void
     */
    public function deleteExpired(): void
    {
        $this->deleteAll(['expires <=' => new DateTime()]);
    }

    /**
     * @param string $token the raw token
     * @return string what gets stored
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token); // 256-bit random token, not a password skipcq: PHP-A1004
    }
}
