<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

$workspaceRoot = dirname(__DIR__, 2);
$options = getopt('', ['root::']);
$requestedRoot = trim((string) ($options['root'] ?? $workspaceRoot));
$root = realpath($requestedRoot);
$configPath = $workspaceRoot . '/config.php';

if (!is_string($root)
    || !is_file($root . '/App/bootstrap.php')
    || !is_file($root . '/Public/install/index.php')
) {
    fwrite(STDERR, "MySQL installer rehearsal root is invalid.\n");
    exit(1);
}

if (!is_file($configPath)) {
    echo "SKIP MySQL installer rehearsal: local database configuration is unavailable.\n";
    exit(0);
}

$baseConfig = require $configPath;
$databaseConfig = is_array($baseConfig['database'] ?? null) ? $baseConfig['database'] : [];
$configHash = hash_file('sha256', $configPath);

if (($databaseConfig['host'] ?? '') === '' || !array_key_exists('user', $databaseConfig)) {
    echo "SKIP MySQL installer rehearsal: local MySQL connection is not configured.\n";
    exit(0);
}

$host = (string) $databaseConfig['host'];
$port = isset($databaseConfig['port']) ? ';port=' . (int) $databaseConfig['port'] : '';
$charset = preg_match('/^[A-Za-z0-9_]+$/', (string) ($databaseConfig['charset'] ?? 'utf8mb4')) === 1
    ? (string) ($databaseConfig['charset'] ?? 'utf8mb4')
    : 'utf8mb4';
