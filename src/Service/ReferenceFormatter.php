<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

/**
 * Mise en forme de la référence à partir du motif configuré (pur, testable).
 * Jetons : {SHOP} code site, {YEAR} année sur 4 chiffres, {YY} sur 2, {NUM} numéro sur 5 chiffres.
 */
final class ReferenceFormatter
{
    public const DEFAULT_PATTERN = 'DEV-{SHOP}-{YEAR}-{NUM}';

    public static function format(string $pattern, string $shopCode, int $year, int $number): string
    {
        $pattern = trim($pattern) !== '' ? $pattern : self::DEFAULT_PATTERN;
        if (!str_contains($pattern, '{NUM}')) {
            $pattern .= '-{NUM}';
        }
        $shopCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shopCode) ?? '');

        $reference = strtr($pattern, [
            '{SHOP}' => $shopCode !== '' ? $shopCode : 'SHOP',
            '{YEAR}' => sprintf('%04d', $year),
            '{YY}' => sprintf('%02d', $year % 100),
            '{NUM}' => sprintf('%05d', $number),
        ]);

        return substr($reference, 0, 32);
    }
}
