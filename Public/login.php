<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

tc_login_auth_schema();
$seeded = tc_login_seed_default_user();

if (is_post()) {
    csrf_require();

    if (auth_attempt([
        'email' => (string) post('email', ''),
        'password' => (string) post('password', ''),
    ])) {
        redirect(tc_login_next());
    }

    flash('error', 'Neplatné přihlášení.');
    redirect('/login?next=' . rawurlencode(tc_login_next()));
}

guest_only();

$error = flash('error');
$message = flash('success');
$seedLogin = (string) config('auth.seed.login', 'admin@example.test');
$seedPassword = (string) config('auth.seed.password', 'password');

layout('layout', [
    'title' => 'Login',
    'current' => '/login',
    'nav' => [
        ['href' => '/admin/users', 'icon' => 'users', 'label' => 'Users'],
    ],
], static function () use ($error, $message, $seeded, $seedLogin, $seedPassword): void {
    ?>
    <section class="grid md:grid-2" style="--grid-gap: 24px;">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('login') ?> Přihlášení</h1>
            </div>
            <div class="card-body stack">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= e($message) ?></div>
                <?php endif; ?>
                <form class="stack" method="post" action="/login?next=<?= e(rawurlencode(tc_login_next())) ?>">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span class="label">E-mail</span>
                        <input class="input" type="email" name="email" autocomplete="email" value="<?= e($seeded ? $seedLogin : '') ?>" required>
                    </label>
                    <label class="field">
                        <span class="label">Heslo</span>
                        <input class="input" type="password" name="password" autocomplete="current-password" required>
                    </label>
                    <button class="btn btn-primary" type="submit"><?= icon('login') ?> <span>Přihlásit</span></button>
                </form>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('shield') ?> Auth kontrakt</h2>
            </div>
            <div class="card-body stack">
                <div class="alert alert-info">Auth core je mapovaný přes <code>config.php</code>, takže tabulka i sloupce můžou být jiné podle projektu.</div>
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            <tr><th>Helper</th><td><code>auth()</code>, <code>auth_check()</code>, <code>require_auth()</code></td></tr>
                            <tr><th>Tabulka</th><td><code><?= e(config('auth.table', 'users')) ?></code></td></tr>
                            <tr><th>Login</th><td><code><?= e(config('auth.login', 'email')) ?></code></td></tr>
                            <tr><th>Password</th><td><code><?= e(config('auth.password', 'password')) ?></code></td></tr>
                            <?php if ((bool) config('auth.seed.enabled', true)): ?>
                                <tr><th>First run</th><td><code><?= e($seedLogin) ?></code> / <code><?= e($seedPassword) ?></code></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    </section>
    <?php
});

function tc_login_next(): string
{
    $next = (string) get('next', config('auth.home_url', '/'));

    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
        return (string) config('auth.home_url', '/');
    }

    return route_path($next) === '/login' ? (string) config('auth.home_url', '/') : $next;
}

function tc_login_auth_schema(): void
{
    run(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password VARCHAR(255) NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'user',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            tags VARCHAR(255) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY users_email_unique (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!tc_login_column_exists('password')) {
        run('ALTER TABLE users ADD password VARCHAR(255) NULL AFTER email');
    }
}

function tc_login_column_exists(string $column): bool
{
    return (int) val(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['users', $column]
    ) > 0;
}

function tc_login_seed_default_user(): bool
{
    if (!(bool) config('auth.seed.enabled', true)) {
        return false;
    }

    $passwordColumn = (string) config('auth.password', 'password');
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $passwordColumn)) {
        return false;
    }

    $usersWithPassword = (int) val("SELECT COUNT(*) FROM users WHERE {$passwordColumn} IS NOT NULL AND {$passwordColumn} <> ''");

    if ($usersWithPassword > 0) {
        return false;
    }

    $email = (string) config('auth.seed.login', 'admin@example.test');
    $password = (string) config('auth.seed.password', 'password');
    $data = [
        'name' => 'Admin',
        'email' => $email,
        'password' => auth_password($password),
        'role' => 'admin',
        'status' => 'active',
        'tags' => 'team',
        'note' => 'First-run admin účet.',
    ];

    if (total('users', ['email' => $email]) > 0) {
        update('users', $data, ['email' => $email]);
    } else {
        insert('users', $data);
    }

    return true;
}
