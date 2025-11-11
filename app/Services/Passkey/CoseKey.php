<?php

namespace App\Services\Passkey;

use RuntimeException;

class CoseKey
{
    public function __construct(private readonly array $data)
    {
    }

    public function toPem(): string
    {
        $kty = $this->data[1] ?? null;

        return match ($kty) {
            2 => $this->ecKeyToPem(),
            default => throw new RuntimeException('Unsupported COSE key type: ' . ($kty ?? 'unknown')),
        };
    }

    private function ecKeyToPem(): string
    {
        $crv = $this->data[-1] ?? null;
        $x = $this->data[-2] ?? null;
        $y = $this->data[-3] ?? null;

        if ($crv !== 1) {
            throw new RuntimeException('Only P-256 curve is supported.');
        }

        if (!is_string($x) || !is_string($y)) {
            throw new RuntimeException('Invalid EC key coordinates.');
        }

        $publicKey = "\x04" . $x . $y;
        $algoSequence = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
        $publicKeyBitString = "\x03" . $this->encodeLength(strlen($publicKey) + 1) . "\x00" . $publicKey;
        $sequence = "\x30" . $this->encodeLength(strlen($algoSequence . $publicKeyBitString)) . $algoSequence . $publicKeyBitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($sequence), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
