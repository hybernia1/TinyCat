<?php
declare(strict_types=1);

namespace TinyCat\Tools\Performance;

use RuntimeException;

final class HttpLoadRunner
{
    /** @return array<string, mixed> */
    public function request(string $url): array
    {
        return $this->run($url, 1, 1);
    }

    /** @return array<string, mixed> */
    public function run(string $url, int $requests, int $concurrency): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP curl extension is required.');
        }

        $multi = curl_multi_init();
        $active = [];
        $completed = 0;
        $durations = [];
        $timeToFirstByte = [];
        $sizes = [];
        $statuses = [];
        $errors = [];
        $fatalResponses = 0;
        $started = hrtime(true);

        try {
            while ($completed < $requests) {
                while (count($active) < $concurrency && count($active) + $completed < $requests) {
                    $handle = curl_init($url);
                    if ($handle === false) {
                        throw new RuntimeException('Unable to initialize cURL.');
                    }
                    curl_setopt_array($handle, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT_MS => 3000,
                        CURLOPT_TIMEOUT_MS => 30000,
                        CURLOPT_ENCODING => '',
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_HTTPHEADER => [
                            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                            'User-Agent: TinyCat-Comparative-Benchmark/1.0',
                        ],
                    ]);
                    curl_multi_add_handle($multi, $handle);
                    $active[spl_object_id($handle)] = $handle;
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('cURL multi error: ' . curl_multi_strerror($status));
                }

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $body = curl_multi_getcontent($handle);
                    $curlInfo = curl_getinfo($handle);
                    if ($curlInfo === false) {
                        throw new RuntimeException('Unable to read cURL transfer information.');
                    }
                    $code = (int) $curlInfo['http_code'];
                    $statuses[$code] = ($statuses[$code] ?? 0) + 1;
                    $durations[] = (float) $curlInfo['total_time'] * 1000;
                    $timeToFirstByte[] = (float) $curlInfo['starttransfer_time'] * 1000;
                    $sizes[] = (int) $curlInfo['size_download'];
                    if ($info['result'] !== CURLE_OK) {
                        $message = curl_error($handle) ?: curl_strerror($info['result']);
                        $errors[$message] = ($errors[$message] ?? 0) + 1;
                    }
                    if (is_string($body) && preg_match('/(?:Fatal error|Uncaught (?:Error|Exception)|<b>Warning<\/b>)/i', $body) === 1) {
                        $fatalResponses++;
                    }
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($active[spl_object_id($handle)]);
                    $completed++;
                }

                if ($running > 0) {
                    $selected = curl_multi_select($multi, 1.0);
                    if ($selected === -1) {
                        usleep(1000);
                    }
                }
            }
        } finally {
            foreach ($active as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }

        $wallSeconds = max(0.000001, (hrtime(true) - $started) / 1_000_000_000);
        $successes = array_sum(array_filter(
            $statuses,
            static fn (int $count, int $code): bool => $code >= 200 && $code < 400,
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            'requests' => $requests,
            'concurrency' => $concurrency,
            'wall_seconds' => round($wallSeconds, 6),
            'requests_per_second' => round($requests / $wallSeconds, 3),
            'successes' => $successes,
            'failures' => $requests - $successes + array_sum($errors),
            'fatal_responses' => $fatalResponses,
            'status_codes' => $statuses,
            'errors' => $errors,
            'latency_ms' => $this->distribution($durations),
            'ttfb_ms' => $this->distribution($timeToFirstByte),
            'response_bytes' => $this->distribution(array_map('floatval', $sizes)),
        ];
    }

    /**
     * @param list<float> $values
     * @return array<string, float>
     */
    private function distribution(array $values): array
    {
        if ($values === []) {
            return ['min' => 0.0, 'mean' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'max' => 0.0];
        }
        sort($values, SORT_NUMERIC);

        return [
            'min' => round($values[0], 3),
            'mean' => round(array_sum($values) / count($values), 3),
            'p50' => round($this->percentile($values, 0.50), 3),
            'p95' => round($this->percentile($values, 0.95), 3),
            'p99' => round($this->percentile($values, 0.99), 3),
            'max' => round($values[array_key_last($values)], 3),
        ];
    }

    /** @param non-empty-list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        $rank = max(0, (int) ceil($percentile * count($values)) - 1);

        return $values[min($rank, count($values) - 1)];
    }
}
