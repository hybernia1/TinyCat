<?php
declare(strict_types=1);

namespace TinyCat\Installation;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Pages
{
    public static function page(): void
    {
        \TinyCat\Security\Csrf::token();
        $languages = Installer::languages();
        $state = &Installer::state();
        \TinyCat\Support\Localization::locale(Installer::locale($languages, $state));
        if (empty($state['database']) && is_array(\TinyCat\Settings\Store::config('database', []))) {
            $state['database'] = \TinyCat\Settings\Store::config('database', []);
        }
        self::render((bool) \TinyCat\Support\Config::get('install.complete', false) ? 'done' : self::step($state), $languages, $state);
    }

    public static function step(array $state): string
    {
        $status = \TinyCat\Database\Schema::app_db_status();

        if ($status['ready']) {
            return 'done';
        }

        $defaultStep = isset($state['locale']) ? 'db' : 'language';

        if (isset($state['locale']) && $status['connected']) {
            $defaultStep = 'tables';

            if ($status['connected'] && $status['missing_tables'] === [] && !$status['account_ready']) {
                $defaultStep = 'admin';
            }
        }

        $step = (string) \TinyCat\Http\Web::get('step', $defaultStep);
        $allowed = ['language', 'db', 'tables', 'admin', 'done'];

        if (!in_array($step, $allowed, true)) {
            $step = 'language';
        }

        if (in_array($step, ['tables', 'admin'], true) && empty($state['database'])) {
            \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.missing_db'));
            \TinyCat\Http\Web::redirect('/install?step=db');
        }

        $tablesReady = $status['connected'] && $status['missing_tables'] === [];

        if ($step === 'admin' && empty($state['tables']) && !$tablesReady) {
            \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.missing_tables'));
            \TinyCat\Http\Web::redirect('/install?step=tables');
        }

        if ($step === 'done' && !$status['ready']) {
            \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.db_not_ready'));
            \TinyCat\Http\Web::redirect('/install?step=' . $defaultStep);
        }

        return $step;
    }

