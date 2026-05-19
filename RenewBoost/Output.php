<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewBoost;

use Typecho\Response;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Output
{
    public static function boot(): void
    {
        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1') {
            return;
        }

        if (($settings['outputAltSvc'] ?? '0') === '1' && self::altSvcReady() && !headers_sent()) {
            $value = trim((string) ($settings['outputAltSvcValue'] ?? ''));
            if ($value !== '') {
                Response::getInstance()->setHeader('Alt-Svc', $value);
            }
        }

        if (($settings['outputEnabled'] ?? '0') !== '1') {
            return;
        }

        $env = self::environment();
        if (!(bool) ($env['ready'] ?? false)) {
            return;
        }

        ob_start('ob_gzhandler');
    }

    public static function environment(): array
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        $zlib = extension_loaded('zlib');
        $handler = function_exists('ob_gzhandler');
        $outputCompression = strtolower(trim((string) ini_get('zlib.output_compression')));
        $compressionOn = $outputCompression !== '' && $outputCompression !== '0' && $outputCompression !== 'off';
        $acceptGzip = str_contains($accept, 'gzip') || str_contains($accept, 'deflate');
        $handlers = ob_list_handlers();
        $hasGzipHandler = false;
        foreach ($handlers as $active) {
            if (stripos((string) $active, 'ob_gzhandler') !== false) {
                $hasGzipHandler = true;
                break;
            }
        }

        $ready = $zlib
            && $handler
            && in_array($method, ['GET', 'HEAD'], true)
            && $range === ''
            && $acceptGzip
            && !$compressionOn
            && !$hasGzipHandler
            && !headers_sent();

        return [
            'zlib' => $zlib,
            'handler' => $handler,
            'method' => $method,
            'range' => $range === '' ? 'none' : 'present',
            'acceptGzip' => $acceptGzip,
            'zlibOutputCompression' => $outputCompression === '' ? '0' : $outputCompression,
            'activeHandler' => $hasGzipHandler,
            'headersSent' => headers_sent(),
            'ready' => $ready,
        ];
    }

    public static function configEnvironment(): array
    {
        $zlib = extension_loaded('zlib');
        $handler = function_exists('ob_gzhandler');
        $outputCompression = strtolower(trim((string) ini_get('zlib.output_compression')));
        $compressionOn = $outputCompression !== '' && $outputCompression !== '0' && $outputCompression !== 'off';
        $hasGzipHandler = false;

        foreach (ob_list_handlers() as $active) {
            if (stripos((string) $active, 'ob_gzhandler') !== false) {
                $hasGzipHandler = true;
                break;
            }
        }

        return [
            'zlib' => $zlib,
            'handler' => $handler,
            'zlibOutputCompression' => $outputCompression === '' ? '0' : $outputCompression,
            'activeHandler' => $hasGzipHandler,
            'ready' => $zlib && $handler && !$compressionOn && !$hasGzipHandler,
        ];
    }

    public static function altSvcReady(): bool
    {
        return str_starts_with(Settings::siteUrl(), 'https://');
    }

    public static function configUnavailableReason(array $env): string
    {
        if ((bool) ($env['zlib'] ?? false) === false) {
            return '未检测到 zlib 扩展';
        }
        if ((bool) ($env['handler'] ?? false) === false) {
            return '当前环境不可用 ob_gzhandler';
        }
        if (!in_array((string) ($env['zlibOutputCompression'] ?? '0'), ['0', 'off', ''], true)) {
            return 'zlib.output_compression 已开启';
        }
        if ((bool) ($env['activeHandler'] ?? false) === true) {
            return '已有 gzip 输出处理器';
        }

        return '当前环境暂不建议启用';
    }
}
