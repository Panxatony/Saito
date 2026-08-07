<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\User\Auth;

use Cake\Core\Configure;
use Cake\Utility\Security;
use RuntimeException;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The WebAuthn ceremonies, wrapped so the rest of Saito never sees the library.
 *
 * A passkey is a second factor that cannot be phished. The operating system
 * checks the fingerprint or the face **on the device** and hands us a
 * signature; no biometric ever reaches the forum, and none can. That is the
 * reason this is worth carrying a dependency for rather than a nicety.
 *
 * Two things are worth knowing before reading further.
 *
 * The relying-party id is the **domain**, and it is baked into every credential
 * at registration. A passkey created on `saito8-alpha.example.com` will not
 * work on `example.com`, and cannot be made to — that is the anti-phishing
 * property doing its job. Each installation therefore needs its own
 * registration, which surprises people exactly once.
 *
 * And this is emphatically *not* a login. It hangs off the second step of the
 * existing two-phase login: the password has already been checked, and the
 * pending marker in the session is what says so. Passwordless sign-in is a
 * different feature with its own lockout risks, deliberately out of scope.
 */
class WebauthnService
{
    /**
     * How long a challenge stays redeemable, in seconds.
     *
     * Short: a challenge is a one-shot token against replay, and the ceremony
     * it belongs to is a button press away. This is well inside the
     * five-minute life of the pending-login marker it sits within.
     *
     * @var int
     */
    public const CHALLENGE_TTL = 120;

    private ?SerializerInterface $serializer = null;

    /**
     * The domain credentials are bound to.
     *
     * Taken from the configured base URL rather than the request's Host header:
     * a header is attacker-controlled, and letting it choose the relying-party
     * id would let somebody register a credential against a domain of their
     * choosing.
     *
     * @return string
     */
    public function relyingPartyId(): string
    {
        $host = parse_url((string)Configure::read('App.fullBaseUrl'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * The origin the ceremony must have happened on.
     *
     * @return string
     */
    private function origin(): string
    {
        return rtrim((string)Configure::read('App.fullBaseUrl'), '/');
    }

    /**
     * A stable, opaque handle for an account.
     *
     * Not the user id and not the name: the handle is stored on the
     * authenticator and may be readable from it, so it must not identify the
     * member to anybody who picks up the device. Derived rather than stored so
     * it survives a lost credentials table, and salted so it cannot be
     * recomputed from a guessed id alone.
     *
     * @param int $userId account
     * @return string 32 raw bytes
     */
    public function userHandle(int $userId): string
    {
        return hash_hmac('sha256', 'saito.webauthn.handle|' . $userId, Security::getSalt(), true);
    }

    /**
     * The library's serializer, which is also how credentials are stored.
     *
     * Keeping the library's own representation rather than mapping the record
     * onto columns by hand: the record carries a trust path, an AAGUID, backup
     * flags and a COSE public key, and hand-mapping those is how a subtle
     * verification bug gets written.
     *
     * @return \Symfony\Component\Serializer\SerializerInterface
     */
    public function serializer(): SerializerInterface
    {
        if ($this->serializer === null) {
            $this->serializer = (new WebauthnSerializerFactory(
                AttestationStatementSupportManager::create(),
            ))->create();
        }

        return $this->serializer;
    }

    /**
     * Options for registering a new passkey.
     *
     * `excludeCredentials` carries what the account already has, so an
     * authenticator that is registered refuses to register twice instead of
     * silently making a second credential nobody asked for.
     *
     * @param int $userId account
     * @param string $username shown by the operating system's prompt
     * @param list<\Webauthn\PublicKeyCredentialDescriptor> $existing already-registered credentials
     * @return \Webauthn\PublicKeyCredentialCreationOptions
     */
    public function creationOptions(
        int $userId,
        string $username,
        array $existing = [],
    ): PublicKeyCredentialCreationOptions {
        $user = PublicKeyCredentialUserEntity::create(
            $username,
            $this->userHandle($userId),
            $username,
        );

        return PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(
                (string)Configure::read('Saito.Settings.forum_name') ?: 'Saito',
                $this->relyingPartyId(),
            ),
            $user,
            random_bytes(32),
            [
                // ES256 first, then RS256: between them they cover every
                // platform authenticator in circulation.
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                // The device in the member's hand, not a roaming key they might
                // not own. A hardware key still works through the same flow if
                // the browser offers one.
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                // Required, not preferred: without user verification this is
                // "something you have" only, and the point of the exercise is a
                // factor the device has actually checked somebody for.
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED,
            ),
            // No attestation. It would tell us the make and model of the
            // authenticator, which we have no use for and which identifies the
            // member's hardware — data not worth collecting.
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $existing,
            timeout: self::CHALLENGE_TTL * 1000,
        );
    }

    /**
     * Options for confirming a login with an already-registered passkey.
     *
     * @param list<\Webauthn\PublicKeyCredentialDescriptor> $allowed the account's credentials
     * @return \Webauthn\PublicKeyCredentialRequestOptions
     */
    public function requestOptions(array $allowed): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            rpId: $this->relyingPartyId(),
            allowCredentials: $allowed,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: self::CHALLENGE_TTL * 1000,
        );
    }

    /**
     * Check what the browser returned from a registration and turn it into the
     * record to store.
     *
     * @param string $json the credential as the browser serialised it
     * @param \Webauthn\PublicKeyCredentialCreationOptions $options the options it answers
     * @return \Webauthn\CredentialRecord
     * @throws \Throwable when anything about the ceremony does not check out
     */
    public function verifyRegistration(string $json, PublicKeyCredentialCreationOptions $options): CredentialRecord
    {
        $credential = $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new RuntimeException('Not a registration response.', 1786060001);
        }

        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin()]);

        return AuthenticatorAttestationResponseValidator::create($factory->creationCeremony())
            ->check($response, $options, $this->relyingPartyId());
    }

    /**
     * Check what the browser returned from a login confirmation.
     *
     * Returns the updated record: the signature counter moves, and storing it
     * back is what makes a cloned authenticator detectable.
     *
     * @param string $json the assertion as the browser serialised it
     * @param \Webauthn\PublicKeyCredentialRequestOptions $options the options it answers
     * @param \Webauthn\CredentialRecord $record the stored credential it claims to be
     * @param int $userId the account the pending login belongs to
     * @return \Webauthn\CredentialRecord
     * @throws \Throwable when anything about the ceremony does not check out
     */
    public function verifyAssertion(
        string $json,
        PublicKeyCredentialRequestOptions $options,
        CredentialRecord $record,
        int $userId,
    ): CredentialRecord {
        $credential = $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new RuntimeException('Not an assertion response.', 1786060002);
        }

        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin()]);

        return AuthenticatorAssertionResponseValidator::create($factory->requestCeremony())
            ->check(
                $record,
                $response,
                $options,
                $this->relyingPartyId(),
                // Pinning the handle here is what stops one member's passkey
                // from completing another member's pending login.
                $this->userHandle($userId),
            );
    }
}
