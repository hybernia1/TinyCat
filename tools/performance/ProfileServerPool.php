<?php
declare(strict_types=1);

namespace TinyCat\Tools\Performance;

use RuntimeException;

/** Independent loopback PHP workers sharing one isolated benchmark cache. */
final class ProfileServerPool
{
    private array $processes = [];
    private array $addresses = [];

    public function __construct(string $root, string $config, string $log, int $workers, int $actor = 0)
    {
        $env = getenv();
        $env['TINYCAT_PROFILE_CONFIG'] = $config;
        $env['TINYCAT_PROFILE_ROOT'] = $root;
        $env['TINYCAT_PROFILE_OUTPUT'] = $log;
        $env['TINYCAT_PROFILE_USER'] = (string) $actor;
        try {
            for ($worker = 0; $worker < $workers; $worker++) {
                $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
                if ($socket === false) { throw new RuntimeException($error); }
                $address = stream_socket_get_name($socket, false);
                fclose($socket);
                $process = proc_open([PHP_BINARY, '-d', 'opcache.enable=1', '-d', 'memory_limit=256M', '-S', $address, __DIR__ . '/profile-router.php'], [
                    0 => ['pipe', 'r'], 1 => ['file', $log . '.worker-' . $worker . '.server.log', 'a'], 2 => ['file', $log . '.worker-' . $worker . '.server.log', 'a'],
                ], $pipes, $root, $env);
                if (!is_resource($process)) { throw new RuntimeException('Unable to start a profiling worker.'); }
                fclose($pipes[0]);
                $this->processes[] = $process;
                $this->addresses[] = $address;
                $ready = false;
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                    if ($connection !== false) { fclose($connection); $ready = true; break; }
                    usleep(50000);
                }
                if (!$ready) { throw new RuntimeException('Profiling worker did not become ready.'); }
            }
        } catch (\Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    public function urls(string $path): array
    {
        return array_map(static fn (string $address): string => 'http://' . $address . $path, $this->addresses);
    }

    public function stop(): void
    {
        foreach ($this->processes as $process) { proc_terminate($process); proc_close($process); }
        $this->processes = [];
    }
}
