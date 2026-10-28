<?php

namespace App\Support;

/**
 * Phone / WhatsApp number helpers. Numbers are stored in E.164 form (+2507XXXXXXXX);
 * Rwandan local formats (07XXXXXXXX, 7XXXXXXXX, 2507XXXXXXXX) are accepted.
 */
class Phone
{
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/[^\d\s+().\-]/', $raw)) {
            return null;
        }
        $plus = str_starts_with($raw, '+');
        $d = preg_replace('/\D+/', '', $raw) ?? '';

        if (! $plus && str_starts_with($d, '00')) {
            $d = substr($d, 2);
            $plus = true;
        }
        if (! $plus) {
            if (preg_match('/^07\d{8}$/', $d)) {
                return '+250'.substr($d, 1);
            }
            if (preg_match('/^7\d{8}$/', $d)) {
                return '+250'.$d;
            }
            if (preg_match('/^2507\d{8}$/', $d)) {
                return '+'.$d;
            }

            return null;
        }
        if (str_starts_with($d, '250')) {
            return preg_match('/^2507\d{8}$/', $d) ? '+'.$d : null;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $d) ? '+'.$d : null;
    }
}
