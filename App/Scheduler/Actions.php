<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Http\Result;
use TinyCat\Http\Response;
use TinyCat\Support\Localization;

final class Actions
{
    public static function listing(): Result
    {
        return self::listResult(Administration::payload());
    }

    /** @return array<string, mixed> */
    public static function detail(int $id): array
    {
        $item = Administration::runDetail($id);
        if ($item === null) { Response::error(Localization::t('cron.run_not_found'), 404, 'scheduler_run_not_found'); }
        return ['item' => $item];
    }

    public static function run(string $task): Result
    {
        $definition = Tasks::definitions()[$task] ?? null;
        if ($definition === null) { Response::error(Localization::t('cron.unknown_task'), 404, 'scheduler_unknown_task'); }
        $user = \TinyCat\Users\Authentication::auth();
        $execution = \TinyCat\Http\Session::withoutLock(static fn (): array => Execution::run($task, $definition['options'], 'admin', (int) ($user['id'] ?? 0))['tasks'][$task]);
        $code = empty($execution['ok']) ? 'scheduler_run_failed' : (($execution['status'] ?? '') === 'already_running' ? 'scheduler_already_running' : 'scheduler_run_finished');
        $key = match ($code) {
            'scheduler_run_failed' => 'cron.run_failed',
            'scheduler_already_running' => 'cron.already_running',
            default => 'cron.run_finished',
        };
        return self::listResult(Administration::payload() + ['execution' => $execution], Localization::t($key), $code, ['type' => empty($execution['ok']) ? 'danger' : 'success']);
    }

    public static function rotateToken(): Result
    {
        Tokens::cron_token_rotate();
        return new Result(['token' => Tokens::cron_token(), 'rotated' => true], Localization::t('cron.messages.token_rotated'), [], 'scheduler_token_rotated');
    }

    /** @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    private static function listResult(array $data, ?string $message = null, ?string $code = null, array $meta = []): Result
    {
        $pagination = $data['pagination'];
        unset($data['pagination']);
        return new Result($data, $message, ['pagination' => $pagination] + $meta, $code);
    }
}
