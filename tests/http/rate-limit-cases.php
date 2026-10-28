<?php
declare(strict_types=1);

// Keep one session across requests to exercise actual cooldown enforcement.
$resetRateDb = new PDO('sqlite:' . $databasePath);
$resetRateDb->exec("DELETE FROM ip_action_limits WHERE ip_address = 'user:2' AND action_name = 'post'");
$resetRateDb = null;
$rateHeaders = [...$csrf, 'Cookie' => 'PHPSESSID=rate-limit-' . bin2hex(random_bytes(12))];
[$status] = $request('POST', '/api/statuses', ['body' => ''], 'user', $rateHeaders);
$assert($status === 422, 'Empty posts fail validation.');
[$status, $created] = $request('POST', '/api/statuses', ['body' => 'Cooldown regression original text'], 'user', $rateHeaders);
$assert($status === 201, 'Validation errors do not consume the publishing cooldown.');
$ratePostId = $created['data']['item']['id'];
[$status] = $request('PATCH', '/api/statuses/' . $ratePostId, ['body' => 'Cooldown regression corrected text'], 'user', $rateHeaders);
$assert($status === 200, 'A published post can be corrected immediately.');
[$status, $limited, $headers] = $request('POST', '/api/statuses', ['body' => 'Another cooldown regression post'], 'user', $rateHeaders);
$wait = $limited['error']['details']['retry_after'] ?? 0;
$assert($status === 429 && $limited['code'] === 'action_too_fast' && $wait >= 1 && $wait <= 5, 'Repeated publication remains rate limited with a bounded wait.');
$assert(in_array('Retry-After: ' . $wait, $headers, true), 'The Retry-After header matches the response details.');
[$status] = $request('DELETE', '/api/statuses/' . $ratePostId, [], 'user', $rateHeaders);
$assert($status === 200, 'Publishing and editing do not block deletion.');

$rateDb = new PDO('sqlite:' . $databasePath);
$rateDb->exec("UPDATE ip_action_limits SET action_count = 9999 WHERE ip_address = 'user:2' AND action_name = 'post'");
[$status, $limited, $headers] = $request('POST', '/api/statuses', ['body' => 'Hourly account quota regression'], 'user', $csrf);
$wait = $limited['error']['details']['retry_after'] ?? 0;
$assert($status === 429 && $limited['code'] === 'action_limited' && $wait >= 1 && $wait <= 3600, 'Account quotas expose the remaining fixed-window wait.');
$assert(in_array('Retry-After: ' . $wait, $headers, true), 'Hourly quotas return a matching Retry-After header.');
$rateDb = null;