    public static function render(string $step, array $languages, array $state): void
    {
        $appName = 'TinyCat';
        $pageTitle = \TinyCat\Support\Localization::t('install.title') . ' | ' . $appName;
        $error = \TinyCat\Http\Session::flash('error');
        $success = \TinyCat\Http\Session::flash('success');
        ?>
        <!doctype html>
        <html lang="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::locale()) ?>">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="csrf-token" content="<?= \TinyCat\Support\Html::e(\TinyCat\Security\Csrf::token()) ?>">
            <title><?= \TinyCat\Support\Html::e($pageTitle) ?></title>
            <link rel="stylesheet" href="<?= \TinyCat\Support\Html::e(\TinyCat\Support\ClientAssets::styles('/install')[0]) ?>">
            <?php foreach (\TinyCat\Support\ClientAssets::scripts('/install') as $scriptUrl): ?>
                <script src="<?= \TinyCat\Support\Html::e($scriptUrl) ?>" defer></script>
            <?php endforeach; ?>
        </head>
        <body data-icon-sprite="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Assets::asset('icons.svg')) ?>">
            <header class="navbar">
                <div class="container navbar-inner">
                    <strong><?= \TinyCat\Support\Html::e($appName) ?></strong>
                    <span class="badge badge-primary"><?= \TinyCat\Support\Html::icon('settings') ?> <?= \TinyCat\Support\Html::et('install.title') ?></span>
                </div>
            </header>

            <main class="section">
                <div class="container-sm stack stack-gap-24">
                    <div class="stack stack-gap-8">
                        <h1 class="text-2xl m-0"><?= \TinyCat\Support\Html::et('install.title') ?></h1>
                    </div>

                    <?= \TinyCat\Support\View::part('Site/install/steps', ['current' => $step]) ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= \TinyCat\Support\Html::e($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= \TinyCat\Support\Html::e($success) ?></div>
                    <?php endif; ?>

                    <?php
                    match ($step) {
                        'language' => self::language_view($languages, $state),
                        'db' => self::database_view($state),
                        'tables' => self::tables_view($state),
                        'admin' => self::admin_view(),
                        'done' => self::done_view(),
                        default => self::language_view($languages, $state),
                    };
                    ?>
                </div>
            </main>
        </body>
        </html>
        <?php
    }

    public static function language_view(array $languages, array $state): void
    {
        $selected = (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale());
        ?>
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('globe') ?> <?= \TinyCat\Support\Html::et('install.language_title') ?></h2>
            </div>
            <div class="card-body stack">
                <form class="stack" method="post" action="/api/installation/steps/language" data-installation-form>
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <input type="hidden" name="_install_step" value="language">
                    <div class="choice-list">
                        <?php foreach ($languages as $language): ?>
                            <?php $checked = $language['code'] === $selected; ?>
                            <label class="choice-card">
                                <input type="radio" name="locale" value="<?= \TinyCat\Support\Html::e($language['code']) ?>"<?= $checked ? ' checked' : '' ?>>
                                <span>
                                    <strong><?= \TinyCat\Support\Html::e($language['label']) ?></strong>
                                    <small><?= \TinyCat\Support\Html::e(strtoupper((string) $language['code'])) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="choice-row">
                        <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('check') ?> <span><?= \TinyCat\Support\Html::et('common.continue') ?></span></button>
                    </div>
                </form>
            </div>
        </article>
        <?php
    }

    public static function database_view(array $state): void
    {
        $database = is_array($state['database'] ?? null) ? $state['database'] : (array) \TinyCat\Settings\Store::config('database', []);
        ?>
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('database') ?> <?= \TinyCat\Support\Html::et('install.db_title') ?></h2>
            </div>
            <div class="card-body stack">
                <form class="stack" method="post" action="/api/installation/steps/db" data-installation-form>
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <input type="hidden" name="_install_step" value="db">
                    <div class="grid sm:grid-2">
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('install.db_host') ?></span>
                            <input class="input" name="host" value="<?= \TinyCat\Support\Html::e($database['host'] ?? 'localhost') ?>" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('install.db_port') ?></span>
                            <input class="input" type="number" name="port" min="1" max="65535" value="<?= \TinyCat\Support\Html::e($database['port'] ?? '') ?>">
                        </label>
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('install.db_name') ?></span>
                            <input class="input" name="name" value="<?= \TinyCat\Support\Html::e($database['name'] ?? '') ?>" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('install.db_user') ?></span>
                            <input class="input" name="user" value="<?= \TinyCat\Support\Html::e($database['user'] ?? 'root') ?>" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('install.db_password') ?></span>
                            <input class="input" type="password" name="password" value="">
                        </label>
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('install.db_charset') ?></span>
                            <input class="input" name="charset" value="<?= \TinyCat\Support\Html::e($database['charset'] ?? 'utf8mb4') ?>" required>
                        </label>
                    </div>
                    <div class="choice-row">
                        <a class="btn btn-secondary" href="/install?step=language"><?= \TinyCat\Support\Html::icon('arrow-left') ?> <span><?= \TinyCat\Support\Html::et('common.back') ?></span></a>
                        <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('arrow-right') ?> <span><?= \TinyCat\Support\Html::et('install.db_test') ?></span></button>
                    </div>
                </form>
            </div>
        </article>
        <?php
    }

    public static function tables_view(array $state): void
    {
        $tableStatuses = [];
        $error = null;

        if (!empty($state['database']) && is_array($state['database'])) {
            try {
                \TinyCat\Database\Connection::set(Installer::pdo($state['database']));

                foreach (array_keys(Installer::schema_tables()) as $table) {
                    $tableStatuses[$table] = Installer::table_exists($table);
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        ?>
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('database') ?> <?= \TinyCat\Support\Html::et('install.tables_title') ?></h2>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('install.tables_intro') ?></p>
                <?php if ($error !== null): ?>
                    <div class="alert alert-danger"><?= \TinyCat\Support\Html::et('install.messages.db_failed', ['message' => $error]) ?></div>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?= \TinyCat\Support\Html::et('install.table_name') ?></th>
                                <th><?= \TinyCat\Support\Html::et('install.table_purpose') ?></th>
                                <th><?= \TinyCat\Support\Html::et('install.table_state') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (Installer::schema_tables() as $table => $purpose): ?>
                                <?php $exists = (bool) ($tableStatuses[$table] ?? false); ?>
                                <tr>
                                    <td><code><?= \TinyCat\Support\Html::e($table) ?></code></td>
                                    <td><?= \TinyCat\Support\Html::et($purpose) ?></td>
                                    <td>
                                        <span class="badge<?= $exists ? ' badge-primary' : '' ?>">
                                            <?= $exists ? \TinyCat\Support\Html::et('install.state_exists') : \TinyCat\Support\Html::et('install.state_missing') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="post" action="/api/installation/steps/tables" data-installation-form class="choice-row">
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <input type="hidden" name="_install_step" value="tables">
                    <a class="btn btn-secondary" href="/install?step=db"><?= \TinyCat\Support\Html::icon('arrow-left') ?> <span><?= \TinyCat\Support\Html::et('common.back') ?></span></a>
                    <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('check') ?> <span><?= \TinyCat\Support\Html::et('install.create_tables') ?></span></button>
                </form>
            </div>
        </article>
        <?php
    }

    public static function admin_view(): void
    {
        ?>
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('shield') ?> <?= \TinyCat\Support\Html::et('install.admin_title') ?></h2>
            </div>
            <div class="card-body stack">
                <form class="stack" method="post" action="/api/installation/steps/admin" data-installation-form>
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <input type="hidden" name="_install_step" value="admin">
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('common.username') ?></span>
                        <input class="input" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Usernames::username_pattern()) ?>" minlength="2" maxlength="32" required>
                        <span class="help"><?= \TinyCat\Support\Html::e(\TinyCat\Users\Usernames::username_hint()) ?></span>
                    </label>
                    <div class="grid sm:grid-2">
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('common.password') ?></span>
                            <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= \TinyCat\Users\Login::auth_password_max_length() ?>" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('install.admin_password_confirm') ?></span>
                            <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= \TinyCat\Users\Login::auth_password_max_length() ?>" required>
                        </label>
                    </div>
                    <div class="choice-row">
                        <a class="btn btn-secondary" href="/install?step=tables"><?= \TinyCat\Support\Html::icon('arrow-left') ?> <span><?= \TinyCat\Support\Html::et('common.back') ?></span></a>
                        <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('save') ?> <span><?= \TinyCat\Support\Html::et('common.finish') ?></span></button>
                    </div>
                </form>
            </div>
        </article>
        <?php
    }

    public static function done_view(): void
    {
        ?>
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('check-circle') ?> <?= \TinyCat\Support\Html::et('install.done_title') ?></h2>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('install.done_intro') ?></p>
                <ul class="result-list">
                    <li class="result-item"><?= \TinyCat\Support\Html::icon('globe') ?> <span><?= \TinyCat\Support\Html::et('common.language') ?>: <strong><?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::locale()) ?></strong></span></li>
                    <li class="result-item"><?= \TinyCat\Support\Html::icon('database') ?> <span><?= \TinyCat\Support\Html::et('common.tables') ?>: <strong><?= \TinyCat\Support\Html::e(implode(', ', \TinyCat\Database\Schema::app_required_tables())) ?></strong></span></li>
                    <li class="result-item"><?= \TinyCat\Support\Html::icon('shield') ?> <span><?= \TinyCat\Support\Html::et('common.account') ?>: <strong><?= \TinyCat\Support\Html::et('common.done') ?></strong></span></li>
                </ul>
                <div class="btn-group">
                    <a class="btn btn-primary" href="/admin"><?= \TinyCat\Support\Html::icon('dashboard') ?> <span><?= \TinyCat\Support\Html::et('install.open_admin') ?></span></a>
                    <a class="btn btn-secondary" href="/"><?= \TinyCat\Support\Html::icon('home') ?> <span><?= \TinyCat\Support\Html::et('install.open_index') ?></span></a>
                </div>
            </div>
        </article>
        <?php
    }
}
