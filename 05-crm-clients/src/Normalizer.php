<?php

declare(strict_types=1);

final class Normalizer
{
    /** @var list<string> */
    private const LEGAL_FORMS = [
        'ооо', 'оао', 'зао', 'пао', 'ао', 'уп', 'чуп', 'чтуп', 'одо', 'ип', 'гп', 'куп', 'руп',
        'унитарное', 'предприятие',
    ];

    public static function normalizeUnp(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if ($digits === null || strlen($digits) !== 9) {
            return null;
        }

        return $digits;
    }

    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }
        $len = strlen($digits);
        if ($len === 11 && str_starts_with($digits, '80')) {
            return '375' . substr($digits, -9);
        }
        if ($len === 10 && str_starts_with($digits, '0')) {
            return '375' . substr($digits, -9);
        }
        if ($len === 9) {
            return '375' . $digits;
        }
        if ($len >= 7) {
            return $digits;
        }

        return null;
    }

    public static function normalizeEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        $lower = mb_strtolower($trimmed, 'UTF-8');
        if (filter_var($lower, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $lower;
    }

    public static function normalizeName(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $s = trim($value);
        $s = mb_strtolower($s, 'UTF-8');
        $quotesAndDashes = [
            "\u{00AB}", "\u{00BB}", "\u{201E}", "\u{201C}", "\u{201D}",
            "\u{2018}", "\u{2019}", "\u{2014}", "\u{2013}", '-',
        ];
        $s = str_replace($quotesAndDashes, ' ', $s);
        $s = preg_replace('/[.,()\/\\\\|]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        $tokens = explode(' ', $s);
        $filtered = [];
        foreach ($tokens as $i => $token) {
            if ($token === '') {
                continue;
            }
            if ($token === 'унитарное' && isset($tokens[$i + 1]) && $tokens[$i + 1] === 'предприятие') {
                continue;
            }
            if ($token === 'предприятие' && $i > 0 && $tokens[$i - 1] === 'унитарное') {
                continue;
            }
            if (in_array($token, self::LEGAL_FORMS, true)) {
                continue;
            }
            $filtered[] = $token;
        }

        return implode(' ', $filtered);
    }

    public static function nameSimilarity(string $a, string $b): float
    {
        $na = self::normalizeName($a);
        $nb = self::normalizeName($b);
        if ($na === '' || $nb === '') {
            return 0.0;
        }
        similar_text($na, $nb, $percent);

        return (float) $percent;
    }

    public static function nameSimilarityNorm(string $nameNormA, string $nameNormB): float
    {
        if ($nameNormA === '' || $nameNormB === '') {
            return 0.0;
        }
        similar_text($nameNormA, $nameNormB, $percent);

        return (float) $percent;
    }
}
