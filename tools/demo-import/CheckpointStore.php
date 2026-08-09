<?php
declare(strict_types=1);

namespace TinyCat\Tools\DemoImport;

use RuntimeException;

final readonly class CheckpointStore
{
    public function __construct(private string $path)
    {
    }

    /**
     * @param array<string, int|string> $configuration
     * @return array<string, mixed>
     */
    public function load(string $fingerprint, array $configuration, bool $reset, bool $resume): array
    {
        if ($reset || !is_file($this->path)) {
            return [
                'schema' => 1,
                'fingerprint' => $fingerprint,
                'configuration' => $configuration,
                'phase' => 'prepare',
                'cursor' => 0,
                'batches' => 0,
                'stats' => [],
                'started_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'complete' => false,
            ];
        }
        if (!$resume) {
            throw new RuntimeException('Checkpoint exists. Use --resume=1 or start again with --reset=1.');
        }

        $state = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['schema'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported demo-import checkpoint.');
        }
        if (!hash_equals($fingerprint, (string) ($state['fingerprint'] ?? ''))) {
            throw new RuntimeException('Checkpoint options differ from this import. Reset or restore the original options.');
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create checkpoint directory.');
        }
        $state['updated_at'] = gmdate('c');
        $temporary = $this->path . '.tmp-' . getmypid();
        $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write demo-import checkpoint.');
        }
        if (is_file($this->path) && !unlink($this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace demo-import checkpoint.');
        }
        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish demo-import checkpoint.');
        }
    }
}
