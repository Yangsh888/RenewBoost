<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewBoost;

use Typecho\Common;
use Typecho\Request;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Image
{
    private const SOURCE_TYPES = ['jpg', 'jpeg', 'png', 'gif'];
    private static ?array $environmentCache = null;

    public static function upload(object $widget): void
    {
        self::processWidget($widget);
    }

    public static function modify(object $widget): void
    {
        self::processWidget($widget);
    }

    public static function deleteHandle(array $content): bool
    {
        $source = self::deleteSourcePath($content);
        if ($source === '') {
            return false;
        }
        if (!is_file($source)) {
            self::deleteDerivative($source);
            return true;
        }
        if (!@unlink($source)) {
            return false;
        }

        self::deleteDerivative($source);
        return true;
    }

    public static function contentEx(?string $html, object $_widget): ?string
    {
        return self::rewriteHtml($html, 'content');
    }

    public static function excerptEx(?string $html, object $_widget): ?string
    {
        return self::rewriteHtml($html, 'excerpt');
    }

    public static function commentEx(?string $html, object $widget): ?string
    {
        $settings = Settings::load();
        if (($settings['imageRewriteComments'] ?? '0') !== '1' || !self::supportsCommentPicture($widget)) {
            return $html;
        }

        return self::rewriteHtml($html, 'comment');
    }

    public static function header(string $header, object $archive): void
    {
        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1'
            || ($settings['imageEnabled'] ?? '0') !== '1'
            || ($settings['imageHints'] ?? '0') !== '1'
        ) {
            return;
        }

        if (!method_exists($archive, 'is') || (!$archive->is('single') && !$archive->is('page'))) {
            return;
        }

        $origin = self::uploadOrigin();
        if ($origin === '' || $origin === self::siteOrigin()) {
            return;
        }

        echo '<link rel="dns-prefetch" href="' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
        echo '<link rel="preconnect" href="' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '" crossorigin />' . "\n";
    }

    public static function environment(): array
    {
        if (self::$environmentCache !== null) {
            return self::$environmentCache;
        }

        $imagick = extension_loaded('imagick') && class_exists(\Imagick::class);
        $imagickWebp = false;
        if ($imagick) {
            try {
                $formats = array_map('strtoupper', \Imagick::queryFormats('WEBP'));
                $imagickWebp = in_array('WEBP', $formats, true);
            } catch (\Throwable $e) {
                Settings::report('image.imagick.formats', $e);
            }
        }

        $gd = extension_loaded('gd') && function_exists('gd_info');
        $gdWebp = $gd && function_exists('imagewebp');

        self::$environmentCache = [
            'imagick' => $imagick,
            'imagickWebp' => $imagickWebp,
            'gd' => $gd,
            'gdWebp' => $gdWebp,
        ];

        return self::$environmentCache;
    }

    private static function processWidget(object $widget): void
    {
        $attachment = $widget->attachment ?? null;
        if (!is_object($attachment)) {
            return;
        }

        $path = (string) ($attachment->path ?? '');
        if ($path === '') {
            return;
        }

        $source = Settings::physicalFromUploadPath($path);
        if ($source === '' || !is_file($source)) {
            return;
        }

        try {
            self::createDerivative($source, Settings::load());
        } catch (\Throwable $e) {
            Settings::report('image.process', $e);
        }
    }

    private static function createDerivative(string $source, array $settings): void
    {
        if (($settings['enabled'] ?? '0') !== '1' || ($settings['imageEnabled'] ?? '0') !== '1') {
            return;
        }

        if (!self::isSourcePath($source)) {
            return;
        }

        $size = is_file($source) ? filesize($source) : false;
        if ($size === false) {
            return;
        }

        $size = (int) $size;
        if ($size <= 0 || $size < (int) ($settings['imageMinBytes'] ?? 0)) {
            self::deleteDerivative($source);
            return;
        }

        if (self::isAnimatedGif($source)) {
            self::deleteDerivative($source);
            return;
        }

        $engine = self::resolveEngine($settings);
        if ($engine === '') {
            self::deleteDerivative($source);
            return;
        }

        $target = self::targetPath($source);
        $generated = $engine === 'imagick'
            ? self::writeImagick($source, $target, (int) ($settings['imageQuality'] ?? 82))
            : self::writeGd($source, $target, (int) ($settings['imageQuality'] ?? 82));

        if (!$generated || !is_file($target)) {
            throw new \RuntimeException('WebP 转换未生成输出文件');
        }

        $targetSize = (int) (filesize($target) ?: 0);
        if ($targetSize <= 0 || $targetSize >= $size) {
            @unlink($target);
            self::invalidateRewriteCache($source);
            return;
        }

        self::invalidateRewriteCache($source);
    }

    private static function resolveEngine(array $settings): string
    {
        $engine = (string) ($settings['imageEngine'] ?? 'auto');
        $env = self::environment();

        if ($engine === 'off') {
            return '';
        }
        if ($engine === 'imagick') {
            return ($env['imagick'] && $env['imagickWebp']) ? 'imagick' : '';
        }
        if ($engine === 'gd') {
            return ($env['gd'] && $env['gdWebp']) ? 'gd' : '';
        }
        if ($env['imagick'] && $env['imagickWebp']) {
            return 'imagick';
        }

        return ($env['gd'] && $env['gdWebp']) ? 'gd' : '';
    }

    private static function writeImagick(string $source, string $target, int $quality): bool
    {
        $image = new \Imagick();
        try {
            $image->readImage($source);
            if ($image->getNumberImages() > 1) {
                return false;
            }

            $image->setIteratorIndex(0);
            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            }
            $image->setImageFormat('WEBP');
            $image->setImageCompressionQuality($quality);
            return $image->writeImage($target);
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private static function writeGd(string $source, string $target, int $quality): bool
    {
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $image = match ($ext) {
            'jpg', 'jpeg' => imagecreatefromjpeg($source),
            'png' => imagecreatefrompng($source),
            'gif' => imagecreatefromgif($source),
            default => false,
        };

        if (!$image) {
            return false;
        }

        try {
            if (in_array($ext, ['png', 'gif'], true)) {
                if (function_exists('imagepalettetotruecolor')) {
                    imagepalettetotruecolor($image);
                }
                imagealphablending($image, true);
                imagesavealpha($image, true);
            }

            return imagewebp($image, $target, $quality);
        } finally {
            imagedestroy($image);
        }
    }

    private static function rewriteHtml(?string $html, string $context): ?string
    {
        if ($html === null || $html === '' || stripos($html, '<img') === false) {
            return $html;
        }

        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1' || ($settings['imageEnabled'] ?? '0') !== '1') {
            return $html;
        }
        if ($context !== 'comment' && ($settings['imageRewrite'] ?? '0') !== '1') {
            return $html;
        }
        if ($context !== 'comment' && self::isFeedRequest() && ($settings['imageFeed'] ?? '0') !== '1') {
            return $html;
        }

        $segments = preg_split('/(<picture\b[\s\S]*?<\/picture>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($segments)) {
            return $html;
        }

        $excludePaths = self::excludedPaths((string) ($settings['imageExcludePaths'] ?? ''));
        foreach ($segments as $index => $segment) {
            if ($segment === '' || preg_match('/^\s*<picture\b/i', $segment)) {
                continue;
            }

            $segments[$index] = self::rewriteImgTags(
                $segment,
                $context,
                ($settings['imageLazy'] ?? '1') === '1',
                ($settings['imageFirstEager'] ?? '1') === '1',
                $excludePaths
            );
        }

        return implode('', $segments);
    }

    private static function rewriteImgTags(
        string $html,
        string $context,
        bool $imageLazy,
        bool $imageFirstEager,
        array $excludePaths
    ): string
    {
        $imageIndex = 0;

        return preg_replace_callback('/<img\b[^>]*>/i', static function (array $matches) use (
            $context,
            $imageLazy,
            $imageFirstEager,
            $excludePaths,
            &$imageIndex
        ): string {
            $tag = (string) ($matches[0] ?? '');
            if ($tag === '' || stripos($tag, 'data-rb-img=') !== false) {
                return $tag;
            }

            if (!preg_match('/\bsrc\s*=\s*("|\')([^"\']+)\\1/i', $tag, $srcMatch)) {
                return $tag;
            }

            $src = html_entity_decode((string) ($srcMatch[2] ?? ''), ENT_QUOTES, 'UTF-8');
            $candidate = self::candidate($src, $excludePaths);
            if ($candidate === null) {
                return $tag;
            }

            $img = preg_replace('/<img\b/i', '<img data-rb-img="1"', $tag, 1) ?? $tag;
            if ($imageLazy) {
                $img = self::decorateImageTag(
                    $img,
                    $context !== 'content' || !($imageFirstEager && $imageIndex === 0)
                );
            }
            $imageIndex++;
            $source = '<source type="image/webp" srcset="' . Text::e($candidate['target']) . '">';
            return '<picture class="rb-picture">' . $source . $img . '</picture>';
        }, $html) ?? $html;
    }

    private static function decorateImageTag(string $tag, bool $shouldLazy): string
    {
        if (!preg_match('/\bdecoding\s*=/i', $tag)) {
            $tag = preg_replace('/(?=\s*\/?>$)/', ' decoding="async"', $tag, 1) ?? $tag;
        }

        if (!$shouldLazy) {
            return preg_replace('/\s+loading\s*=\s*("|\')[^"\']*\\1/i', '', $tag, 1) ?? $tag;
        }

        if (!preg_match('/\bloading\s*=/i', $tag)) {
            $tag = preg_replace('/(?=\s*\/?>$)/', ' loading="lazy"', $tag, 1) ?? $tag;
        }

        return $tag;
    }

    private static function candidate(string $url, array $excludePaths): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $cacheId = self::rewriteCacheId($host, $path);
        if ($cacheId === '') {
            return null;
        }

        $uploadDir = Settings::uploadDir();
        if ($path === '' || (!str_starts_with($path, $uploadDir . '/') && $path !== $uploadDir)) {
            return self::rememberNoCandidate($cacheId);
        }
        if (self::isExcludedPath($path, $excludePaths)) {
            return null;
        }

        $hit = false;
        $cached = Settings::cacheGet('image', 'rewrite:' . $cacheId, $hit);
        if ($hit) {
            return self::candidateFromCache($cached, (string) ($parts['query'] ?? ''));
        }

        if ($host !== '' && $host !== Settings::siteHost() && $host !== self::uploadHost()) {
            return self::rememberNoCandidate($cacheId);
        }

        $targetUrl = self::targetPublicUrl($path, $host);
        if ($targetUrl === '') {
            return self::rememberNoCandidate($cacheId);
        }

        $source = Settings::physicalFromUploadPath($path);
        if ($source === '' || !self::isSourcePath($source) || !is_file($source)) {
            return self::rememberNoCandidate($cacheId);
        }

        $targetPath = self::targetPath($source);
        if (!is_file($targetPath)) {
            return self::rememberNoCandidate($cacheId);
        }

        Settings::cacheSet('image', 'rewrite:' . $cacheId, $targetUrl, self::rewriteCacheTtl());
        return self::candidateResult($targetUrl, (string) ($parts['query'] ?? ''));
    }

    private static function supportsCommentPicture(object $widget): bool
    {
        $tags = strtolower((string) ($widget->options->allowedTags ?? ''));
        return str_contains($tags, 'picture') && str_contains($tags, 'source');
    }

    private static function isSourcePath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SOURCE_TYPES, true);
    }

    private static function targetPath(string $source): string
    {
        return preg_replace('/\.[a-z0-9]+$/i', '.webp', $source) ?? ($source . '.webp');
    }

    private static function targetPublicUrl(string $path, string $host): string
    {
        $targetPath = preg_replace('/\.[a-z0-9]+$/i', '.webp', $path);
        if (!is_string($targetPath) || $targetPath === '') {
            return '';
        }

        if (defined('__TYPECHO_UPLOAD_URL__')) {
            return Common::url($targetPath, (string) __TYPECHO_UPLOAD_URL__);
        }

        if ($host === '' || $host === Settings::siteHost()) {
            return $targetPath;
        }

        return Common::url($targetPath, Settings::siteUrl());
    }

    private static function deleteDerivative(string $source): void
    {
        $target = self::targetPath($source);
        if (is_file($target)) {
            @unlink($target);
        }

        self::invalidateRewriteCache($source);
    }

    private static function isAnimatedGif(string $path): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'gif' || !is_readable($path)) {
            return false;
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return false;
        }

        return preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $content) > 1;
    }

    private static function isFeedRequest(): bool
    {
        $request = Request::getInstance();
        $path = strtolower((string) $request->getPathInfo());
        $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        return str_contains($path, '/feed') || str_contains($uri, '/feed');
    }

    private static function invalidateRewriteCache(string $source): void
    {
        $path = self::uploadPathFromPhysical($source);
        if ($path === '') {
            return;
        }

        foreach (array_unique([
            self::rewriteCacheId('', $path),
            self::rewriteCacheId(Settings::siteHost(), $path),
        ]) as $cacheId) {
            if ($cacheId !== '') {
                Settings::cacheDelete('image', 'rewrite:' . $cacheId);
            }
        }
    }

    private static function contentPath(array $content): string
    {
        if (!empty($content['path']) && is_string($content['path'])) {
            return $content['path'];
        }

        $attachment = $content['attachment'] ?? null;
        if (is_array($attachment)) {
            return (string) ($attachment['path'] ?? '');
        }

        if (is_object($attachment) && isset($attachment->path)) {
            return (string) $attachment->path;
        }

        return '';
    }

    private static function deleteSourcePath(array $content): string
    {
        $path = str_replace('\\', '/', trim(self::contentPath($content)));
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return '';
        }

        $uploadDir = Settings::uploadDir();
        if ($path !== $uploadDir && !str_starts_with($path, $uploadDir . '/')) {
            return '';
        }

        $baseRoot = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? (string) __TYPECHO_UPLOAD_ROOT_DIR__ : (string) __TYPECHO_ROOT_DIR__;
        $baseRoot = realpath($baseRoot);
        $uploadRoot = realpath(Settings::uploadRoot());
        if ($baseRoot === false || $uploadRoot === false) {
            return '';
        }

        $baseRoot = rtrim(str_replace('\\', '/', $baseRoot), '/');
        $uploadRoot = rtrim(str_replace('\\', '/', $uploadRoot), '/');
        $source = Common::url($path, $baseRoot);
        $directory = realpath(dirname($source));
        if ($directory === false) {
            return '';
        }

        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if ($directory !== $uploadRoot && !str_starts_with($directory, $uploadRoot . '/')) {
            return '';
        }

        return is_file($source) ? $source : '';
    }

    private static function uploadPathFromPhysical(string $source): string
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, Settings::uploadRoot()), DIRECTORY_SEPARATOR);
        $source = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $source);
        if (!str_starts_with($source, $root . DIRECTORY_SEPARATOR) && $source !== $root) {
            return '';
        }

        $relative = ltrim(substr($source, strlen($root)), DIRECTORY_SEPARATOR);
        if ($relative === '') {
            return '';
        }

        return Settings::uploadDir() . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private static function rewriteCacheId(string $host, string $path): string
    {
        if ($path === '') {
            return '';
        }

        $siteHost = Settings::siteHost();
        $uploadHost = self::uploadHost();
        if ($host !== '' && $host !== $siteHost && $host !== $uploadHost) {
            return strtolower($host) . '|' . $path;
        }

        return $siteHost . '|' . $path;
    }

    private static function rememberNoCandidate(string $cacheId): ?array
    {
        Settings::cacheSet('image', 'rewrite:' . $cacheId, '', self::rewriteCacheTtl());
        return null;
    }

    private static function candidateFromCache($cached, string $query): ?array
    {
        if (!is_string($cached) || $cached === '') {
            return null;
        }

        return self::candidateResult($cached, $query);
    }

    private static function candidateResult(string $targetUrl, string $query): array
    {
        if ($query !== '') {
            $targetUrl .= '?' . $query;
        }

        return ['target' => $targetUrl];
    }

    private static function rewriteCacheTtl(): int
    {
        $settings = Settings::load();
        return max(60, (int) ($settings['cacheTtl'] ?? 300));
    }

    private static function excludedPaths(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $uploadDir = Settings::uploadDir();
        $result = [];

        foreach (preg_split('/[\r\n,]+/', $value) ?: [] as $item) {
            $item = trim(str_replace('\\', '/', $item));
            if ($item === '') {
                continue;
            }

            if (!str_starts_with($item, '/')) {
                $item = $uploadDir . '/' . ltrim($item, '/');
            }

            $item = '/' . ltrim($item, '/');
            if ($item !== $uploadDir && !str_starts_with($item, $uploadDir . '/')) {
                continue;
            }

            $result[] = rtrim($item, '/');
        }

        return array_values(array_unique($result));
    }

    private static function isExcludedPath(string $path, array $excludePaths): bool
    {
        $path = rtrim('/' . ltrim(str_replace('\\', '/', $path), '/'), '/');
        foreach ($excludePaths as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function uploadOrigin(): string
    {
        $base = defined('__TYPECHO_UPLOAD_URL__') ? (string) __TYPECHO_UPLOAD_URL__ : Settings::siteUrl();
        return self::safeHttpsOrigin($base);
    }

    private static function uploadHost(): string
    {
        $base = defined('__TYPECHO_UPLOAD_URL__') ? (string) __TYPECHO_UPLOAD_URL__ : Settings::siteUrl();
        return strtolower((string) (parse_url($base, PHP_URL_HOST) ?? Settings::siteHost()));
    }

    private static function siteOrigin(): string
    {
        return self::safeHttpsOrigin(Settings::siteUrl());
    }

    private static function safeHttpsOrigin(string $url): string
    {
        $parts = Common::parseUrl($url);
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
}
