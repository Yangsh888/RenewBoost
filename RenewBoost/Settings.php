<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewBoost;

use Typecho\Cache;
use Typecho\Common;
use Typecho\Plugin\Exception as PluginException;
use Utils\Helper;
use Utils\Pref;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Settings
{
    private const NAME = 'RenewBoost';
    private const CACHE_KEY = 'renewboost:settings:v1';
    private const CACHE_VERSION = 1;
    private static ?array $runtime = null;

    public static function load(): array
    {
        return Pref::load(
            self::$runtime,
            self::CACHE_KEY,
            self::defaults(),
            static fn(): array => self::readStored('load.read'),
            [self::class, 'normalize'],
            [self::class, 'ensureStored'],
            [self::class, 'report']
        );
    }

    public static function loadFresh(): array
    {
        $raw = self::readStored('fresh.read');
        if ($raw === []) {
            self::ensureStored();
            $raw = self::readStored('fresh.retry');
        }

        return self::normalize(array_merge(self::defaults(), $raw));
    }

    public static function defaults(): array
    {
        return [
            'enabled' => '1',
            'cacheAssist' => '1',
            'cacheTtl' => 300,
            'avatarEnabled' => '1',
            'avatarGlobal' => '1',
            'avatarHints' => '1',
            'avatarMirror' => 'loli',
            'avatarCustomMirror' => '',
            'avatarPriority' => 'qq',
            'avatarEnableQq' => '1',
            'avatarDefault' => 'mm',
            'avatarCacheTtl' => 3600,
            'avatarTimeout' => 3,
            'imageEnabled' => '1',
            'imageEngine' => 'auto',
            'imageRewrite' => '1',
            'imageHints' => '1',
            'imageLazy' => '1',
            'imageFirstEager' => '1',
            'imageRewriteComments' => '0',
            'imageFeed' => '0',
            'imageExcludePaths' => '',
            'imageQuality' => 82,
            'imageMinBytes' => 8192,
            'outputEnabled' => '0',
            'outputAltSvc' => '0',
            'outputAltSvcValue' => 'h3=":443"; ma=86400',
        ];
    }

    public static function tabs(): array
    {
        return ['global', 'avatar', 'image', 'output'];
    }

    public static function tabLabels(): array
    {
        return [
            'global' => '基础设置',
            'avatar' => '头像加速',
            'image' => '图片优化',
            'output' => '输出与头部',
        ];
    }

    public static function boolKeys(): array
    {
        return [
            'enabled',
            'cacheAssist',
            'avatarEnabled',
            'avatarGlobal',
            'avatarHints',
            'avatarEnableQq',
            'imageEnabled',
            'imageRewrite',
            'imageHints',
            'imageLazy',
            'imageFirstEager',
            'imageRewriteComments',
            'imageFeed',
            'outputEnabled',
            'outputAltSvc',
        ];
    }

    public static function normalize(array $settings): array
    {
        unset($settings['cacheVer']);
        $d = self::defaults();
        $settings = array_merge($d, $settings);

        foreach (self::boolKeys() as $key) {
            $settings[$key] = self::bool($settings[$key] ?? ($d[$key] ?? '0'));
        }

        $settings['cacheTtl'] = self::int($settings['cacheTtl'] ?? 300, 60, 3600, 300);
        $settings['avatarCacheTtl'] = self::int($settings['avatarCacheTtl'] ?? 3600, 60, 86400, 3600);
        $settings['avatarTimeout'] = self::int($settings['avatarTimeout'] ?? 3, 1, 10, 3);
        $settings['imageQuality'] = self::int($settings['imageQuality'] ?? 82, 20, 100, 82);
        $settings['imageMinBytes'] = self::int($settings['imageMinBytes'] ?? 8192, 0, 5242880, 8192);

        $avatarMirror = (string) $settings['avatarMirror'];
        if ($avatarMirror === 'sep_cc') {
            $avatarMirror = 'sepcc';
        }
        $settings['avatarMirror'] = in_array($avatarMirror, array_keys(Avatar::mirrorOptions()), true)
            ? $avatarMirror : 'loli';
        $settings['avatarPriority'] = in_array((string) $settings['avatarPriority'], ['qq', 'gr'], true)
            ? (string) $settings['avatarPriority'] : 'qq';
        $settings['avatarDefault'] = in_array((string) $settings['avatarDefault'], ['mm', 'blank', 'identicon', 'wavatar', 'monsterid'], true)
            ? (string) $settings['avatarDefault'] : 'mm';
        $settings['imageEngine'] = in_array((string) $settings['imageEngine'], ['auto', 'imagick', 'gd', 'off'], true)
            ? (string) $settings['imageEngine'] : 'auto';

        $settings['avatarCustomMirror'] = self::plainText($settings['avatarCustomMirror'] ?? '', 1024);
        $settings['imageExcludePaths'] = self::plainText($settings['imageExcludePaths'] ?? '', 2048);
        if ($settings['avatarMirror'] === 'custom' && !preg_match('#^https://#i', $settings['avatarCustomMirror'])) {
            $settings['avatarCustomMirror'] = '';
        }
        $settings['outputAltSvcValue'] = self::asciiText($settings['outputAltSvcValue'] ?? '', 255, 'h3=":443"; ma=86400');

        return $settings;
    }

    public static function store(array $settings): void
    {
        $settings = self::normalize($settings);
        \Widget\Plugins\Edit::configPlugin(self::NAME, $settings);
        self::clear();
    }

    public static function ensureStored(): void
    {
        Pref::sync(
            self::NAME,
            self::defaults(),
            [self::class, 'normalize'],
            [self::class, 'report'],
            null,
            static fn(): array => self::readStored('ensure.read')
        );
        self::clear();
    }

    public static function clear(): void
    {
        Pref::forget(self::$runtime, self::CACHE_KEY, [self::class, 'report']);
    }

    public static function panelUrl(): string
    {
        return Helper::url(self::NAME . '/Panel.php');
    }

    public static function panelQueryUrl(array $query = []): string
    {
        $clean = [];
        foreach ($query as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $clean[$key] = $value;
            }
        }

        if ($clean === []) {
            return self::panelUrl();
        }

        $url = self::panelUrl();
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($clean);
    }

    public static function assetUrl(string $path): string
    {
        $base = (string) (Helper::options()->pluginUrl ?? '');
        if ($base === '') {
            $base = Common::url('usr/plugins/', self::siteUrl());
        }

        return Common::url(self::NAME . '/' . ltrim($path, '/'), $base);
    }

    public static function actionUrl(string $do = '', bool $secure = false): string
    {
        $path = '/action/renew-boost';
        if ($do !== '') {
            $path .= '?do=' . rawurlencode($do);
        }

        if ($secure) {
            return \Widget\Security::alloc()->getIndex($path);
        }

        return Common::url($path, (string) Helper::options()->index);
    }

    public static function siteUrl(): string
    {
        return rtrim((string) Helper::options()->siteUrl, '/') . '/';
    }

    public static function siteHost(): string
    {
        return strtolower((string) (parse_url(self::siteUrl(), PHP_URL_HOST) ?? ''));
    }

    public static function uploadDir(): string
    {
        $dir = defined('__TYPECHO_UPLOAD_DIR__') ? (string) __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
        return '/' . trim(str_replace('\\', '/', $dir), '/');
    }

    public static function uploadRoot(): string
    {
        $base = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? (string) __TYPECHO_UPLOAD_ROOT_DIR__ : (string) __TYPECHO_ROOT_DIR__;
        $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR);
        $dir = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, self::uploadDir()), DIRECTORY_SEPARATOR);

        return $dir === '' ? $base : $base . DIRECTORY_SEPARATOR . $dir;
    }

    public static function physicalFromUploadPath(string $path): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', trim($path)), '/');
        $uploadDir = self::uploadDir();
        if (!str_starts_with($path, $uploadDir . '/') && $path !== $uploadDir) {
            return '';
        }

        $suffix = ltrim(substr($path, strlen($uploadDir)), '/');
        return $suffix === '' ? self::uploadRoot() : self::uploadRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $suffix);
    }

    public static function cacheGet(string $bucket, string $id, &$hit = false)
    {
        $hit = false;
        $settings = self::load();
        if ($settings['cacheAssist'] !== '1') {
            return null;
        }

        $cache = Cache::getInstance();
        if (!$cache->enabled()) {
            return null;
        }

        try {
            return $cache->get(self::cacheKey($bucket, $id), $hit);
        } catch (\Throwable $e) {
            self::report('cache.get', $e);
            return null;
        }
    }

    public static function cacheSet(string $bucket, string $id, $value, int $ttl): void
    {
        $settings = self::load();
        if ($settings['cacheAssist'] !== '1') {
            return;
        }

        $cache = Cache::getInstance();
        if (!$cache->enabled()) {
            return;
        }

        try {
            $cache->set(self::cacheKey($bucket, $id), $value, max(1, $ttl));
        } catch (\Throwable $e) {
            self::report('cache.set', $e);
        }
    }

    public static function cacheDelete(string $bucket, string $id): void
    {
        $settings = self::load();
        if ($settings['cacheAssist'] !== '1') {
            return;
        }

        $cache = Cache::getInstance();
        if (!$cache->enabled()) {
            return;
        }

        try {
            $cache->delete(self::cacheKey($bucket, $id));
        } catch (\Throwable $e) {
            self::report('cache.delete', $e);
        }
    }

    public static function report(string $scope, \Throwable $e): void
    {
        error_log('RenewBoost.' . $scope . ': ' . $e->getMessage());
    }

    private static function cacheKey(string $bucket, string $id): string
    {
        return 'renewboost:' . $bucket . ':v' . self::CACHE_VERSION . ':' . md5($id);
    }

    private static function readStored(string $scope): array
    {
        try {
            return (array) Helper::options()->plugin(self::NAME)->toArray();
        } catch (PluginException) {
            return [];
        } catch (\Throwable $e) {
            self::report($scope, $e);
            return [];
        }
    }

    private static function bool($value): string
    {
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
    }

    private static function int($value, int $min, int $max, int $default): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private static function plainText($value, int $max): string
    {
        $value = trim(str_replace("\0", '', (string) $value));
        return Text::cut($value, $max);
    }

    private static function asciiText($value, int $max, string $default): string
    {
        $value = trim(str_replace("\0", '', (string) $value));
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        $value = Text::cut($value, $max);
        return $value !== '' ? $value : $default;
    }

}
