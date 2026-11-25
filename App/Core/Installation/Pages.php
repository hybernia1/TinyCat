<?php
declare(strict_types=1);

namespace TinyCat\Core\Installation;

use Throwable;

final class Pages
{
    public static function recovery(): void
    {
        $operation = \TinyCat\Core\Updates\Manager::installationRecoveryState();
        self::render('recovery', [], ['operation' => (string) ($operation['id'] ?? '')]);
    }

    public static function page(): void
    {
        if (Installer::isComplete()) {
            \TinyCat\Http\Document::notFound('Not found.');
            return;
        }
        \TinyCat\Core\Security\Csrf::token();
        $languages = Installer::languages();
        $state = &Installer::state();
        \TinyCat\Support\Localization::locale(Installer::locale($languages, $state));
        if (empty($state['database']) && is_array(\TinyCat\Core\Settings\Store::config('database', []))) {
            $state['database'] = \TinyCat\Core\Settings\Store::config('database', []);
        }
        self::render(self::step($state), $languages, $state);
    }

    /** @param array<mixed> $state */
    public static function step(array $state): string
    {
        $status = \TinyCat\Database\Schema::app_db_status();

        if ($status['ready']) {
            return 'done';
        }

        $defaultStep = isset($state['locale']) ? 'db' : 'language';

        if (isset($state['locale']) && $status['connected']) {
            $defaultStep = 'tables';

            if ($status['missing_tables'] === [] && !$status['account_ready']) {
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

        if ($step === 'done') {
            \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.db_not_ready'));
            \TinyCat\Http\Web::redirect('/install?step=' . $defaultStep);
        }

        return $step;
    }

    /** @param array<string, array{code: string, label: string}> $languages
     * @param array<mixed> $state */
    public static function render(string $step, array $languages, array $state): void
    {
        $data = $step === 'recovery' ? ['current' => 'recovery', 'steps' => [], 'operation' => $state['operation'] ?? ''] : self::prepare($step, $languages, $state);
        $data['error'] = \TinyCat\Http\Session::flash('error');
        $data['success'] = \TinyCat\Http\Session::flash('success');
        echo \TinyCat\Core\Ui\Document::frame([
            'title' => \TinyCat\Support\Localization::t('install.title') . ' | TinyCat',
            'htmlAttributes' => ['lang' => \TinyCat\Support\Localization::locale()],
            'bodyAttributes' => ['data-icon-sprite' => \TinyCat\Support\Assets::asset('App/Core/Ui/assets/icons.svg')],
            'csrfToken' => \TinyCat\Core\Security\Csrf::token(),
            'styles' => \TinyCat\Support\ClientAssets::styles('/install'),
            'scripts' => \TinyCat\Support\ClientAssets::scripts('/install'),
            'skipLink' => false, 'colorScheme' => false,
        ], new \TinyCat\Support\Markup(\TinyCat\Support\Components::render('installation/page', $data)));
    }

    /** Prepare explicit component inputs before rendering; only the tables step probes the database.
     * @param array<string, array{code: string, label: string}> $languages
     * @param array<mixed> $state
     * @return array<string, mixed> */
    public static function prepare(string $step, array $languages, array $state): array
    {
        $labels = ['language' => 'install.step_language', 'db' => 'install.step_database', 'tables' => 'install.step_tables', 'admin' => 'install.step_admin', 'done' => 'install.step_done'];
        $step = isset($labels[$step]) ? $step : 'language';
        $currentIndex = (int) array_search($step, array_keys($labels), true);
        $steps = [];
        foreach (array_keys($labels) as $index => $code) {
            $steps[] = ['label' => \TinyCat\Support\Localization::t($labels[$code]), 'active' => $code === $step, 'complete' => $index < $currentIndex];
        }
        $data = ['current' => $step, 'steps' => $steps, 'locale' => \TinyCat\Support\Localization::locale()];
        if ($step === 'language') {
            $data['locale'] = (string) ($state['locale'] ?? $data['locale']);
            $data['languages'] = array_values($languages);
        } elseif ($step === 'db') {
            $database = is_array($state['database'] ?? null) ? $state['database'] : (array) \TinyCat\Core\Settings\Store::config('database', []);
            $data['database'] = [];
            foreach (['host' => 'localhost', 'port' => '', 'name' => '', 'user' => 'root', 'charset' => 'utf8mb4'] as $key => $default) {
                $data['database'][$key] = $database[$key] ?? $default;
            }
        } elseif ($step === 'tables') {
            $statuses = []; $data['table_error'] = null;
            $tables = Installer::schema_tables();
            if (!empty($state['database']) && is_array($state['database'])) {
                try {
                    \TinyCat\Database\Connection::set(Installer::pdo($state['database']));
                    $existing = array_flip(\TinyCat\Database\Schema::app_existing_tables(array_keys($tables)));
                    foreach (array_keys($tables) as $table) { $statuses[$table] = isset($existing[$table]); }
                } catch (Throwable $exception) { \TinyCat\Core\Runtime\Failure::report($exception); $data['table_error'] = \TinyCat\Core\Runtime\Failure::message($exception, \TinyCat\Support\Localization::t('common.request_failed')); }
            }
            $data['tables'] = [];
            foreach ($tables as $table => $purpose) {
                $data['tables'][] = ['name' => $table, 'purpose' => \TinyCat\Support\Localization::t($purpose), 'exists' => (bool) ($statuses[$table] ?? false)];
            }
        } elseif ($step === 'admin') {
            $data += ['username_pattern' => \TinyCat\Core\Identity\Usernames::pattern(), 'username_hint' => \TinyCat\Core\Identity\Usernames::hint(), 'password_min' => \TinyCat\Core\Identity\Passwords::minLength(), 'password_max' => \TinyCat\Core\Identity\Passwords::maxLength()];
        } elseif ($step === 'done') {
            $data['required_tables'] = implode(', ', \TinyCat\Database\Schema::app_required_tables());
        }
        return $data;
    }
}
