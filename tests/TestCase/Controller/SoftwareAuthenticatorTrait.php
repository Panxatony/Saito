<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller;

use OpenSSLAsymmetricKey;
use RuntimeException;
use Saito\User\Auth\WebauthnService;

/**
 * A passkey device in software, for tests that need a ceremony to succeed.
 *
 * An ES256 key pair and the two messages a device produces with it: the
 * attestation that answers a registration and the assertion that answers a
 * login. Everything is written out byte by byte rather than produced with the
 * library's encoders, so a test never passes merely because the library agrees
 * with itself. Verifying stays the library's job.
 */
trait SoftwareAuthenticatorTrait
{
    /**
     * A fresh P-256 key, as a platform authenticator would hold one.
     *
     * @return \OpenSSLAsymmetricKey
     */
    private function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('Could not generate an EC key for the test authenticator.');
        }

        return $key;
    }

    /**
     * The public half of a key as a COSE key.
     *
     * A five-entry CBOR map (kty EC2, alg ES256, crv P-256, x, y): ten fixed
     * bytes, the first coordinate, three more, the second. 77 bytes.
     *
     * @param \OpenSSLAsymmetricKey $key the authenticator's key
     * @return string
     */
    private function coseKey(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Could not read the EC key of the test authenticator.');
        }
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;
    }

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * What a device answers a registration challenge with.
     *
     * Authenticator data is the hash of the relying-party id, a flags byte,
     * the signature counter and the attested credential: sixteen bytes of
     * AAGUID (zeros: we ask for no attestation), the credential id with its
     * length, and the public key. It travels in a CBOR map with the format
     * `none` and an empty statement. Nothing is signed: with `none` the
     * server takes the key on the strength of the challenge and the origin
     * in the client data alone.
     *
     * @param \OpenSSLAsymmetricKey $key the key being registered
     * @param string $challenge the challenge as the server sent it (base64url)
     * @param string $rawId the credential id the device chose
     * @param string $origin the origin the browser reports
     * @param int $flags 0x45 = user present, user verified, credential attached
     * @return string the credential as a browser serialises it
     */
    private function attestationFor(
        OpenSSLAsymmetricKey $key,
        string $challenge,
        string $rawId,
        string $origin,
        int $flags = 0x45,
    ): string {
        $clientData = (string)json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
        $authenticatorData = hash('sha256', (new WebauthnService())->relyingPartyId(), true)
            . chr($flags)
            . pack('N', 0)
            . str_repeat("\0", 16)
            . pack('n', strlen($rawId)) . $rawId
            . $this->coseKey($key);

        // {"fmt": "none", "attStmt": {}, "authData": <bytes>}
        $attestationObject = "\xA3"
            . "\x63fmt" . "\x64none"
            . "\x67attStmt" . "\xA0"
            . "\x68authData" . "\x59" . pack('n', strlen($authenticatorData)) . $authenticatorData;

        return (string)json_encode([
            'id' => $this->base64url($rawId),
            'rawId' => $this->base64url($rawId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64url($clientData),
                'attestationObject' => $this->base64url($attestationObject),
            ],
        ]);
    }

    /**
     * What a device answers a login challenge with.
     *
     * Authenticator data is the hash of the relying-party id, a flags byte
     * (user present and user verified) and the signature counter; the
     * signature covers that plus the hash of the client data, which is where
     * the challenge and the origin are.
     *
     * @param \OpenSSLAsymmetricKey $key the key that signs
     * @param string $challenge the challenge as the server sent it (base64url)
     * @param string $rawId the credential id
     * @param int $userId the account the credential belongs to
     * @param string $origin the origin the browser reports
     * @param int $counter the authenticator's signature counter
     * @return string the credential as a browser serialises it
     */
    private function assertionFor(
        OpenSSLAsymmetricKey $key,
        string $challenge,
        string $rawId,
        int $userId,
        string $origin,
        int $counter = 1,
    ): string {
        $service = new WebauthnService();
        $clientData = (string)json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
        $authenticatorData = hash('sha256', $service->relyingPartyId(), true) . "\x05" . pack('N', $counter);

        $signature = '';
        $signed = openssl_sign(
            $authenticatorData . hash('sha256', $clientData, true),
            $signature,
            $key,
            OPENSSL_ALGO_SHA256,
        );
        if (!$signed) {
            throw new RuntimeException('The test authenticator could not sign.');
        }

        return (string)json_encode([
            'id' => $this->base64url($rawId),
            'rawId' => $this->base64url($rawId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64url($clientData),
                'authenticatorData' => $this->base64url($authenticatorData),
                'signature' => $this->base64url($signature),
                'userHandle' => $this->base64url($service->userHandle($userId)),
            ],
        ]);
    }
}
