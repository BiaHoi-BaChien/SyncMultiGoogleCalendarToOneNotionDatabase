<?php

namespace App\Services\Passkey;

use App\Models\PasskeyCredential;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PasskeyService
{
    private CacheRepository $cache;

    public function __construct(
        CacheFactory $cacheFactory,
        private readonly CborDecoder $decoder,
    ) {
        $this->cache = $cacheFactory->store();
    }

    public function createRegistrationChallenge(string $userHandle, ?string $displayName = null): array
    {
        $challenge = random_bytes(32);
        $challengeId = Str::uuid()->toString();
        $displayName ??= $userHandle;
        $userId = hash('sha256', $userHandle, true);

        $payload = [
            'challenge_id' => $challengeId,
            'challenge' => Base64Url::encode($challenge),
            'user' => [
                'id' => Base64Url::encode($userId),
                'name' => $userHandle,
                'displayName' => $displayName,
            ],
        ];

        $this->storeChallenge($challengeId, $payload['challenge'], 'registration', $userHandle);

        return [
            'publicKey' => [
                'rp' => [
                    'name' => config('app.name', 'Laravel App'),
                    'id' => config('passkeys.rp_id'),
                ],
                'user' => $payload['user'],
                'challenge' => $payload['challenge'],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                ],
                'timeout' => 60000,
                'attestation' => 'none',
            ],
            'challengeId' => $challengeId,
            'userHandle' => $userHandle,
        ];
    }

    public function completeRegistration(array $credential, string $challengeId, ?string $name = null): PasskeyCredential
    {
        if (!isset($credential['response']['clientDataJSON'], $credential['response']['attestationObject'])) {
            throw new RuntimeException('Missing attestation payload.');
        }

        $challenge = $this->pullChallenge($challengeId, 'registration');
        $clientDataJSON = Base64Url::decode($credential['response']['clientDataJSON']);
        $clientData = json_decode($clientDataJSON, true);

        if (!is_array($clientData) || ($clientData['type'] ?? null) !== 'webauthn.create') {
            throw new RuntimeException('Invalid client data type for registration.');
        }

        $expectedOrigin = rtrim((string) config('passkeys.origin'), '/');
        $origin = $clientData['origin'] ?? null;
        if (!is_string($origin) || rtrim($origin, '/') !== $expectedOrigin) {
            throw new RuntimeException('Unexpected origin for registration.');
        }

        $clientChallenge = $clientData['challenge'] ?? '';
        $clientChallenge = is_string($clientChallenge) ? $clientChallenge : '';
        $expectedChallenge = Base64Url::encode(Base64Url::decode($clientChallenge));
        if (!hash_equals($challenge['value'], $expectedChallenge)) {
            throw new RuntimeException('Registration challenge mismatch.');
        }

        $attestationObject = Base64Url::decode($credential['response']['attestationObject']);
        $attestation = $this->decoder->decode($attestationObject);

        if (!is_array($attestation) || !isset($attestation['authData'])) {
            throw new RuntimeException('Invalid attestation object.');
        }

        $authData = $attestation['authData'];
        $parsedAuthData = $this->parseAuthenticatorData($authData);
        $credentialId = Base64Url::encode($parsedAuthData['credentialId']);

        $coseKey = new CoseKey($parsedAuthData['credentialPublicKey']);
        $publicKeyPem = $coseKey->toPem();

        $passkey = PasskeyCredential::updateOrCreate(
            [
                'user_handle' => $challenge['userHandle'],
                'credential_id' => $credentialId,
            ],
            [
                'name' => $name ?? $challenge['userHandle'],
                'public_key_pem' => $publicKeyPem,
                'sign_count' => $parsedAuthData['signCount'],
            ]
        );

        Log::info('Passkey registration completed.', ['credential_id' => $credentialId]);

        return $passkey;
    }

    public function createLoginChallenge(string $userHandle): array
    {
        $passkeys = PasskeyCredential::where('user_handle', $userHandle)->get();
        if ($passkeys->isEmpty()) {
            throw new RuntimeException('No passkeys registered for the provided user.');
        }

        $challenge = random_bytes(32);
        $challengeId = Str::uuid()->toString();
        $encodedChallenge = Base64Url::encode($challenge);

        $this->storeChallenge($challengeId, $encodedChallenge, 'login', $userHandle);

        return [
            'publicKey' => [
                'challenge' => $encodedChallenge,
                'allowCredentials' => $passkeys->map(fn (PasskeyCredential $item) => [
                    'id' => $item->credential_id,
                    'type' => 'public-key',
                ])->values()->all(),
                'timeout' => 60000,
                'rpId' => config('passkeys.rp_id'),
                'userVerification' => 'preferred',
            ],
            'challengeId' => $challengeId,
            'userHandle' => $userHandle,
        ];
    }

    public function verifyLogin(array $assertion, string $challengeId): PasskeyCredential
    {
        $storedChallenge = $this->pullChallenge($challengeId, 'login');

        $credentialId = $assertion['id'] ?? null;
        if (!is_string($credentialId)) {
            throw new RuntimeException('Missing credential id.');
        }

        $passkey = PasskeyCredential::where('credential_id', $credentialId)->first();
        if (!$passkey) {
            throw new RuntimeException('Unknown credential.');
        }

        if (!hash_equals($storedChallenge['userHandle'], $passkey->user_handle)) {
            throw new RuntimeException('Credential does not belong to the requested user.');
        }

        if (!isset($assertion['response']['clientDataJSON'], $assertion['response']['authenticatorData'], $assertion['response']['signature'])) {
            throw new RuntimeException('Missing assertion payload.');
        }

        $clientDataJSON = Base64Url::decode($assertion['response']['clientDataJSON']);
        $clientData = json_decode($clientDataJSON, true);
        if (!is_array($clientData) || ($clientData['type'] ?? null) !== 'webauthn.get') {
            throw new RuntimeException('Invalid client data type for login.');
        }

        $expectedOrigin = rtrim((string) config('passkeys.origin'), '/');
        $origin = $clientData['origin'] ?? null;
        if (!is_string($origin) || rtrim($origin, '/') !== $expectedOrigin) {
            throw new RuntimeException('Unexpected origin for login.');
        }

        $clientChallenge = $clientData['challenge'] ?? '';
        $clientChallenge = is_string($clientChallenge) ? $clientChallenge : '';
        $expectedChallenge = Base64Url::encode(Base64Url::decode($clientChallenge));
        if (!hash_equals($storedChallenge['value'], $expectedChallenge)) {
            throw new RuntimeException('Login challenge mismatch.');
        }

        $authenticatorData = Base64Url::decode($assertion['response']['authenticatorData']);
        $signature = Base64Url::decode($assertion['response']['signature']);

        $parsed = $this->parseAuthenticatorData($authenticatorData, false);

        $expectedRpIdHash = hash('sha256', (string) config('passkeys.rp_id'), true);
        if ($expectedRpIdHash !== false && !hash_equals($expectedRpIdHash, $parsed['rpIdHash'])) {
            Log::warning('RP ID hash mismatch during passkey login.');
        }

        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $verifyData = $authenticatorData . $clientDataHash;

        $valid = openssl_verify($verifyData, $signature, $passkey->public_key_pem, OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            throw new RuntimeException('Signature verification failed.');
        }

        if ($parsed['signCount'] <= $passkey->sign_count) {
            Log::warning('Potential cloned authenticator detected.', [
                'credential_id' => $credentialId,
                'expected_sign_count' => $passkey->sign_count + 1,
                'actual_sign_count' => $parsed['signCount'],
            ]);
        }

        $passkey->update(['sign_count' => $parsed['signCount']]);

        return $passkey;
    }

    private function parseAuthenticatorData(string $authData, bool $expectAttested = true): array
    {
        if (strlen($authData) < 37) {
            throw new RuntimeException('Authenticator data is too short.');
        }

        $offset = 0;
        $rpIdHash = substr($authData, $offset, 32);
        $offset += 32;

        $flags = ord($authData[$offset]);
        $offset += 1;

        $signCount = $this->readUint32(substr($authData, $offset, 4));
        $offset += 4;

        $attested = ($flags & 0x40) === 0x40;

        $credentialId = null;
        $credentialPublicKey = null;

        if ($attested) {
            if (strlen($authData) < $offset + 18) {
                throw new RuntimeException('Attested credential data is malformed.');
            }

            $aaguid = substr($authData, $offset, 16);
            $offset += 16;

            $credentialIdLength = $this->readUint16(substr($authData, $offset, 2));
            $offset += 2;
            if (strlen($authData) < $offset + $credentialIdLength) {
                throw new RuntimeException('Credential ID length exceeds authenticator data.');
            }
            $credentialId = substr($authData, $offset, $credentialIdLength);
            $offset += $credentialIdLength;

            $credentialPublicKey = $this->decoder->decodeAt($authData, $offset);
        } elseif ($expectAttested) {
            throw new RuntimeException('Attested credential data missing.');
        }

        return [
            'rpIdHash' => $rpIdHash,
            'flags' => $flags,
            'signCount' => $signCount,
            'credentialId' => $credentialId,
            'credentialPublicKey' => $credentialPublicKey,
        ];
    }

    private function readUint32(string $bytes): int
    {
        $value = 0;
        for ($i = 0; $i < strlen($bytes); $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        return $value;
    }

    private function readUint16(string $bytes): int
    {
        $value = 0;
        for ($i = 0; $i < strlen($bytes); $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        return $value;
    }

    private function storeChallenge(string $id, string $value, string $type, string $userHandle): void
    {
        $this->cache->put($this->cacheKey($id), [
            'value' => $value,
            'type' => $type,
            'userHandle' => $userHandle,
        ], now()->addSeconds((int) config('passkeys.challenge_ttl')));
    }

    private function pullChallenge(string $id, string $expectedType): array
    {
        $payload = $this->cache->pull($this->cacheKey($id));
        if (!$payload || ($payload['type'] ?? null) !== $expectedType) {
            throw new RuntimeException('Challenge has expired or is invalid.');
        }

        return $payload;
    }

    private function cacheKey(string $id): string
    {
        return 'passkey_challenge_' . $id;
    }
}