$databaseName = 'tinycat_installer_test_' . bin2hex(random_bytes(6));
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = null;
$database = null;
$created = false;
$demoRoot = $workspaceRoot . '/storage/demo-schema-test-' . bin2hex(random_bytes(6));
$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    if (preg_match('/^tinycat_installer_test_[a-f0-9]{12}$/', $databaseName) !== 1) {
        throw new RuntimeException('Unsafe installer rehearsal database name.');
    }

    try {
        $server = new PDO(
            "mysql:host={$host}{$port};charset={$charset}",
            (string) $databaseConfig['user'],
            (string) ($databaseConfig['password'] ?? ''),
            $options
        );
    } catch (Throwable) {
        echo "SKIP MySQL installer rehearsal: configured server is unavailable.\n";
        exit(0);
    }

    try {
        $server->exec("CREATE DATABASE `{$databaseName}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci");
        $created = true;
    } catch (PDOException $exception) {
        echo 'SKIP MySQL installer rehearsal: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        "mysql:host={$host}{$port};dbname={$databaseName};charset={$charset}",
        (string) $databaseConfig['user'],
        (string) ($databaseConfig['password'] ?? ''),
        $options
    );
    define('TINYCAT', true);
    require_once $root . '/App/bootstrap.php';
    ob_start();

    try {
        require $root . '/Public/install/index.php';
    } finally {
        ob_end_clean();
    }

    \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
    tc_install_create_tables();
    $assert(tc_install_missing_tables() === [], 'Fresh installer creates every required table.');
    $tagIndexColumns = static function () use ($database): array {
        $columns = [];
        foreach ($database->query('SHOW INDEX FROM content_tags')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['Key_name'] === 'content_tags_term_index') {
                $columns[(int) $index['Seq_in_index']] = (string) $index['Column_name'];
            }
        }
        ksort($columns);
        return array_values($columns);
    };
    $assert($tagIndexColumns() === ['term_id', 'content_id'], 'Fresh installation creates the covering tag archive index.');
    $tagIndexMigration = require $workspaceRoot . '/migrations/20261028_001_tag_archive_index.php';
    $tagIndexMigration($database);
    $tagIndexMigration($database);
    $assert($tagIndexColumns() === ['term_id', 'content_id'], 'Tag index migration is idempotent on the fresh schema.');
    // Keep the term foreign key supported while simulating a legacy missing index.
    $database->exec('ALTER TABLE content_tags ADD INDEX tag_test_fk (term_id), DROP INDEX content_tags_term_index');
    $tagIndexMigration($database);
    $assert($tagIndexColumns() === ['term_id', 'content_id'], 'Migration restores a missing tag archive index.');
    $database->exec('ALTER TABLE content_tags DROP INDEX content_tags_term_index, ADD INDEX content_tags_term_index (content_id, term_id)');
    $tagIndexMigration($database);
    $assert($tagIndexColumns() === ['term_id', 'content_id'], 'Migration repairs incorrect tag index column order.');
    $database->exec('ALTER TABLE content_tags DROP INDEX content_tags_term_index');
    tc_install_create_tables();
    $assert($tagIndexColumns() === ['term_id', 'content_id'], 'Restarted installation also restores a missing tag archive index.');
    $database->exec('ALTER TABLE content_tags DROP INDEX tag_test_fk');
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profile_links'")->fetchColumn() === 0,
        'Fresh installer does not create the obsolete profile links table.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content' AND COLUMN_NAME = 'created_at'")->fetchColumn() === 0,
        'Fresh installer does not create the redundant content creation timestamp.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('content_images', 'content_likes', 'comment_likes') AND COLUMN_NAME = 'created_at'")->fetchColumn() === 0,
        'Fresh installer does not create redundant relation creation timestamps.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'links' AND COLUMN_NAME = 'embed_url'")->fetchColumn() === 0,
        'Fresh installer does not create the redundant link embed URL.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates'")->fetchColumn() === 0,
        'Fresh installer does not create the redundant email template table.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('last_login_at', 'last_seen_at')")->fetchColumn() === 0,
        'Fresh installer does not create activity timestamps.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_followers' AND INDEX_NAME = 'user_followers_follower_recent_index'")->fetchColumn() === 3,
        'Fresh installer creates the recent-followers index.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_followers' AND INDEX_NAME = 'user_followers_follower_index'")->fetchColumn() === 0,
        'Fresh installer omits the obsolete followers index.'
    );
    $firstTableCount = (int) $database->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();

    \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set(new PDO(
        "mysql:host={$host}{$port};dbname={$databaseName};charset={$charset}",
        (string) $databaseConfig['user'],
        (string) ($databaseConfig['password'] ?? ''),
        $options
    ));
    tc_install_create_tables();
    $secondTableCount = (int) \TinyCat\Database\Connection::get()->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
    $assert($secondTableCount === $firstTableCount, 'Repeating table creation is restart-safe.');
    $assert(tc_install_missing_tables() === [], 'Restarted installer still recognizes the complete schema.');

    $password = 'Installer-Rehearsal-2026';
    $firstAdmin = tc_install_create_admin_account('installer_stage1', $password, 'en');
    $secondAdmin = tc_install_create_admin_account('installer_stage1', $password, 'cs');
    $assert($firstAdmin > 0 && $secondAdmin === $firstAdmin, 'Admin creation updates the same account after restart.');
    $assert(
        (int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM users WHERE username = 'installer_stage1' AND role = 'admin' AND locale = 'cs'") === 1,
        'Restarted admin step persists one current administrator.'
    );

    tc_install_default_settings(['locale' => 'en']);
    $settingsCount = (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM settings');
    $emailTemplateStates = \TinyCat\Settings\Store::setting('email.templates', []);
    tc_install_default_settings(['locale' => 'cs']);
    $assert((int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM settings') === $settingsCount, 'Default settings are idempotent.');
    $assert((int) \TinyCat\Database\Connection::value('SELECT COUNT(DISTINCT setting_key) FROM settings') === $settingsCount, 'Default settings remain unique.');
    $assert(\TinyCat\Settings\Store::setting('email.templates', []) === $emailTemplateStates, 'Email delivery switches are idempotent settings.');
    $assert((string) \TinyCat\Database\Connection::value("SELECT setting_value FROM settings WHERE setting_key = 'i18n.locale'") === 'cs', 'Repeated defaults update the selected locale.');

    $mixedName = '2Pac.Žaneta';
    $mixedUserId = (int) \TinyCat\Database\Connection::insert('users', [
        'username' => \TinyCat\Users\Usernames::username_normalize($mixedName),
        'password' => \TinyCat\Users\Authentication::authPassword($password),
        'role' => 'user',
        'status' => 'active',
        'locale' => 'en',
        'bio' => '',
    ]);
    $assert((string) \TinyCat\Database\Connection::value('SELECT username FROM users WHERE id = ?', [$mixedUserId]) === $mixedName, 'MySQL preserves the requested username case and diacritics.');
    $assert(\TinyCat\Users\Usernames::user_username_taken('2PAC.ŽANETA'), 'MySQL rejects case variants as duplicate usernames.');
    $assert(\TinyCat\Users\Login::auth_attempt(['username' => '2pac.žaneta', 'password' => $password]), 'Case-insensitive login works with a digit prefix and Unicode letters.');
    $assert((string) \TinyCat\Users\Authentication::auth('username') === $mixedName, 'Login preserves the account display spelling.');
    $assert(\TinyCat\Users\Login::auth_attempt(['username' => 'INSTALLER_STAGE1', 'password' => $password]), 'Existing lowercase accounts still accept differently cased login input.');

    $status = \TinyCat\Database\Schema::app_db_status();
    $assert(($status['connected'] ?? false) === true, 'Installed database is connected.');
    $assert(($status['missing_tables'] ?? null) === [], 'Installed database reports no missing tables.');
    $assert(($status['account_ready'] ?? false) === true, 'Installed database reports an active account.');
    $assert(($status['ready'] ?? false) === true, 'Installed database is application-ready.');

    $recentIndex = static fn (): array => array_column(\TinyCat\Database\Connection::all(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='content_comments' AND INDEX_NAME='content_comments_recent_index' ORDER BY SEQ_IN_INDEX"
    ), 'COLUMN_NAME');
    $assert($recentIndex() === ['created_at', 'id'], 'Fresh installs index the latest comments in display order.');
    \TinyCat\Database\Connection::exec('ALTER TABLE content_comments DROP INDEX content_comments_recent_index');
    $indexRecentComments = require $root . '/migrations/20261026_001_recent_comments_index.php';
    $indexRecentComments(\TinyCat\Database\Connection::get());
    $indexRecentComments(\TinyCat\Database\Connection::get());
    $assert($recentIndex() === ['created_at', 'id'], 'Recent-comments migration is repeatable on existing installations.');

    $searchIndex = static fn (): array => array_column(\TinyCat\Database\Connection::all(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='users_status_username_index' ORDER BY SEQ_IN_INDEX"
    ), 'COLUMN_NAME');
    $assert($searchIndex() === ['status', 'username', 'id'], 'Fresh installs index active-user search and ordering.');
    \TinyCat\Database\Connection::exec('ALTER TABLE users DROP INDEX users_status_username_index');
    $indexUserSearch = require $root . '/migrations/20261026_002_user_search_index.php';
    $indexUserSearch(\TinyCat\Database\Connection::get());
    $indexUserSearch(\TinyCat\Database\Connection::get());
    $assert($searchIndex() === ['status', 'username', 'id'], 'User-search migration is repeatable on existing installations.');

    $botId = (int) \TinyCat\Database\Connection::insert('users', [
        'username' => 'adopted_feedbot',
        'role' => 'bot',
        'status' => 'active',
        'locale' => 'en',
        'bio' => '',
    ]);
    $feedHash = \TinyCat\Bots\Importer::feedSourceHash('https://93.184.216.34/feed');
    $sourceId = (int) \TinyCat\Database\Connection::insert('bot_sources', [
        'bot_user_id' => $botId,
        'name' => 'Preserved source',
        'feed_url' => 'https://93.184.216.34/feed',
        'feed_hash' => $feedHash,
        'post_template' => \TinyCat\Bots\Importer::defaultSourceTemplate(),
        'next_run_at' => date('Y-m-d H:i:s', time() + 86400),
    ]);
    $historyHash = hash('sha256', 'existing-item');
    \TinyCat\Database\Connection::insert('bot_feed_history', ['bot_user_id' => $botId, 'feed_hash' => $feedHash, 'item_hash' => $historyHash]);
    \TinyCat\Database\Connection::insert('bot_source_runs', ['source_id' => $sourceId, 'status' => 'imported', 'started_at' => \TinyCat\Support\Dates::dateDb(), 'items_seen' => 1]);

    // Simulate an older installed Bots package with per-source duplicate history.
    \TinyCat\Database\Connection::exec('CREATE TABLE bot_feed_items (source_id BIGINT UNSIGNED NOT NULL, item_hash CHAR(64) NOT NULL, PRIMARY KEY (source_id, item_hash)) ENGINE=InnoDB');
    $legacyHash = hash('sha256', 'legacy-item');
    \TinyCat\Database\Connection::insert('bot_feed_items', ['source_id' => $sourceId, 'item_hash' => $legacyHash]);
    \TinyCat\Database\Connection::exec('ALTER TABLE bot_sources ADD COLUMN updated_at DATETIME NULL');
    $adoptBots = require $root . '/migrations/20261025_001_core_bots.php';
    $adoptBots(\TinyCat\Database\Connection::get());
    $adoptBots(\TinyCat\Database\Connection::get());
    $assert(\TinyCat\Bots\Administration::accountById($botId)['username'] === 'adopted_feedbot', 'Core adoption preserves the bot account and ID.');
    $assert(\TinyCat\Bots\Importer::findSource($sourceId)['feed_hash'] === $feedHash, 'Core adoption preserves source identity and scheduling.');
    $assert((int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM bot_source_runs WHERE source_id = ?', [$sourceId]) === 1, 'Core adoption preserves run history.');
    $knownHashes = (new ReflectionMethod(\TinyCat\Bots\Importer::class, 'feedHistoryHashes'))->invoke(null, $botId, $feedHash, [$historyHash, $legacyHash]);
    $assert(isset($knownHashes[$historyHash], $knownHashes[$legacyHash]), 'Repeated core adoption retains current and legacy duplicate protection.');
    $assert((int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_feed_items'") === 0, 'Core adoption finishes legacy history consolidation.');
    $feedTask = \TinyCat\Bots\Importer::scheduledTasks()['feeds']['runner'];
    $feedResult = $feedTask(['options' => ['bot_limit' => 20]]);
    $assert($feedResult['ok'] && $feedResult['count'] === 0, 'Built-in scheduler respects a future source schedule.');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    \TinyCat\Users\Authentication::authLogin($firstAdmin);
    foreach (['accounts-page.php', 'sources-page.php', 'detail.php'] as $botPage) {
        $_GET = ['id' => $botId];
        $_SERVER['REQUEST_URI'] = '/admin/bots/' . $botId;
        ob_start();
        try {
            match ($botPage) {
                'accounts-page.php' => \TinyCat\Bots\Pages::accounts(),
                'sources-page.php' => \TinyCat\Bots\Pages::sources(),
                default => \TinyCat\Bots\Pages::detail($botId),
            };
            $botHtml = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $assert(str_contains($botHtml, 'adopted_feedbot'), 'Built-in bot admin renders adopted data: ' . $botPage);
        $assert(!str_contains($botHtml, '/admin/extensions'), 'Built-in bot navigation omits the extension store: ' . $botPage);
    }

    \TinyCat\Settings\Store::setSetting('extensions.states', ['bots' => true], 'json', 'extensions');
    \TinyCat\Settings\Store::setSetting('extensions.installed_versions', ['bots' => '1.4.3'], 'json', 'extensions');
    \TinyCat\Database\Connection::insert('schema_migrations', ['migration' => 'extension:bots:fixture', 'version' => '1.4.3', 'checksum' => str_repeat('a', 64), 'applied_at' => \TinyCat\Support\Dates::dateDb()]);
    $removeExtensionMetadata = require $root . '/migrations/20261025_002_remove_extension_metadata.php';
    $removeExtensionMetadata(\TinyCat\Database\Connection::get());
    $removeExtensionMetadata(\TinyCat\Database\Connection::get());
    $assert((int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'extensions.%'") === 0, 'Retired extension configuration is removed idempotently.');
    $assert((int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM schema_migrations WHERE migration LIKE 'extension:%'") === 0, 'Retired extension migration metadata is removed.');
    $assert((int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM bot_feed_history WHERE bot_user_id = ?', [$botId]) === 2, 'Metadata cleanup preserves built-in bot import history.');
    $assert((string) \TinyCat\Database\Connection::value("SELECT setting_value FROM settings WHERE setting_key = 'i18n.locale'") === 'cs', 'Metadata cleanup preserves application settings.');
    $assert(is_string($configHash) && hash_file('sha256', $configPath) === $configHash, 'Schema rehearsal does not replace application configuration.');

    // Run the real importer against the current fresh schema, including interrupted resume.
    foreach (['Options', 'DeterministicGenerator', 'ProgressReporter', 'CheckpointStore', 'BatchWriter', 'Schema', 'UiFixtures', 'DemoImporter'] as $tool) { require_once $workspaceRoot . '/tools/demo-import/' . $tool . '.php'; }
    mkdir($demoRoot, 0700);
    file_put_contents($demoRoot . '/config.php', "<?php return ['database' => []];");
    mkdir($demoRoot . '/storage/cache/assets', 0775, true);
    file_put_contents($demoRoot . '/storage/cache/assets/demo.aaaaaaaaaaaaaaaaaaaa.js', 'immutable');
    file_put_contents($demoRoot . '/storage/cache/expired.json', '{}');
    $demoArguments = ['demo-import.php', '--config=' . $demoRoot . '/config.php', '--profile=small', '--users=12', '--prefix=fixture', '--ui-fixtures=' . (function_exists('imagewebp') ? '1' : '0')];
    $pausedOptions = \TinyCat\Tools\DemoImport\Options::fromArgv([...$demoArguments, '--reset=1', '--max-batches=2'], $workspaceRoot);
    $progress = new \TinyCat\Tools\DemoImport\ProgressReporter(false);
    $paused = (new \TinyCat\Tools\DemoImport\DemoImporter($database, $pausedOptions, $progress, $databaseName))->run();
    $assert(!$paused['complete'], 'Fresh-schema demo import can pause at a checkpoint.');
    $resumedOptions = \TinyCat\Tools\DemoImport\Options::fromArgv([...$demoArguments, '--reset=0'], $workspaceRoot);
    $importer = new \TinyCat\Tools\DemoImport\DemoImporter($database, $resumedOptions, $progress, $databaseName);
    $demo = $importer->run();
    $assert($demo['complete'] && $demo['counts']['content'] > 0 && $demo['counts']['content_comments'] > 0, 'Resumed demo import populates the current schema.');
    $assert(is_file($demoRoot . '/storage/cache/assets/demo.aaaaaaaaaaaaaaaaaaaa.js'), 'Demo import preserves immutable assets for open pages.');
    $assert(!is_file($demoRoot . '/storage/cache/expired.json'), 'Demo import clears stale data caches.');
    $assert((int) $database->query("SELECT COUNT(*) FROM settings WHERE setting_key IN ('i18n.locale', 'datetime.timezone')")->fetchColumn() === 2, 'Demo importer uses current localization setting keys.');
    $repeated = $importer->run();
    $assert($repeated['counts'] === $demo['counts'], 'Completed demo checkpoint is idempotent.');
    if (function_exists('imagewebp')) {
        $assert((int) $database->query('SELECT COUNT(*) FROM bot_sources')->fetchColumn() === 2, 'UI demo includes built-in bot sources.');
        $assert((int) $database->query('SELECT COUNT(*) FROM bot_sources WHERE enabled <> 0')->fetchColumn() === 0, 'Demo bot sources do not start network imports.');
        $assert((int) $database->query('SELECT COUNT(*) FROM content_images')->fetchColumn() > 0, 'UI demo includes status images.');
        $assert(is_file($demoRoot . '/uploads/avatars/1.webp'), 'UI demo stores an actual avatar file.');
        $before = (int) $database->query('SELECT COUNT(*) FROM content')->fetchColumn();
        (new \TinyCat\Tools\DemoImport\UiFixtures($database, $demoRoot, 'fixture', $resumedOptions->anchor))->run();
        $assert((int) $database->query('SELECT COUNT(*) FROM content')->fetchColumn() === $before, 'Visual fixture generation is idempotent.');
    }

    $database->exec("INSERT INTO terms (name) VALUES ('archive_index_probe')");
    $probeTermId = (int) $database->lastInsertId();
    $database->prepare('INSERT INTO content_tags (content_id, term_id) SELECT id, ? FROM content ORDER BY id LIMIT 3')->execute([$probeTermId]);
    $probeQuery = \TinyCat\Status\Feed::public_status_tag_id_query($probeTermId)->order('c.published_at DESC, c.id DESC')->limit(20);
    \TinyCat\Status\Feed::public_status_apply_cursor($probeQuery, '2099-01-01 00:00:00', PHP_INT_MAX);
    $probeSql = (new ReflectionMethod(\TinyCat\Database\Query::class, 'sql'))->invoke($probeQuery);
    $probeParams = (new ReflectionProperty(\TinyCat\Database\Query::class, 'params'))->getValue($probeQuery);
    $probeExplain = $database->prepare('EXPLAIN ' . $probeSql);
    $probeExplain->execute($probeParams);
    $probePlan = array_column($probeExplain->fetchAll(PDO::FETCH_ASSOC), null, 'table');
    $assert(($probePlan['ct']['key'] ?? '') === 'content_tags_term_index', 'Rare tag selection uses the covering term index.');
    $assert(($probePlan['c']['key'] ?? '') === 'PRIMARY' && ($probePlan['c']['type'] ?? '') === 'eq_ref', 'Rare tag cursor looks up matching content by primary key instead of scanning the global feed.');

} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
} finally {
    if (is_dir($demoRoot)) {
        $resolvedDemo = realpath($demoRoot);
        $storage = realpath($workspaceRoot . '/storage');
        if (is_string($resolvedDemo) && is_string($storage) && str_starts_with($resolvedDemo, $storage . DIRECTORY_SEPARATOR)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedDemo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($resolvedDemo);
        }
    }
    $database = null;

    if ($created && $server instanceof PDO && preg_match('/^tinycat_installer_test_[a-f0-9]{12}$/', $databaseName) === 1) {
        try {
            $server->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
        } catch (Throwable $exception) {
            $failures[] = 'Unable to remove installer rehearsal database: ' . $exception->getMessage();
        }
    }

    $server = null;
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    echo "\nMySQL installer rehearsal: {$checks} checks, " . count($failures) . " failures.\n";
    exit(1);
}

echo "PASS restart-safe MySQL installer rehearsal ({$checks} checks, disposable database removed)\n";
