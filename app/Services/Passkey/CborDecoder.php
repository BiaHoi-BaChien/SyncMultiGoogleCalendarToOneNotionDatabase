<?php

namespace App\Services\Passkey;

use RuntimeException;

class CborDecoder
{
    public function decode(string $binary): array|int|string|float|bool|null
    {
        $offset = 0;
        return $this->decodeItem($binary, $offset);
    }

    public function decodeAt(string $binary, int &$offset): array|int|string|float|bool|null
    {
        return $this->decodeItem($binary, $offset);
    }

    private function decodeItem(string $binary, int &$offset): array|int|string|float|bool|null
    {
        if (!isset($binary[$offset])) {
            throw new RuntimeException('Unexpected end of CBOR data.');
        }

        $initialByte = ord($binary[$offset++]);
        $majorType = $initialByte >> 5;
        $additional = $initialByte & 0x1f;

        $length = match ($additional) {
            24 => $this->readUInt($binary, $offset, 1),
            25 => $this->readUInt($binary, $offset, 2),
            26 => $this->readUInt($binary, $offset, 4),
            27 => $this->readUInt($binary, $offset, 8),
            31 => null,
            default => $additional,
        };

        return match ($majorType) {
            0 => $length,
            1 => -1 - $length,
            2 => $this->readBytes($binary, $offset, $length),
            3 => $this->readText($binary, $offset, $length),
            4 => $this->readArray($binary, $offset, $length),
            5 => $this->readMap($binary, $offset, $length),
            6 => $this->decodeTag($length, $binary, $offset),
            7 => $this->decodeSimpleValue($additional, $binary, $offset, $length),
            default => throw new RuntimeException('Unsupported CBOR major type: ' . $majorType),
        };
    }

    private function readUInt(string $binary, int &$offset, int $length): int
    {
        $data = substr($binary, $offset, $length);
        if (strlen($data) !== $length) {
            throw new RuntimeException('Unable to read unsigned integer.');
        }
        $offset += $length;

        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($data[$i]);
        }

        return $value;
    }

    private function readBytes(string $binary, int &$offset, ?int $length): string
    {
        if ($length === null) {
            throw new RuntimeException('Indefinite length byte strings are not supported.');
        }

        $data = substr($binary, $offset, $length);
        if (strlen($data) !== $length) {
            throw new RuntimeException('Unexpected end of CBOR byte string.');
        }
        $offset += $length;
        return $data;
    }

    private function readText(string $binary, int &$offset, ?int $length): string
    {
        return $this->readBytes($binary, $offset, $length);
    }

    private function readArray(string $binary, int &$offset, ?int $length): array
    {
        if ($length === null) {
            $result = [];
            while (true) {
                if (!isset($binary[$offset])) {
                    throw new RuntimeException('Unexpected end of CBOR array.');
                }

                if (ord($binary[$offset]) === 0xff) {
                    $offset++;
                    break;
                }

                $result[] = $this->decodeItem($binary, $offset);
            }
            return $result;
        }

        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = $this->decodeItem($binary, $offset);
        }
        return $result;
    }

    private function readMap(string $binary, int &$offset, ?int $length): array
    {
        if ($length === null) {
            $result = [];
            while (true) {
                if (!isset($binary[$offset])) {
                    throw new RuntimeException('Unexpected end of CBOR map.');
                }

                if (ord($binary[$offset]) === 0xff) {
                    $offset++;
                    break;
                }

                $key = $this->decodeItem($binary, $offset);
                $result[$key] = $this->decodeItem($binary, $offset);
            }
            return $result;
        }

        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $key = $this->decodeItem($binary, $offset);
            $result[$key] = $this->decodeItem($binary, $offset);
        }
        return $result;
    }

    private function decodeTag(?int $tag, string $binary, int &$offset): mixed
    {
        return $this->decodeItem($binary, $offset);
    }

    private function decodeSimpleValue(int $additional, string $binary, int &$offset, ?int $length): mixed
    {
        return match ($additional) {
            20 => false,
            21 => true,
            22 => null,
            23 => null,
            24 => ord($binary[$offset++]),
            25 => $this->readFloat($binary, $offset, 2),
            26 => $this->readFloat($binary, $offset, 4),
            27 => $this->readFloat($binary, $offset, 8),
            default => throw new RuntimeException('Unsupported CBOR simple value: ' . $additional),
        };
    }

    private function readFloat(string $binary, int &$offset, int $length): float
    {
        $data = substr($binary, $offset, $length);
        if (strlen($data) !== $length) {
            throw new RuntimeException('Unexpected end of CBOR float.');
        }
        $offset += $length;

        return match ($length) {
            2 => $this->decodeHalfFloat($data),
            4 => unpack('G', $data)[1],
            8 => unpack('E', $data)[1],
            default => throw new RuntimeException('Unsupported float length.'),
        };
    }

    private function decodeHalfFloat(string $bytes): float
    {
        $half = unpack('n', $bytes)[1];
        $sign = (($half >> 15) & 0x1) ? -1 : 1;
        $exp = ($half >> 10) & 0x1f;
        $mantissa = $half & 0x3ff;

        if ($exp === 0) {
            return $sign * (2 ** -14) * ($mantissa / (2 ** 10));
        }

        if ($exp === 0x1f) {
            return $mantissa === 0 ? $sign * INF : NAN;
        }

        return $sign * (2 ** ($exp - 15)) * (1 + $mantissa / (2 ** 10));
    }
}
