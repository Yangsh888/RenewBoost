<?php
declare(strict_types=1);

use TypechoPlugin\RenewBoost\Avatar;
use TypechoPlugin\RenewBoost\Settings;
use TypechoPlugin\RenewBoost\Text;

$mirrorOptions = Avatar::mirrorOptions();
?>
<form id="renewboost-main-form" method="post" action="<?php echo Text::e(Settings::actionUrl('save', true)); ?>">
    <input type="hidden" name="tab" value="<?php echo Text::e($currentTab); ?>" data-boost-tab-field>

    <div class="tr-panel-pane<?php echo $currentTab === 'global' ? ' is-active' : ''; ?>" data-tab="global">
        <div class="boost-card">
            <div class="boost-card-header">
                <h3 class="boost-card-title">运行总览</h3>
                <p class="boost-card-desc">用于配置插件启用状态，以及是否联动系统已启用的缓存能力。</p>
            </div>
            <div class="boost-list">
                <?php foreach ([
                    ['enabled', '全局开关', '关闭后将停用 RenewBoost 的头像、图片与输出优化功能。'],
                    ['cacheAssist', '联动系统缓存', '启用后将优先使用系统已启用的 Redis 或 APCu 缓存保存头像结果与图片改写缓存。'],
                ] as [$key, $title, $desc]): ?>
                    <div class="boost-list-item">
                        <div class="boost-list-item-meta">
                            <h4 class="boost-list-item-title"><?php echo Text::e($title); ?></h4>
                            <p class="boost-list-item-desc"><?php echo Text::e($desc); ?></p>
                        </div>
                        <div class="boost-list-item-control">
                            <label class="boost-switch">
                                <input type="checkbox" name="<?php echo Text::e($key); ?>" value="1"<?php echo $settings[$key] === '1' ? ' checked' : ''; ?>>
                                <span class="boost-slider"></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="boost-list-item">
                    <div class="boost-list-item-meta">
                        <h4 class="boost-list-item-title">配置缓存秒数</h4>
                        <p class="boost-list-item-desc">用于设置插件配置与轻量状态缓存的有效期。</p>
                    </div>
                    <div class="boost-list-item-control">
                        <input class="boost-input w-short" type="number" name="cacheTtl" min="60" max="3600" value="<?php echo (int) $settings['cacheTtl']; ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tr-panel-pane<?php echo $currentTab === 'avatar' ? ' is-active' : ''; ?>" data-tab="avatar">
        <div class="boost-card">
            <div class="boost-card-header">
                <h3 class="boost-card-title">头像加速</h3>
                <p class="boost-card-desc">用于配置头像镜像源、QQ 邮箱头像与全局 Gravatar 前缀等加速能力。</p>
            </div>
            <?php if (!empty($renewAvatarActive)): ?>
                <div class="boost-help">
                    <p>检测到 "RenewAvatar" 仍处于启用状态。为避免头像 Hook 与全局前缀重复接管，建议停用 "RenewAvatar" 后再启用 "RenewBoost" 的头像功能。</p>
                </div>
            <?php endif; ?>
            <div class="boost-list">
                <?php foreach ([
                    ['avatarEnabled', '启用头像加速', '关闭后将恢复系统默认的 Gravatar 输出。'],
                    ['avatarGlobal', '全局替换头像源', '启用后同时作用于前台评论头像与后台用户头像。'],
                    ['avatarHints', '输出头像资源提示', '启用后将在单篇页面为当前头像镜像输出 "dns-prefetch" 与 "preconnect" 资源提示。'],
                    ['avatarEnableQq', '启用 QQ 邮箱头像', '启用后将优先为 "12345@qq.com" 这类邮箱获取 QQ 头像。'],
                ] as [$key, $title, $desc]): ?>
                    <div class="boost-list-item">
                        <div class="boost-list-item-meta">
                            <h4 class="boost-list-item-title"><?php echo Text::e($title); ?></h4>
                            <p class="boost-list-item-desc"><?php echo Text::e($desc); ?></p>
                        </div>
                        <div class="boost-list-item-control">
                            <label class="boost-switch">
                                <input type="checkbox" name="<?php echo Text::e($key); ?>" value="1"<?php echo $settings[$key] === '1' ? ' checked' : ''; ?>>
                                <span class="boost-slider"></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="boost-grid">
                    <label class="boost-field">
                        <span>镜像源</span>
                        <select name="avatarMirror" class="boost-input">
                            <?php foreach ($mirrorOptions as $value => $label): ?>
                                <option value="<?php echo Text::e($value); ?>"<?php echo $settings['avatarMirror'] === $value ? ' selected' : ''; ?>><?php echo Text::e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="boost-field">
                        <span>头像优先策略</span>
                        <select name="avatarPriority" class="boost-input">
                            <option value="qq"<?php echo $settings['avatarPriority'] === 'qq' ? ' selected' : ''; ?>>优先 QQ</option>
                            <option value="gr"<?php echo $settings['avatarPriority'] === 'gr' ? ' selected' : ''; ?>>优先镜像</option>
                        </select>
                    </label>
                    <label class="boost-field">
                        <span>默认头像</span>
                        <select name="avatarDefault" class="boost-input">
                            <?php foreach (['mm' => '神秘人', 'blank' => '空白', 'identicon' => '抽象图形', 'wavatar' => 'Wavatar', 'monsterid' => '小怪物'] as $value => $label): ?>
                                <option value="<?php echo Text::e($value); ?>"<?php echo $settings['avatarDefault'] === $value ? ' selected' : ''; ?>><?php echo Text::e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="boost-field">
                        <span>头像缓存秒数</span>
                        <input class="boost-input" type="number" name="avatarCacheTtl" min="60" max="86400" value="<?php echo (int) $settings['avatarCacheTtl']; ?>">
                    </label>
                    <label class="boost-field">
                        <span>请求超时秒数</span>
                        <input class="boost-input" type="number" name="avatarTimeout" min="1" max="10" value="<?php echo (int) $settings['avatarTimeout']; ?>">
                    </label>
                </div>
                <div class="boost-block">
                    <div class="boost-list-item-meta">
                        <h4 class="boost-list-item-title">自定义镜像</h4>
                        <p class="boost-list-item-desc">仅在镜像源选择“自定义”时生效，仅支持 "https://" 地址。</p>
                    </div>
                    <input class="boost-input w-full mono" type="text" name="avatarCustomMirror" value="<?php echo Text::e($settings['avatarCustomMirror']); ?>" placeholder="https://example.com/avatar">
                </div>
            </div>
        </div>
    </div>

    <div class="tr-panel-pane<?php echo $currentTab === 'image' ? ' is-active' : ''; ?>" data-tab="image">
        <div class="boost-card">
            <div class="boost-card-header">
                <h3 class="boost-card-title">本地图片优化</h3>
                <p class="boost-card-desc">用于配置本站上传目录内图片的 WebP 转换与前台输出策略，原图文件将继续保留。</p>
            </div>
            <div class="boost-list">
                <?php foreach ([
                    ['imageEnabled', '启用图片优化', '启用后将在上传或替换本地图片时生成 ".webp" 副本。'],
                    ['imageRewrite', '前台正文改写', '启用后将在前台正文与摘要中为本地图片输出 "<picture>" 结构与 WebP 副本。'],
                    ['imageHints', '输出图片资源提示', '启用后将在单篇页面为独立 HTTPS 上传域名输出 "dns-prefetch" 与 "preconnect" 资源提示。'],
                    ['imageLazy', '补全图片加载属性', '启用后将为插件接管的图片补充异步解码，并为摘要与评论中的图片补充延迟加载。'],
                    ['imageFirstEager', '正文首图取消延迟加载', '启用后将移除正文中首张被插件接管图片的延迟加载属性，其余正文图片再按需补充延迟加载。'],
                    ['imageRewriteComments', '评论图片改写', '仅在评论允许 "picture/source" 标签时生效。'],
                    ['imageFeed', 'Feed 中改写图片', '默认关闭，以减少对 Feed 阅读器的兼容性影响。'],
                ] as [$key, $title, $desc]): ?>
                    <div class="boost-list-item">
                        <div class="boost-list-item-meta">
                            <h4 class="boost-list-item-title"><?php echo Text::e($title); ?></h4>
                            <p class="boost-list-item-desc"><?php echo Text::e($desc); ?></p>
                        </div>
                        <div class="boost-list-item-control">
                            <label class="boost-switch">
                                <input type="checkbox" name="<?php echo Text::e($key); ?>" value="1"<?php echo $settings[$key] === '1' ? ' checked' : ''; ?>>
                                <span class="boost-slider"></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="boost-grid">
                    <label class="boost-field">
                        <span>图片引擎</span>
                        <select name="imageEngine" class="boost-input">
                            <option value="auto"<?php echo $settings['imageEngine'] === 'auto' ? ' selected' : ''; ?>>自动（优先 Imagick）</option>
                            <option value="imagick"<?php echo $settings['imageEngine'] === 'imagick' ? ' selected' : ''; ?>>强制 Imagick</option>
                            <option value="gd"<?php echo $settings['imageEngine'] === 'gd' ? ' selected' : ''; ?>>强制 GD</option>
                            <option value="off"<?php echo $settings['imageEngine'] === 'off' ? ' selected' : ''; ?>>关闭转换</option>
                        </select>
                    </label>
                    <label class="boost-field">
                        <span>WebP 质量</span>
                        <input class="boost-input" type="number" name="imageQuality" min="20" max="100" value="<?php echo (int) $settings['imageQuality']; ?>">
                    </label>
                    <label class="boost-field">
                        <span>最小处理体积（字节）</span>
                        <input class="boost-input" type="number" name="imageMinBytes" min="0" max="5242880" value="<?php echo (int) $settings['imageMinBytes']; ?>">
                    </label>
                </div>
                <div class="boost-block">
                    <div class="boost-list-item-meta">
                        <h4 class="boost-list-item-title">排除处理路径</h4>
                        <p class="boost-list-item-desc">每行或用英文逗号填写一条上传路径。命中后将跳过 WebP 改写与相关图片输出处理，例如 "/usr/uploads/2026/05/demo/"。</p>
                    </div>
                    <textarea class="boost-input w-full mono" name="imageExcludePaths" rows="4" placeholder="/usr/uploads/2026/05/demo/"><?php echo Text::e($settings['imageExcludePaths']); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="tr-panel-pane<?php echo $currentTab === 'output' ? ' is-active' : ''; ?>" data-tab="output">
        <div class="boost-card">
            <div class="boost-card-header">
                <h3 class="boost-card-title">输出与头部</h3>
                <p class="boost-card-desc">用于配置输出压缩与 Alt-Svc 响应头。输出压缩默认关闭，建议仅在环境满足条件时手动启用。</p>
            </div>
            <div class="boost-list">
                <?php foreach ([
                    ['outputEnabled', '启用输出压缩', '仅对前台 GET/HEAD 请求生效；如已启用 zlib 输出压缩或存在其他 gzip 处理器，将自动保持关闭。'],
                    ['outputAltSvc', '输出 Alt-Svc 头', '仅在 HTTPS 站点输出指定的 Alt-Svc 响应头，不负责创建实际的 HTTP/3 或替代服务端点。'],
                ] as [$key, $title, $desc]): ?>
                    <div class="boost-list-item">
                        <div class="boost-list-item-meta">
                            <h4 class="boost-list-item-title"><?php echo Text::e($title); ?></h4>
                            <p class="boost-list-item-desc"><?php echo Text::e($desc); ?></p>
                        </div>
                        <div class="boost-list-item-control">
                            <label class="boost-switch">
                                <input type="checkbox" name="<?php echo Text::e($key); ?>" value="1"<?php echo $settings[$key] === '1' ? ' checked' : ''; ?>>
                                <span class="boost-slider"></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="boost-block">
                    <div class="boost-list-item-meta">
                        <h4 class="boost-list-item-title">Alt-Svc 头内容</h4>
                        <p class="boost-list-item-desc">例如 "h3=":443"; ma=86400"。请根据实际部署环境填写有效的响应头内容；留空时将回退为默认值。</p>
                    </div>
                    <input class="boost-input w-full mono" type="text" name="outputAltSvcValue" value="<?php echo Text::e($settings['outputAltSvcValue']); ?>">
                </div>
                <div class="boost-block">
                    <div class="boost-list-item-meta">
                        <h4 class="boost-list-item-title">压缩环境说明</h4>
                        <p class="boost-list-item-desc">当前压缩环境：<?php echo Text::e($outputReason); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tr-panel-sticky" id="renewboost-sticky">
        <button type="submit" class="btn primary">保存当前配置</button>
    </div>
</form>
