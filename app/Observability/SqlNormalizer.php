<?php

namespace App\Observability;

/**
 * Reduces SQL to a shape that identifies "the same query" across executions.
 *
 * Bindings are never stored (they routinely hold emails, tokens and other PII), and
 * any literals written inline are replaced too. Variable-length IN lists collapse to
 * one placeholder so `IN (?, ?)` and `IN (?, ?, ?)` share a fingerprint, which is what
 * makes eager-load vs N+1 patterns comparable.
 */
final class SqlNormalizer
{
    public static function normalize(string $sql): string
    {
        $sql = preg_replace([
            "/'(?:[^'\\\\]|\\\\.|'')*'/s",                 // 'string literals'
            '/(?<![\w"`.])-?\d+(?:\.\d+)?(?![\w"`])/',     // bare numbers, not inside identifiers
            '/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i',        // in (?, ?, ?) -> in (...)
            '/\bvalues\s*(\(\s*\?(?:\s*,\s*\?)*\s*\))(?:\s*,\s*\1)+/i', // multi-row inserts
            '/\s+/',
        ], ['?', '?', 'in (...)', 'values $1, ...', ' '], $sql) ?? $sql;

        return trim($sql);
    }

    public static function fingerprint(string $normalizedSql): string
    {
        return substr(sha1($normalizedSql), 0, 16);
    }

    public static function isSelect(string $sql): bool
    {
        return (bool) preg_match('/^\s*select\b/i', $sql);
    }
}
