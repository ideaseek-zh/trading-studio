<?php

namespace App\Support;

class SensitiveValueMasker
{
    public static function maskSecret(?string $value, int $visibleStart = 3, int $visibleEnd = 2): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length <= ($visibleStart + $visibleEnd + 1)) {
            return str_repeat('*', max($length, 4));
        }

        return mb_substr($value, 0, $visibleStart)
            .str_repeat('*', max($length - $visibleStart - $visibleEnd, 4))
            .mb_substr($value, -$visibleEnd);
    }

    public static function maskUrl(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return self::maskSecret($value, 4, 3);
        }

        $masked = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $masked .= ':'.$parts['port'];
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '') {
            $segments = explode('/', $path);
            $lastIndex = count($segments) - 1;
            $segments[$lastIndex] = self::maskSecret($segments[$lastIndex], 2, 2) ?? '****';
            $masked .= '/'.implode('/', $segments);
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        if ($query !== []) {
            $maskedQuery = [];
            foreach ($query as $key => $item) {
                $maskedQuery[$key] = is_scalar($item)
                    ? self::maskSecret((string) $item, 2, 2)
                    : '****';
            }

            $masked .= '?'.http_build_query($maskedQuery);
        }

        return $masked;
    }

    public static function looksMasked(?string $value): bool
    {
        return $value !== null && str_contains($value, '***');
    }
}
