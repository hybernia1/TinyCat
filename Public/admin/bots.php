<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();
require_once base_path('App/BotAdmin.php');

$sourceAction = (string) ($botAdminAction ?? '');

if (in_array($sourceAction, ['run', 'toggle'], true)) {
    api_endpoint('POST', static function () use ($sourceAction): never {
        csrf_require();
        $sourceId = max(1, (int) input('source_id', 0));
        $source = tc_admin_bot_source_for_action($sourceId, max(0, (int) input('bot_id', 0)));

        if ($sourceAction === 'toggle') {
            tc_admin_bot_toggle_source($source);
            $message = t('bots.messages.saved');
        } else {
            $result = tc_admin_bot_run_source($source);
            $failed = (string) ($result['status'] ?? '') === 'error';
            $message = t($failed ? 'bots.detail_run_failed' : 'bots.detail_run_finished');
        }

        $payload = BotAdmin::payload($sourceId);
        $redirect = auth_safe_next_url((string) input('redirect', ''));
        if ($redirect !== '') {
            flash('success', $message);
            $payload['redirect'] = $redirect;
        }

        api_ok($payload, $message);
    });
}

if (method() === 'GET') {
    api_ok(BotAdmin::payload());
}

if (in_array(method(), ['POST', 'PATCH'], true)) {
    api_endpoint(method(), static function (): never {
        csrf_require();
        $id = method() === 'PATCH' ? max(1, (int) input('id', 0)) : null;

        if ($id !== null && bot_source_find($id) === null) {
            api_error(t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        $payload = BotAdmin::sourcePayload($id);

        try {
            if ($id === null) {
                $id = (int) insert('bot_sources', $payload + ['created_at' => date_db()]);
                $message = t('bots.messages.created');
            } else {
                update('bot_sources', $payload, ['id' => $id]);
                $message = t('bots.messages.saved');
            }
        } catch (Throwable $exception) {
            if (
                bot_source_duplicate_exception($exception)
                && bot_source_duplicate_exists((string) ($payload['feed_url'] ?? ''), (int) $id)
            ) {
                api_validation(['feed_url' => [t('bots.validation.feed_duplicate')]]);
            }

            throw $exception;
        }

        api_ok(BotAdmin::payload($id), $message);
    });
}

if (method() === 'DELETE') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id', 0));

        if (bot_source_find($id) === null) {
            api_error(t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        bot_delete_source($id);
        api_ok(BotAdmin::payload(), t('bots.messages.deleted'));
    });
}

api_error('Method not allowed.', 405, 'method_not_allowed');

function tc_admin_bot_source_for_action(int $sourceId, int $botId = 0): array
{
    $source = bot_source_find($sourceId);

    if ($source === null || ($botId > 0 && (int) ($source['bot_user_id'] ?? 0) !== $botId)) {
        api_error(t('bots.messages.not_found'), 404, 'bot_source_not_found');
    }

    $bot = one(
        'SELECT id, status FROM users WHERE id = ? AND role = ? LIMIT 1',
        [(int) ($source['bot_user_id'] ?? 0), 'bot']
    );

    if ($bot === null) {
        api_error(t('bots.messages.not_found'), 404, 'bot_account_not_found');
    }

    $source['bot_status'] = (string) ($bot['status'] ?? '');

    return $source;
}

function tc_admin_bot_run_source(array $source): array
{
    if ((string) ($source['bot_status'] ?? '') !== 'active') {
        api_error(t('bots.detail_bot_inactive'), 409, 'bot_inactive');
    }

    if (!(bool) ($source['enabled'] ?? false)) {
        api_error(t('bots.detail_source_disabled'), 409, 'bot_source_disabled');
    }

    return bot_run_source($source, true);
}

function tc_admin_bot_toggle_source(array $source): void
{
    $enabled = (bool) ($source['enabled'] ?? false);

    if (!$enabled && (string) ($source['bot_status'] ?? '') !== 'active') {
        api_error(t('bots.detail_bot_inactive'), 409, 'bot_inactive');
    }

    update('bot_sources', [
        'enabled' => $enabled ? 0 : 1,
        'next_run_at' => $enabled ? null : date_db(),
        'last_error' => null,
    ], ['id' => (int) ($source['id'] ?? 0)]);
}
