<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewBoost;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Text
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function cut(?string $value, int $max): string
    {
        $value = (string) $value;
        if ($max <= 0 || $value === '') {
            return $max <= 0 ? '' : $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        if (function_exists('iconv_substr')) {
            $cut = iconv_substr($value, 0, $max, 'UTF-8');
            if ($cut !== false) {
                return $cut;
            }
        }

        return substr($value, 0, $max);
    }
}
