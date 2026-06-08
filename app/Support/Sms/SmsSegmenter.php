<?php

declare(strict_types=1);

namespace App\Support\Sms;

/**
 * Computes SMS encoding and segmentation. GSM-7 messages fit 160 chars in a
 * single segment (153 per segment when concatenated); messages containing
 * characters outside the GSM 03.38 alphabet fall back to UCS-2 (70 chars
 * single, 67 per concatenated segment).
 */
class SmsSegmenter
{
    private const GSM7_SINGLE = 160;

    private const GSM7_MULTI = 153;

    private const UCS2_SINGLE = 70;

    private const UCS2_MULTI = 67;

    public function segment(string $text): SmsSegmentation
    {
        $length = mb_strlen($text);

        if ($this->isGsm7($text)) {
            $perSegment = $length <= self::GSM7_SINGLE ? self::GSM7_SINGLE : self::GSM7_MULTI;

            return new SmsSegmentation('GSM-7', $length, $this->countSegments($length, $perSegment), $perSegment);
        }

        $perSegment = $length <= self::UCS2_SINGLE ? self::UCS2_SINGLE : self::UCS2_MULTI;

        return new SmsSegmentation('UCS-2', $length, $this->countSegments($length, $perSegment), $perSegment);
    }

    private function countSegments(int $length, int $perSegment): int
    {
        if ($length === 0) {
            return 1;
        }

        return (int) ceil($length / $perSegment);
    }

    private function isGsm7(string $text): bool
    {
        $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extended = '^{}\\[~]|€';
        $allowed = $basic.$extended;

        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if (mb_strpos($allowed, mb_substr($text, $i, 1)) === false) {
                return false;
            }
        }

        return true;
    }
}
