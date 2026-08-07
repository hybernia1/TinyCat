<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$mode = auth_modal_mode((string) ($mode ?? 'login'));
$isRegister = $mode === 'register';
$next = auth_safe_next_url((string) ($next ?? ''));

echo render('modals/layout', [
    'id' => auth_modal_id(),
    'title' => t($isRegister ? 'auth.register_title' : 'auth.login_title'),
    'icon' => $isRegister ? 'user-plus' : 'login',
    'modalClass' => 'modal-mobile-fullscreen',
    'size' => 'auth-modal-panel',
    'bodyClass' => 'auth-modal-body',
    'body' => part('auth/content', ['mode' => $mode, 'next' => $next]),
]);
