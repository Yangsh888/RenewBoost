<?php
declare(strict_types=1);

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Cache;
use TypechoPlugin\RenewBoost\Image;
use TypechoPlugin\RenewBoost\Output;
use TypechoPlugin\RenewBoost\Settings;
use TypechoPlugin\RenewBoost\Text;

$user->pass('administrator');
$settings = Settings::loadFresh();
$currentTab = trim((string) $request->get('tab', 'global'));
if (!in_array($currentTab, Settings::tabs(), true)) {
    $currentTab = 'global';
}
$tabLabels = Settings::tabLabels();

$cache = Cache::getInstance();
$cacheEnabled = $cache->enabled();
$activatedPlugins = is_array($options->plugins['activated'] ?? null) ? $options->plugins['activated'] : [];
$renewAvatarActive = array_key_exists('RenewAvatar', $activatedPlugins);
$imageEnv = Image::environment();
$outputEnv = Output::configEnvironment();
$outputReason = $outputEnv['ready'] ? '当前环境满足基础启用条件，实际是否压缩仍以前台 GET/HEAD 请求为准。' : Output::configUnavailableReason($outputEnv);
$altSvcReady = Output::altSvcReady();
$settings['outputAltSvc'] = ($settings['outputAltSvc'] === '1' && $altSvcReady) ? '1' : '0';
$cacheDriver = $cacheEnabled ? strtolower((string) ($options->cacheDriver ?? 'unknown')) : 'disabled';
$cacheDriverLabel = match ($cacheDriver) {
    'redis' => 'Redis',
    'apcu' => 'APCu',
    'disabled' => '未启用',
    'unknown' => '未知',
    default => strtoupper($cacheDriver),
};
?>
<link rel="stylesheet" href="<?php echo Text::e(Settings::assetUrl('assets/panel.css')); ?>">
<div class="tr-panel tr-panel-boost">
    <section class="tr-card">
        <div class="tr-card-b">
            <div class="tr-panel-head">
                <div class="tr-panel-heading">
                    <h2 class="tr-panel-title">优化加速</h2>
                    <p class="tr-panel-desc">用于统一配置头像加速、本地图片 WebP、输出压缩及缓存协同等优化能力。</p>
                </div>
                <div class="tr-panel-pills">
                    <span class="tr-pill<?php echo $settings['enabled'] === '1' ? ' tr-pill-accent' : ''; ?>">插件<?php echo $settings['enabled'] === '1' ? '已启用' : '已停用'; ?></span>
                    <span class="tr-pill<?php echo $settings['avatarEnabled'] === '1' ? ' tr-pill-accent' : ''; ?>"><?php echo Text::e($tabLabels['avatar'] ?? '头像加速'); ?></span>
                    <span class="tr-pill<?php echo $settings['imageEnabled'] === '1' ? ' tr-pill-accent' : ''; ?>"><?php echo Text::e($tabLabels['image'] ?? '图片优化'); ?></span>
                    <span class="tr-pill<?php echo ($settings['outputEnabled'] === '1' || $settings['outputAltSvc'] === '1') ? ' tr-pill-accent' : ''; ?>"><?php echo Text::e($tabLabels['output'] ?? '输出与头部'); ?></span>
                </div>
            </div>
        </div>
    </section>

    <div class="tr-panel-kpis">
        <article class="tr-panel-kpi"><strong><?php echo Text::e($cacheDriverLabel); ?></strong><span>缓存驱动</span></article>
        <article class="tr-panel-kpi"><strong><?php echo $imageEnv['imagickWebp'] ? 'Imagick' : ($imageEnv['gdWebp'] ? 'GD' : '无'); ?></strong><span>图片引擎</span></article>
        <article class="tr-panel-kpi"><strong><?php echo $outputEnv['ready'] ? '可启用' : '受限'; ?></strong><span>输出压缩</span></article>
        <article class="tr-panel-kpi"><strong><?php echo $cacheEnabled ? '已接入' : '未启用'; ?></strong><span>系统缓存</span></article>
    </div>

    <nav class="tr-panel-tabs" aria-label="优化加速导航">
        <?php foreach (Settings::tabs() as $tab): ?>
            <button type="button"
                    class="tr-panel-tab<?php echo $currentTab === $tab ? ' is-active' : ''; ?>"
                    data-target="<?php echo Text::e($tab); ?>"><?php echo Text::e($tabLabels[$tab] ?? $tab); ?></button>
        <?php endforeach; ?>
    </nav>

    <?php require __DIR__ . '/view/form.php'; ?>
</div>
<script src="<?php $options->adminStaticUrl('js', 'tr-tabs.js'); ?>"></script>
<script src="<?php echo Text::e(Settings::assetUrl('assets/panel.js')); ?>"></script>
