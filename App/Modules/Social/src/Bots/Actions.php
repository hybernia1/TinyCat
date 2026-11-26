<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

use Throwable;
use InvalidArgumentException;

final class Actions
{
    /**
     * @return array<mixed>
     */
    public static function adminBotAccountCreate(): array
    {
        $id = (int) \TinyCat\Database\Connection::insert('users', \TinyCat\Social\Bots\Administration::accountCreatePayload());
        return \TinyCat\Social\Bots\Administration::accountsPayload($id) + ['message' => \TinyCat\Support\Localization::t('bots.messages.account_created'), 'code' => 'bot_account_created'];
    }

    /**
     * @return array<mixed>
     */
    public static function adminBotAccountUpdate(int $id): array
    {
        \TinyCat\Http\Request::current()->validated(['status' => 'string', 'bio' => 'string']);
        $account = \TinyCat\Social\Bots\Administration::accountById($id);

        if ($account === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found');
        }

        $status = (string) \TinyCat\Http\Request::current()->input('status', $account['status']);
        if (!in_array($status, \TinyCat\Core\Identity\Administration::statuses(), true)) {
            \TinyCat\Http\Response::validation(['status' => [\TinyCat\Http\Response::issue('user_status_invalid', \TinyCat\Support\Localization::t('users.validation.status_invalid'))]]);
        }

        $payload = [
            'bio' => \TinyCat\Core\Text\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('bio', ''), 500),
        ];
        $statusProvided = \TinyCat\Http\Request::current()->input('status', null) !== null;
        if ($statusProvided) { $payload['status'] = $status; }
        try {
            \TinyCat\Core\Identity\Avatars::admin_user_avatar_save($account, $payload, static function () use ($id, $status, $statusProvided): void {
                if ($statusProvided && $status !== 'active') {
                    \TinyCat\Database\Connection::update('bot_sources', ['enabled' => 0], ['bot_user_id' => $id]);
                }
            });
        } catch (InvalidArgumentException $exception) {
            \TinyCat\Http\Response::error($exception->getMessage(), 422, 'avatar_invalid');
        }

        $response = \TinyCat\Social\Bots\Administration::accountsPayload($id);

        return $response + ['message' => \TinyCat\Support\Localization::t('bots.messages.saved'), 'code' => 'bot_saved'];
    }

    /**
     * @return array<mixed>
     */
    public static function adminBotAccountDelete(int $id): array
    {
        $account = \TinyCat\Social\Bots\Administration::accountById($id);

        if ($account === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found');
        }

        \TinyCat\Core\Identity\Lifecycle::delete($id, \TinyCat\Core\Identity\Participants::current(true));
        return \TinyCat\Social\Bots\Administration::accountsPayload() + ['message' => \TinyCat\Support\Localization::t('bots.messages.account_deleted'), 'code' => 'bot_account_deleted'];
    }

    /**
     * @return array<mixed>
     */
    public static function adminBotSourceCreate(): array
    {
        return self::saveBotSource(null);
    }

    /**
     * @return array<mixed>
     */
    public static function adminBotSourceUpdate(int $id): array
    {
        return self::saveBotSource($id);
    }

    /**
     * @return array<mixed>
     */
    public static function adminBotSourceDelete(int $id): array
    {

        if (\TinyCat\Social\Bots\Importer::findSource($id) === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        \TinyCat\Social\Bots\Importer::deleteSource($id);
        return \TinyCat\Social\Bots\Administration::payload() + ['message' => \TinyCat\Support\Localization::t('bots.messages.deleted'), 'code' => 'bot_deleted'];
    }

    public static function adminBotSourceAction(int $sourceId): \TinyCat\Http\Result
    {
        $source = \TinyCat\Social\Bots\Administration::sourceForAction($sourceId, max(0, (int) \TinyCat\Http\Request::current()->input('bot_id', 0)));

        $result = \TinyCat\Social\Bots\Administration::runSource($source);
        if (($result['status'] ?? '') === 'already_running') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('cron.already_running'), 409, 'scheduler_already_running');
        }
        $failed = empty($result['ok']);
        $message = \TinyCat\Support\Localization::t($failed ? 'bots.detail_run_failed' : 'bots.detail_run_finished');
        $code = $failed ? 'bot_run_failed' : 'bot_run_completed';

        $payload = \TinyCat\Social\Bots\Administration::payload($sourceId);

        return new \TinyCat\Http\Result($payload + ['execution' => $result], $message, ['type' => $failed ? 'danger' : 'success'], $code);
    }

    /**
     * @return array<mixed>
     */
    private static function saveBotSource(?int $id): array
    {
        if ($id !== null && \TinyCat\Social\Bots\Importer::findSource($id) === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        $payload = \TinyCat\Social\Bots\Administration::sourcePayload($id);

        try {
            if ($id === null) {
                $id = (int) \TinyCat\Database\Connection::insert('bot_sources', $payload + ['created_at' => \TinyCat\Support\Dates::dateDb()]);
                $message = \TinyCat\Support\Localization::t('bots.messages.created');
                $code = 'bot_created';
            } else {
                \TinyCat\Database\Connection::update('bot_sources', $payload, ['id' => $id]);
                $message = \TinyCat\Support\Localization::t('bots.messages.saved');
                $code = 'bot_saved';
            }
        } catch (Throwable $exception) {
            if (
                \TinyCat\Social\Bots\Importer::isDuplicateSourceException($exception)
                && \TinyCat\Social\Bots\Importer::sourceDuplicateExists((string) ($payload['feed_url'] ?? ''), (int) $id)
            ) {
                \TinyCat\Http\Response::validation(['feed_url' => [\TinyCat\Http\Response::issue('bot_feed_duplicate', \TinyCat\Support\Localization::t('bots.validation.feed_duplicate'))]]);
            }

            throw $exception;
        }

        return \TinyCat\Social\Bots\Administration::payload($id) + ['message' => $message, 'code' => $code];
    }

}
