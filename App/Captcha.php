<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Session-bound slider CAPTCHA challenges.
 */
final class Captcha
{
    private const string ANSWER_FIELD = 'tc_captcha';
    private const string TOKEN_FIELD = 'tc_captcha_token';
    private const int CHALLENGE_TTL = 600;
    private const int FAILURE_WINDOW = 900;
    private const int MAX_ACTIVE_CHALLENGES = 5;

    public static function field(string $context = 'form'): string
    {
        if (!self::enabled()) {
            return '';
        }

        $challenge = self::challenge($context, true);
        $pieceTop = (int) ($challenge['piece_top'] ?? 42);

        return '<div class="field captcha-puzzle" data-captcha data-captcha-hint="' . Core::e(Core::t('security.captcha_hint')) . '" style="--captcha-y: ' . Core::e($pieceTop) . '%;">'
            . '<span class="label">' . Core::e(Core::t('security.captcha_label')) . '</span>'
            . '<input type="hidden" name="' . self::ANSWER_FIELD . '" value="" data-captcha-answer required>'
            . '<input type="hidden" name="' . self::TOKEN_FIELD . '" value="' . Core::e((string) $challenge['token']) . '">'
            . '<div class="captcha-board" aria-hidden="true">'
            . '<img class="captcha-image" src="' . Core::e(self::boardDataUri($challenge)) . '" alt="" draggable="false">'
            . '<img class="captcha-piece" src="' . Core::e(self::pieceDataUri($challenge)) . '" alt="" draggable="false">'
            . '</div>'
            . '<label class="captcha-slider-label">'
            . '<span class="sr-only">' . Core::e(Core::t('security.captcha_slider')) . '</span>'
            . '<input class="captcha-slider" type="range" min="8" max="92" step="1" value="8" data-captcha-slider>'
            . '</label>'
            . '<span class="captcha-hint" data-captcha-status>' . Core::e(Core::t('security.captcha_hint')) . '</span>'
            . '</div>';
    }

    public static function check(string $context = 'form'): bool
    {
        if (!self::enabled()) {
            return true;
        }

        $answer = trim((string) Core::payload(self::ANSWER_FIELD, ''));
        $token = trim((string) Core::payload(self::TOKEN_FIELD, ''));
        $challenge = self::storedChallenge($context, $token);

        if (self::failureLocked($context) || $challenge === [] || $answer === '') {
            self::forgetChallenge($context, $token);
            self::recordFailure($context);
            return false;
        }

        [$position, $elapsed, $moves, $method] = array_pad(explode(':', $answer, 4), 4, '');
        $target = (int) ($challenge['target'] ?? -1);
        $issuedAt = (float) ($challenge['issued_at'] ?? 0);
        $serverElapsedMs = $issuedAt > 0 ? (int) floor((microtime(true) - $issuedAt) * 1000) : 0;
        $valid = (int) ($challenge['expires'] ?? 0) >= time()
            && is_numeric($position)
            && abs((float) $position - $target) <= 2
            && is_numeric($elapsed) && (int) $elapsed >= 500
            && $serverElapsedMs >= 500
            && is_numeric($moves) && (int) $moves >= 1
            && in_array($method, ['pointer', 'mouse', 'touch', 'keyboard'], true);

        self::forgetChallenge($context, $token);
        $valid ? self::clearFailures($context) : self::recordFailure($context);

        return $valid;
    }

    public static function refresh(string $context = 'form'): string
    {
        if (!self::enabled()) {
            return '';
        }

        return (string) (self::challenge($context, true)['token'] ?? '');
    }

    public static function loginRequired(): bool
    {
        if (!self::enabled()) {
            return false;
        }

        Core::session();
        $state = $_SESSION['_tinycat_login_guard'] ?? [];
        $updatedAt = is_array($state) ? (int) ($state['updated_at'] ?? 0) : 0;

        if ($updatedAt < time() - self::FAILURE_WINDOW) {
            unset($_SESSION['_tinycat_login_guard']);
            $sessionFailures = 0;
        } else {
            $sessionFailures = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        }

        return $sessionFailures >= self::loginFailureLimit()
            || rate_limit_ip_action_count('login_failed', self::FAILURE_WINDOW) >= self::loginFailureLimit();
    }

    public static function recordLoginFailure(): void
    {
        Core::session();
        $state = $_SESSION['_tinycat_login_guard'] ?? [];
        $now = time();
        $updatedAt = is_array($state) ? (int) ($state['updated_at'] ?? 0) : 0;
        $count = is_array($state) && $updatedAt >= $now - self::FAILURE_WINDOW
            ? (int) ($state['count'] ?? 0) + 1
            : 1;

        $_SESSION['_tinycat_login_guard'] = ['count' => $count, 'updated_at' => $now];
        rate_limit_ip_action_record('login_failed', self::FAILURE_WINDOW);
    }

    public static function clearLoginFailures(): void
    {
        Core::session();
        unset($_SESSION['_tinycat_login_guard']);
    }

    private static function enabled(): bool
    {
        return (bool) Core::config('security.captcha.enabled', true);
    }

    private static function loginFailureLimit(): int
    {
        return max(1, min(10, (int) Core::config('security.captcha.login_attempts', 3)));
    }

