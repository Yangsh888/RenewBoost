<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewBoost;

use Typecho\Plugin as Hook;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Utils\Helper;
use Utils\NoPersonal;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 【TypeRenew 专用】优化加速插件
 *
 * @package RenewBoost
 * @author TypeRenew
 * @link https://www.typerenew.com/
 * @version 1.0.0
 * @since 1.4.2
 */
class Plugin implements PluginInterface
{
    use NoPersonal;

    public static function activate(): string
    {
        Settings::ensureStored();
        self::registerHooks();
        Helper::removeRoute('renew_boost_action');
        Helper::addRoute('renew_boost_action', '/action/renew-boost', Action::class, 'action');
        Helper::removePanel(3, 'RenewBoost/Panel.php');
        Helper::addPanel(3, 'RenewBoost/Panel.php', '优化加速', '优化加速', 'administrator', false, '', ['icon' => 'i-zap']);
        return _t('RenewBoost 已启用');
    }

    public static function deactivate(): string
    {
        Helper::removeRoute('renew_boost_action');
        Helper::removePanel(3, 'RenewBoost/Panel.php');
        Settings::clear();
        return _t('RenewBoost 已停用');
    }

    public static function config(Form $form): void
    {
        $settings = Settings::load();

        $enabled = new Form\Element\Radio(
            'enabled',
            ['1' => _t('启用'), '0' => _t('停用')],
            $settings['enabled'] ?? '1',
            _t('插件状态'),
            _t('完整配置请前往“优化加速”面板。')
        );
        $form->addInput($enabled);

        foreach (Settings::defaults() as $key => $value) {
            if ($key === 'enabled') {
                continue;
            }

            $form->addInput(new Hidden($key, null, (string) ($settings[$key] ?? $value)));
        }
    }

    public static function configHandle(array $settings, bool $_isInit): void
    {
        $current = Settings::load();
        Settings::store(array_merge($current, $settings));
    }

    private static function registerHooks(): void
    {
        Hook::factory('index.php')->{'begin_05'} = [Avatar::class, 'bootstrapPrefix'];
        Hook::factory('index.php')->{'begin_15'} = [Output::class, 'boot'];
        Hook::factory('admin/header.php')->{'header_05'} = [Avatar::class, 'filterAdminHeader'];
        Hook::factory('Widget\\Archive')->{'header_20'} = [Image::class, 'header'];
        Hook::factory('Widget\\Archive')->{'header_30'} = [Avatar::class, 'header'];
        Hook::factory('Widget\\Base\\Comments')->gravatar = [Avatar::class, 'render'];
        Hook::factory('Widget\\Upload')->upload = [Image::class, 'upload'];
        Hook::factory('Widget\\Upload')->modify = [Image::class, 'modify'];
        Hook::factory('Widget\\Upload')->deleteHandle = [Image::class, 'deleteHandle'];
        Hook::factory('Widget\\Base\\Contents')->contentEx = [Image::class, 'contentEx'];
        Hook::factory('Widget\\Base\\Contents')->excerptEx = [Image::class, 'excerptEx'];
        Hook::factory('Widget\\Base\\Comments')->contentEx = [Image::class, 'commentEx'];
    }
}
