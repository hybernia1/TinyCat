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
$emailDb->exec("UPDATE ip_action_limits SET action_count = 60 WHERE action_name = 'email-check'");
[$status, $limited, $headers] = $request('POST', '/api/users/registrations/email-checks', ['email' => 'free@example.test'], '', $csrf);
$assert($status === 429 && $limited['code'] === 'email_check_limited', 'Public enumeration requests are bounded by the shared IP limiter.');
$emailDb->exec("DELETE FROM ip_action_limits WHERE action_name = 'email-check'");
$emailDb = null;