    private static function challenge(string $context, bool $refresh): array
    {
        Core::session();
        $key = self::sessionKey($context);
        $challenges = self::activeChallenges($context);

        if (!$refresh && $challenges !== []) {
            return reset($challenges) ?: [];
        }

        $target = random_int(18, 82);
        $pieceTop = random_int(24, 62);
        $challenge = [
            'token' => bin2hex(random_bytes(16)),
            'target' => $target,
            'piece_top' => $pieceTop,
            'decoys' => self::decoys($target, $pieceTop),
            'issued_at' => microtime(true),
            'expires' => time() + self::CHALLENGE_TTL,
        ];
        $challenges[(string) $challenge['token']] = $challenge;
        uasort($challenges, static fn (array $a, array $b): int => (float) ($b['issued_at'] ?? 0) <=> (float) ($a['issued_at'] ?? 0));
        $_SESSION[$key] = array_slice($challenges, 0, self::MAX_ACTIVE_CHALLENGES, true);

        return $challenge;
    }

    private static function storedChallenge(string $context, string $token): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return [];
        }

        $challenge = self::activeChallenges($context)[$token] ?? null;

        return self::validChallenge($challenge) ? $challenge : [];
    }

    private static function activeChallenges(string $context): array
    {
        Core::session();
        $key = self::sessionKey($context);
        $stored = $_SESSION[$key] ?? [];

        // Upgrade an in-flight challenge created before multi-tab support.
        if (self::validChallenge($stored)) {
            $stored = [(string) $stored['token'] => $stored];
        }

        $challenges = [];
        foreach ((array) $stored as $token => $challenge) {
            if (is_string($token) && self::validChallenge($challenge) && hash_equals($token, (string) $challenge['token'])) {
                $challenges[$token] = $challenge;
            }
        }

        if ($challenges === []) {
            unset($_SESSION[$key]);
        } else {
            $_SESSION[$key] = $challenges;
        }

        return $challenges;
    }

    private static function forgetChallenge(string $context, string $token): void
    {
        if ($token === '') {
            return;
        }

        $challenges = self::activeChallenges($context);
        unset($challenges[$token]);

        $key = self::sessionKey($context);
        if ($challenges === []) {
            unset($_SESSION[$key]);
            return;
        }

        $_SESSION[$key] = $challenges;
    }

    private static function validChallenge(mixed $challenge): bool
    {
        return is_array($challenge)
            && (int) ($challenge['expires'] ?? 0) >= time()
            && is_string($challenge['token'] ?? null);
    }

    private static function failureLocked(string $context): bool
    {
        Core::session();
        $state = $_SESSION[self::failureKey($context)] ?? [];

        if (!is_array($state) || (int) ($state['updated_at'] ?? 0) < time() - self::FAILURE_WINDOW) {
            unset($_SESSION[self::failureKey($context)]);
            return false;
        }

        return (int) ($state['lock_until'] ?? 0) > time();
    }

    private static function recordFailure(string $context): void
    {
        Core::session();
        $key = self::failureKey($context);
        $state = $_SESSION[$key] ?? [];
        $now = time();
        $count = is_array($state) && (int) ($state['updated_at'] ?? 0) >= $now - self::FAILURE_WINDOW
            ? (int) ($state['count'] ?? 0) + 1
            : 1;

        $_SESSION[$key] = [
            'count' => $count,
            'updated_at' => $now,
            'lock_until' => $count >= 4 ? $now + min(20, 2 * ($count - 3)) : 0,
        ];
    }

    private static function clearFailures(string $context): void
    {
        Core::session();
        unset($_SESSION[self::failureKey($context)]);
    }

    private static function boardDataUri(array $challenge): string
    {
        $targetX = (int) round(420 * ((int) ($challenge['target'] ?? 50)) / 100);
        $targetY = (int) round(128 * ((int) ($challenge['piece_top'] ?? 42)) / 100);
        $slots = '';

        foreach ((array) ($challenge['decoys'] ?? []) as $decoy) {
            $x = (int) round(420 * ((int) ($decoy['x'] ?? 50)) / 100);
            $y = (int) round(128 * ((int) ($decoy['y'] ?? 42)) / 100);
            $slots .= '<rect x="' . ($x - 21) . '" y="' . ($y - 21) . '" width="42" height="42" rx="9" fill="#f8fcfc" stroke="#86aaa7" stroke-width="3" stroke-dasharray="6 5"/>';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="128" viewBox="0 0 420 128">'
            . '<defs><pattern id="g" width="24" height="24" patternUnits="userSpaceOnUse"><path d="M24 0H0V24" fill="none" stroke="#d4e4e3"/></pattern></defs>'
            . '<rect width="420" height="128" fill="#e7f0f4"/><rect width="420" height="128" fill="url(#g)"/>' . $slots
            . '<rect x="' . ($targetX - 21) . '" y="' . ($targetY - 21) . '" width="42" height="42" rx="9" fill="#f8fcfc" stroke="#0f766e" stroke-width="4"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function pieceDataUri(array $challenge): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect x="11" y="11" width="42" height="42" rx="9" fill="#0f766e" stroke="#0d5f58" stroke-width="4"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function decoys(int $target, int $pieceTop): array
    {
        $decoys = [];

        while (count($decoys) < 3) {
            $x = random_int(18, 82);
            $y = random_int(22, 76);
            if (abs($x - $target) < 17 && abs($y - $pieceTop) < 20) {
                continue;
            }

            foreach ($decoys as $decoy) {
                if (abs($x - (int) $decoy['x']) < 17 && abs($y - (int) $decoy['y']) < 20) {
                    continue 2;
                }
            }

            $decoys[] = ['x' => $x, 'y' => $y];
        }

        return $decoys;
    }

    private static function sessionKey(string $context): string
    {
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', $context) ?? 'form';
        $context = trim($context, '_-');
        return '_captcha_' . ($context !== '' ? $context : 'form');
    }

    private static function failureKey(string $context): string
    {
        return self::sessionKey($context) . '_failures';
    }
}
