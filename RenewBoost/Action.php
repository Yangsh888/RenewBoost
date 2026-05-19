<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewBoost;

use Widget\Notice;
use Widget\Security;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends \Typecho\Widget
{
    public function action(): void
    {
        $this->guard();
        $this->requirePost();

        $do = trim((string) $this->request->get('do'));
        match ($do) {
            'save' => $this->save(),
            default => $this->error('未知操作'),
        };
    }

    private function save(): void
    {
        $data = [];
        foreach (Settings::boolKeys() as $key) {
            $data[$key] = '0';
        }

        foreach (array_keys(Settings::defaults()) as $key) {
            if (array_key_exists($key, $_POST)) {
                $value = $_POST[$key];
                $data[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        $tab = $this->tab((string) $this->request->get('tab'));

        $current = Settings::loadFresh();
        $next = array_merge($current, $data);

        $notice = '配置已保存';
        $noticeType = 'success';
        if (($next['outputEnabled'] ?? '0') === '1') {
            $env = Output::configEnvironment();
            if (!(bool) ($env['ready'] ?? false)) {
                $next['outputEnabled'] = '0';
                $notice .= '，输出压缩未满足启用条件，已自动保持关闭：' . Output::configUnavailableReason($env);
                $noticeType = 'notice';
            }
        }
        if (($next['outputAltSvc'] ?? '0') === '1' && !Output::altSvcReady()) {
            $next['outputAltSvc'] = '0';
            $notice .= '，Alt-Svc 仅支持 HTTPS 站点输出，已自动保持关闭';
            $noticeType = 'notice';
        }

        Settings::store($next);
        Notice::alloc()->set($notice, $noticeType);
        $this->response->redirect(Settings::panelQueryUrl(['tab' => $tab]));
    }

    private function guard(): void
    {
        User::alloc()->pass('administrator');
        Security::alloc()->protect();
    }

    private function requirePost(): void
    {
        if ($this->request->isPost()) {
            return;
        }

        $this->response->setStatus(405);
        $this->error('该操作必须通过 POST 提交');
    }

    private function error(string $message): void
    {
        Notice::alloc()->set($message, 'error');
        $this->response->redirect(Settings::panelUrl());
        exit;
    }

    private function tab(string $tab): string
    {
        $tab = trim($tab);
        return in_array($tab, Settings::tabs(), true) ? $tab : 'global';
    }
}
