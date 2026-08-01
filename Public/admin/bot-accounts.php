<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (method() === 'GET') {
    api_ok(BotAdmin::accountsPayload());
}

if (method() === 'POST') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $id = (int) insert('users', BotAdmin::accountCreatePayload());
        api_created(BotAdmin::accountsPayload($id), t('bots.messages.account_created'));
    });
}

if (method() === 'DELETE') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id', 0));
        $account = BotAdmin::accountById($id);

        if ($account === null) {
            api_error(t('bots.messages.account_not_found'), 404, 'bot_account_not_found');
        }

        db_transaction(static function () use ($id): void {
            bot_delete_sources_for_user($id);
            delete('user_profile_links', ['user_id' => $id]);
            delete('users', ['id' => $id]);
        });
        Avatar::delete($account['avatar_config'] ?? null);
        api_ok(BotAdmin::accountsPayload(), t('bots.messages.account_deleted'));
    });
}

api_error('Method not allowed.', 405, 'method_not_allowed');
