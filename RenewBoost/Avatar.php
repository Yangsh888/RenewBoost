<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewBoost;

use Typecho\Common;
use Typecho\Http\Client;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Avatar
{
    public static function render($size, $rating, $default, $comments): void
    {
        if (self::legacyAvatarActive()) {
            return;
        }

        $settings = Settings::load();
        $mail = (string) ($comments->mail ?? '');
        $secure = $comments->request->isSecure();
        $size = (int) $size;
        $enabled = ($settings['enabled'] ?? '0') === '1' && ($settings['avatarEnabled'] ?? '0') === '1';
        $url = $enabled
            ? self::avatarUrl($mail, $size, (string) $rating, $default, $settings)
            : Common::gravatarUrl($mail, $size, (string) $rating, $default, $secure);
        $srcset = self::buildSrcset($mail, $size, (string) $rating, $default, $settings, $enabled, $secure, $comments);

        $author = htmlspecialchars((string) ($comments->author ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<img class="avatar" loading="lazy" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '"' . $srcset . ' alt="' . $author . '" width="' . $size . '" height="' . $size . '" />';
    }

    public static function filterAdminHeader(string $header): string
    {
        self::bootstrapPrefix();
        return $header;
    }

    public static function header(string $header, $archive): void
    {
        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1'
            || ($settings['avatarEnabled'] ?? '0') !== '1'
            || ($settings['avatarHints'] ?? '0') !== '1'
            || self::legacyAvatarActive()
        ) {
            return;
        }

        if (!method_exists($archive, 'is') || !$archive->is('single') || !($archive->options->commentsAvatar ?? false)) {
            return;
        }

        $origin = self::mirrorOrigin($settings);
        if ($origin === '') {
            return;
        }

        echo '<link rel="dns-prefetch" href="' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
        echo '<link rel="preconnect" href="' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '" crossorigin />' . "\n";
    }

    public static function bootstrapPrefix(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;

        if (defined('__TYPECHO_GRAVATAR_PREFIX__')) {
            return;
        }

        if (self::legacyAvatarActive()) {
            return;
        }

        try {
            $settings = Settings::load();
        } catch (\Throwable) {
            return;
        }

        if (($settings['enabled'] ?? '0') !== '1' || ($settings['avatarEnabled'] ?? '0') !== '1' || ($settings['avatarGlobal'] ?? '0') !== '1') {
            return;
        }

        $prefix = self::mirrorPrefix($settings);
        if ($prefix !== '') {
            define('__TYPECHO_GRAVATAR_PREFIX__', $prefix);
        }
    }

    public static function mirrorOptions(): array
    {
        return [
            'loli' => 'Loli',
            'qiniu' => 'Qiniu',
            'cravatar' => 'Cravatar',
            'webpse' => 'Webp.se',
            'weavatar' => 'WeAvatar',
            'sepcc' => 'SEP.CC',
            'geekzu' => 'Geekzu',
            'custom' => '自定义',
        ];
    }

    public static function avatarUrl(string $mail, int $size, string $rating, ?string $default, array $settings): string
    {
        $size = max(16, min(512, $size));
        $rating = $rating !== '' ? $rating : 'G';
        $defaultAvatar = (string) ($settings['avatarDefault'] ?? 'mm');
        $defaultValue = $default !== null ? (string) $default : $defaultAvatar;
        $normalized = self::normalizeMail($mail);

        if ($normalized === '') {
            return self::gravatarWithHash('', $size, $rating, $defaultValue, $settings);
        }

        if (($settings['avatarEnableQq'] ?? '0') === '1' && ($settings['avatarPriority'] ?? 'qq') === 'qq') {
            $qq = self::qqAvatarUrl($normalized, $settings);
            if ($qq !== '') {
                return $qq;
            }
        }

        return self::gravatarWithHash(md5($normalized), $size, $rating, $defaultValue, $settings);
    }

    private static function gravatarWithHash(string $hash, int $size, string $rating, string $default, array $settings): string
    {
        $query = http_build_query([
            's' => $size,
            'r' => $rating,
            'd' => $default,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::mirrorPrefix($settings) . $hash . '?' . $query;
    }

    private static function qqAvatarUrl(string $mail, array $settings): string
    {
        if (!preg_match('/^(\d{5,11})@qq\.com$/i', $mail, $matches)) {
            return '';
        }

        $uin = $matches[1];
        $ttl = (int) ($settings['avatarCacheTtl'] ?? 3600);
        $cacheKey = 'qq:' . md5($mail);
        $failKey = $cacheKey . ':fail';

        $hit = false;
        $cached = Settings::cacheGet('avatar', $cacheKey, $hit);
        if ($hit && is_string($cached)) {
            return $cached;
        }

        $failData = Settings::cacheGet('avatar', $failKey, $hit);
        $failCount = 0;
        $retryAt = 0;
        if ($hit && is_string($failData) && $failData !== '') {
            $decoded = json_decode($failData, true);
            if (is_array($decoded)) {
                $failCount = max(0, (int) ($decoded['count'] ?? 0));
                $retryAt = max(0, (int) ($decoded['retryAt'] ?? 0));
            }
        }

        if ($retryAt > time()) {
            return '';
        }

        $url = self::fetchQqUrl($uin, (int) ($settings['avatarTimeout'] ?? 3));
        if ($url !== '') {
            Settings::cacheSet('avatar', $cacheKey, $url, $ttl);
            Settings::cacheDelete('avatar', $failKey);
            return $url;
        }

        $nextCount = $failCount + 1;
        Settings::cacheSet('avatar', $failKey, (string) json_encode([
            'count' => $nextCount,
            'retryAt' => time() + self::failTtl($nextCount),
        ]), self::failTtl($nextCount));

        return '';
    }

    private static function fetchQqUrl(string $uin, int $timeout): string
    {
        $target = 'https://ptlogin2.qq.com/getface?uin=' . rawurlencode($uin) . '&imgtype=3&fid=0';

        try {
            $body = self::request($target, $timeout);
        } catch (\Throwable $e) {
            Settings::report('avatar.request', $e);
            return '';
        }

        if (preg_match('/pt\.setHeader\((.+)\)/is', $body, $match)) {
            $decoded = json_decode((string) ($match[1] ?? ''), true);
            if (is_array($decoded)) {
                $value = trim((string) ($decoded[$uin] ?? ''));
                if ($value !== '') {
                    return self::normalizeQqAvatarUrl($value);
                }
            }
        }

        if (!preg_match('/https?:\/\/[^\'"]+/i', $body, $matches)) {
            return '';
        }

        $url = trim((string) ($matches[0] ?? ''));
        return self::normalizeQqAvatarUrl($url);
    }

    private static function normalizeQqAvatarUrl(string $url): string
    {
        $parts = Common::parseUrl($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !Common::checkSafeHost($host)) {
            return '';
        }

        return preg_replace('#^http://#i', 'https://', $url) ?? '';
    }

    private static function request(string $url, int $timeout): string
    {
        $parts = Common::parseUrl($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !Common::checkSafeHost($host)) {
            throw new \RuntimeException('头像请求目标不安全');
        }

        $client = Client::get($url);
        $client->setHeader('User-Agent', 'TypeRenew')
            ->setHeader('Accept', 'text/plain, text/html, */*')
            ->setTimeout(max(1, min(10, $timeout)))
            ->send();

        if ($client->getResponseStatus() !== 200) {
            throw new \RuntimeException('头像源返回异常状态');
        }

        return (string) $client->getResponseBody();
    }

    private static function failTtl(int $count): int
    {
        return match (true) {
            $count <= 1 => 60,
            $count === 2 => 180,
            $count === 3 => 600,
            default => 1800,
        };
    }

    private static function mirrorPrefix(array $settings): string
    {
        $mirror = (string) ($settings['avatarMirror'] ?? 'loli');
        if ($mirror === 'custom') {
            return self::sanitizeCustomMirror((string) ($settings['avatarCustomMirror'] ?? ''));
        }

        return self::mirrorMap()[$mirror] ?? self::mirrorMap()['loli'];
    }

    private static function mirrorOrigin(array $settings): string
    {
        $prefix = self::mirrorPrefix($settings);
        if ($prefix === '') {
            return '';
        }

        $parts = Common::parseUrl($prefix);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || !Common::checkSafeHost($host)) {
            return '';
        }

        $origin = $scheme . '://' . $host;
        $port = (int) ($parts['port'] ?? 0);
        if ($port > 0 && $port !== 443) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private static function mirrorMap(): array
    {
        return [
            'loli' => 'https://gravatar.loli.net/avatar/',
            'qiniu' => 'https://dn-qiniu-avatar.qbox.me/avatar/',
            'cravatar' => 'https://cn.cravatar.com/avatar/',
            'webpse' => 'https://gravatar.webp.se/avatar/',
            'weavatar' => 'https://weavatar.com/avatar/',
            'sepcc' => 'https://cdn.sep.cc/avatar/',
            'geekzu' => 'https://sdn.geekzu.org/avatar/',
        ];
    }

    private static function sanitizeCustomMirror(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('#^https://#i', $value)) {
            return self::mirrorMap()['loli'];
        }

        $parts = Common::parseUrl($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !Common::checkSafeHost($host)) {
            return self::mirrorMap()['loli'];
        }

        return rtrim($value, '/') . '/';
    }

    private static function normalizeMail(string $mail): string
    {
        return strtolower(trim($mail));
    }

    private static function buildSrcset(
        string $mail,
        int $size,
        string $rating,
        ?string $default,
        array $settings,
        bool $enabled,
        bool $secure,
        object $comments
    ): string {
        if (!self::highResRequested($comments)) {
            return '';
        }

        $url2x = $enabled
            ? self::avatarUrl($mail, $size * 2, $rating, $default, $settings)
            : Common::gravatarUrl($mail, $size * 2, $rating, $default, $secure);
        $url3x = $enabled
            ? self::avatarUrl($mail, $size * 3, $rating, $default, $settings)
            : Common::gravatarUrl($mail, $size * 3, $rating, $default, $secure);

        if ($url2x === '' || $url3x === '') {
            return '';
        }

        return ' srcset="' . htmlspecialchars($url2x, ENT_QUOTES, 'UTF-8') . ' 2x, '
            . htmlspecialchars($url3x, ENT_QUOTES, 'UTF-8') . ' 3x"';
    }

    private static function highResRequested(object $comments): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 12) as $frame) {
            if (($frame['function'] ?? '') !== 'gravatar') {
                continue;
            }

            $object = $frame['object'] ?? null;
            if (!is_object($object) || !is_a($object, \Widget\Base\Comments::class) || $object !== $comments) {
                continue;
            }

            return (bool) ($frame['args'][2] ?? false);
        }

        return false;
    }

    private static function legacyAvatarActive(): bool
    {
        return \Typecho\Plugin::exists('RenewAvatar');
    }
}
