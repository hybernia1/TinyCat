<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

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
