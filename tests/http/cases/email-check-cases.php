<?php
declare(strict_types=1);

[$status] = $request('POST', '/api/users/registrations/email-checks', ['email' => 'free@example.test']);
$assert($status === 403, 'Public email checks require CSRF protection.');
[$status, $available, $headers] = $request('POST', '/api/users/registrations/email-checks', ['email' => 'free@example.test'], '', $csrf);
$assert($status === 200 && $available['data'] === ['available' => true] && str_contains(implode(' ', $headers), 'no-store'), 'Email availability returns only a boolean and is not cacheable.');
[$status, $taken] = $request('POST', '/api/users/registrations/email-checks', ['email' => ' MEMBER@EXAMPLE.TEST '], '', $csrf);
$assert($status === 200 && !$taken['data']['available'], 'Registration checks normalize email addresses and detect occupied addresses.');
[$status, $invalid] = $request('POST', '/api/users/registrations/email-checks', ['email' => 'not-an-email'], '', $csrf);
$assert($status === 422 && isset($invalid['error']['details']['errors']['email']), 'Invalid email syntax follows standard field validation.');
[$status, $own] = $request('POST', '/api/users/me/email-checks', ['email' => 'member@example.test'], 'user', $csrf);
$assert($status === 200 && $own['data']['available'], 'Profile checks exclude the current account email.');
[$status] = $request('POST', '/api/users/1/email-checks', ['email' => 'member@example.test'], 'user', $csrf);
$assert($status === 403, 'Users cannot exclude someone else from the uniqueness check.');
[$status, $admin] = $request('POST', '/api/users/2/email-checks', ['email' => 'member@example.test'], 'admin', $csrf);
$assert($status === 200 && $admin['data']['available'], 'Administrators can check the profile they are authorized to edit.');
[$status] = $request('POST', '/api/users/me/email-checks', ['email' => 'free@example.test'], '', $csrf);
$assert($status === 401, 'Profile email checks require authentication.');
$emailDb = new PDO('sqlite:' . $databasePath);
// Seed the observed bucket and its successors: a minute boundary between
// the previous request and this assertion must not turn a full bucket into a new one.
$emailLimitRows = $emailDb->query("SELECT ip_address, MAX(bucket_start) AS bucket_start FROM ip_action_limits WHERE action_name = 'email-check' GROUP BY ip_address")->fetchAll(PDO::FETCH_ASSOC);
if ($emailLimitRows === []) { throw new RuntimeException('Email checks did not create a limiter subject.'); }
$emailLimitFill = $emailDb->prepare("INSERT INTO ip_action_limits (ip_address, action_name, bucket_start, action_count, updated_at) VALUES (?, 'email-check', ?, 60, ?) ON CONFLICT (ip_address, action_name, bucket_start) DO UPDATE SET action_count = 60");
foreach ($emailLimitRows as $emailLimitRow) {
    $emailLimitBucket = new DateTimeImmutable($emailLimitRow['bucket_start'], new DateTimeZone('UTC'));
    foreach ([0, 60, 120] as $emailLimitOffset) {
        $emailLimitStamp = $emailLimitBucket->modify('+' . $emailLimitOffset . ' seconds')->format('Y-m-d H:i:s');
        $emailLimitFill->execute([$emailLimitRow['ip_address'], $emailLimitStamp, $emailLimitStamp]);
    }
}
unset($emailLimitFill, $emailLimitRows);
[$status, $limited, $headers] = $request('POST', '/api/users/registrations/email-checks', ['email' => 'free@example.test'], '', $csrf);
$assert($status === 429 && $limited['code'] === 'email_check_limited', 'Public enumeration requests are bounded by the shared IP limiter.');
$emailDb->exec("DELETE FROM ip_action_limits WHERE action_name = 'email-check'");
$emailDb = null;
