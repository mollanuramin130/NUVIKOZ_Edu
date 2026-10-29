<?php

namespace App\Services;

use InvalidArgumentException;

/** RFC 6238 SHA-1, 30-second periods. Eight digits supported for RFC vectors only. */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function seed(): string
    {
        $bits = '';
        foreach (unpack('C*', random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= self::ALPHABET[bindec($chunk)];
        }

        return $result;
    }

    public function code(string $seed, int $timestamp, int $digits = 6): string
    {
        if (! preg_match('/\A[A-Z2-7]{32}\z/', $seed) || $timestamp < 0 || ! in_array($digits, [6, 8], true)) {
            throw new InvalidArgumentException('Invalid TOTP parameters.');
        }
        $bits = '';
        foreach (str_split($seed) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            $key .= chr(bindec($byte));
        }
        $counter = intdiv($timestamp, 30);
        $digest = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($digest[19]) & 15;
        $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function counter(string $seed, string $code, int $time, ?int $last): ?int
    {
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }
        $current = intdiv($time, 30);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if ($step >= 0 && ($last === null || $step > $last) && hash_equals($this->code($seed, $step * 30), $code)) {
                return $step;
            }
        }

        return null;
    }
}
