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

use App\Model\Entity\TwoFactorRecoveryCode;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\Utility\Security;

/**
 * Single-use recovery codes — the way back in when the phone is gone.
 *
 * Without these, a lost device means an administrator has to intervene, and on
 * a forum whose admin is one person that can mean days locked out. They are
 * stored the way passwords are, hashed with bcrypt, because that is what they
 * are: a credential that alone gets you past the second factor.
 *
 * A spent code is stamped, not deleted. It costs one row and buys two things:
 * "you have three left" stays answerable, and a code presented twice is
 * distinguishable from one that was never issued — worth knowing when reading
 * a log after somebody's account misbehaves.
 */
class TwoFactorRecoveryCodesTable extends Table
{
    /**
     * How many codes an enrolment hands out. Ten is the common figure — enough
     * that losing a printout is not fatal, few enough to write on one card.
     *
     * @var int
     */
    public const CODE_COUNT = 10;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('two_factor_recovery_codes');
        $this->setEntityClass(TwoFactorRecoveryCode::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * Replace an account's codes with a fresh set.
     *
     * Returns them in plaintext — the only time they exist that way. Whoever
     * calls this owes the member a screen showing them, because there is no
     * second chance to read them out of the database.
     *
     * @param int $userId account
     * @return list<string> the new codes, to show once
     */
    public function issueFor(int $userId): array
    {
        $this->deleteAll(['user_id' => $userId]);

        $hasher = new DefaultPasswordHasher();
        $codes = [];
        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $code = $this->generateCode();
            $codes[] = $code;

            $entity = $this->newEmptyEntity();
            $entity->set('user_id', $userId);
            $entity->set('code_hash', $hasher->hash($code));
            $entity->set('used_at', null);
            $this->saveOrFail($entity);
        }

        return $codes;
    }

    /**
     * Spend a code, if it is one of this account's unused ones.
     *
     * Every unused hash is checked rather than looked up, because a bcrypt hash
     * cannot be searched for — that is the point of it. Ten comparisons is the
     * cost of not storing recovery codes in a form a database read could use.
     *
     * @param int $userId account
     * @param string $code what the member typed
     * @return bool whether it was valid and is now spent
     */
    public function consume(int $userId, string $code): bool
    {
        $code = strtolower(trim($code));
        // Shown grouped ("abcd-efgh"); accept it typed either way.
        $code = (string)preg_replace('/[^a-z0-9]/', '', $code);
        if ($code === '') {
            return false;
        }

        $hasher = new DefaultPasswordHasher();
        $rows = $this->find()
            ->where(['user_id' => $userId, 'used_at IS' => null])
            ->all();

        foreach ($rows as $row) {
            if ($hasher->check($code, (string)$row->get('code_hash'))) {
                $row->set('used_at', new DateTime());
                $this->saveOrFail($row);

                return true;
            }
        }

        return false;
    }

    /**
     * How many codes an account has left.
     *
     * @param int $userId account
     * @return int
     */
    public function remainingFor(int $userId): int
    {
        return $this->find()
            ->where(['user_id' => $userId, 'used_at IS' => null])
            ->count();
    }

    /**
     * Drop an account's codes — enrolment restarted, or the second factor
     * switched off.
     *
     * @param int $userId account
     * @return void
     */
    public function clearFor(int $userId): void
    {
        $this->deleteAll(['user_id' => $userId]);
    }

    /**
     * A code with enough entropy to be a credential and a shape somebody can
     * read off paper: ten characters from a 32-symbol alphabet is about 50
     * bits, and the alphabet leaves out the pairs that get misread by hand.
     *
     * @return string
     */
    private function generateCode(): string
    {
        // No 0/o, 1/l/i — the characters people transcribe wrongly.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
