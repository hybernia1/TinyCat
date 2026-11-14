<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

final class RunResource
{
    /** @param array<mixed> $run
     * @return array<string, mixed> */
    public static function make(array $run): array
    {
        $task = (string) $run['task'];
        $options = json_decode((string) ($run['options_json'] ?? '{}'), true) ?: [];
        $summary = json_decode((string) ($run['summary_json'] ?? '{}'), true) ?: [];
        return [
            'id' => (int) $run['id'], 'task' => $task,
            'detail_url' => '/api/scheduler/runs/' . (int) $run['id'],
            'started_at' => \TinyCat\Support\Dates::dateIso((string) $run['started_at']), 'finished_at' => !empty($run['finished_at']) ? \TinyCat\Support\Dates::dateIso((string) $run['finished_at']) : null,
            'duration_ms' => $run['duration_ms'] !== null ? (int) $run['duration_ms'] : null,
            'status' => (string) $run['status'],
            'mode' => (string) $run['mode'],
            'options' => $options,
            'summary' => $summary, 'error' => (string) ($run['error'] ?? ''),
        ];
    }
}
