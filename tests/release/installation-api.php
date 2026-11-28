<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/support/workspace.php';

require_once __DIR__ . '/../support/cache-isolation.php';
$workspace = dirname(__DIR__, 2);
$capabilityOnly = in_array('--configuration-capability-only', $argv, true);
$configurationOnly = $capabilityOnly || in_array('--configuration-only', $argv, true);
if (!$configurationOnly) {
    if (!is_file($workspace . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        echo "SKIP installation API: local MySQL configuration unavailable.\n";
        exit(0);
    }
    $local = require $workspace . '/config.php';
    $db = $local['database'];
    if (($db['driver'] ?? 'mysql') !== 'mysql' || empty($db['host']) || !array_key_exists('user', $db)) {
        echo "SKIP installation API: local MySQL configuration unavailable.\n";
        exit(0);
    }
} elseif (PHP_OS_FAMILY !== 'Windows') {
    echo "SKIP Windows configuration-only regression: requires Windows NTFS.\n";
    exit(0);
}
$configHash = $configurationOnly ? null : hash_file('sha256', $workspace . '/config.php');
$name = 'tinycat_install_api_' . bin2hex(random_bytes(6));
$root = \TinyCat\Tools\Workspace::directory('tests/tmp') . '/install-api-' . bin2hex(random_bytes(6));
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$serverDb = $configurationOnly ? null : new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';charset=utf8mb4', $db['user'], $db['password'], $options);
$http = null;
$created = false;
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException($message . ' Last response: ' . ($GLOBALS['install_api_last_response'] ?? '')); }
};
try {
    // Only the isolated copy may receive config.php or installation writes.
    $windows = static function (string $code, array $input): array {
        $process = proc_open([getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(iconv('UTF-8', 'UTF-16LE', "\$ErrorActionPreference = 'Stop'; \$inputData = [Console]::In.ReadToEnd() | ConvertFrom-Json; " . $code))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start Windows fixture setup.'); }
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    };
    if (PHP_OS_FAMILY === 'Windows') {
        [$exit, , $error] = $windows(<<<'PS'
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$security = New-Object Security.AccessControl.DirectorySecurity
$security.SetOwner($sid); $security.SetAccessRuleProtection($true, $false)
foreach ($value in @($sid.Value, 'S-1-5-18', 'S-1-5-32-544') | Select-Object -Unique) {
    $principal = New-Object Security.Principal.SecurityIdentifier($value)
    $security.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($principal, 'FullControl', 'ContainerInherit, ObjectInherit', 'None', 'Allow')))
}
[IO.Directory]::CreateDirectory([string] $inputData.root, $security) | Out-Null
PS, ['root' => $root]);
        $assert($exit === 0, 'Only the new empty Windows fixture root receives a private inheritable DACL: ' . $error);
    } else { mkdir($root, 0775, true); }
    $assertPrivate = static function (string $path, int $mode = 0600) use ($windows, $assert): void {
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $path);
            $assert((fileperms($path) & 0777) === $mode, 'Resulting POSIX configuration preserves the expected mode.');
            return;
        }
        [$exit, , $error] = $windows(<<<'PS'
$acl = [IO.File]::GetAccessControl([string] $inputData.path)
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
if (!$acl.AreAccessRulesProtected -or $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne $sid) { throw 'Unsafe owner/inheritance' }
$current = $false
foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
    if ($rule.IsInherited -or $rule.AccessControlType -ne 'Allow' -or $rule.IdentityReference.Value -notin @($sid, 'S-1-5-18', 'S-1-5-32-544') -or $rule.FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl) { throw 'Unsafe result DACL' }
    if ($rule.IdentityReference.Value -eq $sid) { $current = $true }
}
if (!$current) { throw 'Missing runtime identity' }
PS, ['path' => $path]);
        $assert($exit === 0, 'Independently read resulting Windows file DACL and current identity: ' . $error);
    };
    if (PHP_OS_FAMILY === 'Windows' && !$capabilityOnly) {
        $configuration = static function (string $operation, string $target, string $content = '', ?string $helper = null, ?string $raw = null) use ($workspace, $root): array {
            $process = proc_open([getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-File', $helper ?? $workspace . '/App/Core/Installation/configuration-windows.ps1'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start configuration fixture verifier.'); }
            $request = $raw ?? json_encode(['root' => $root, 'operation' => $operation, 'target' => $target, 'content' => base64_encode($content)], JSON_THROW_ON_ERROR);
            $written = 0;
            while ($written < strlen($request)) {
                $count = @fwrite($pipes[0], substr($request, $written));
                if ($count === false || $count === 0) { break; }
                $written += $count;
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            return [proc_close($process), $output, $error];
        };
        [$exit] = $configuration('check', $root . '/config.php');
        $assert($exit === 0, 'Private Windows fixture admits configuration publication.');
        [$exit] = $windows(<<<'PS'
$acl = [IO.Directory]::GetAccessControl([string] $inputData.path)
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
if (!$acl.AreAccessRulesProtected -or $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne $sid) { throw 'Unsafe staging owner/inheritance' }
foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
    if ($rule.IsInherited -or $rule.AccessControlType -ne 'Allow' -or $rule.IdentityReference.Value -notin @($sid, 'S-1-5-18', 'S-1-5-32-544') -or $rule.FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl) { throw 'Unsafe staging DACL' }
}
PS, ['path' => $root . '/storage/updates/installation-private']);
        $assert($exit === 0, 'Staging is a separately protected private directory inside storage, not beside config.');
        foreach (['Write', 'DeleteSubdirectoriesAndFiles'] as $right) {
            [$exit] = $windows(<<<'PS'
    $acl = [IO.Directory]::GetAccessControl([string] $inputData.root)
    $rule = New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier('S-1-1-0')), [string] $inputData.right, 'Allow')
    $acl.AddAccessRule($rule); [IO.Directory]::SetAccessControl([string] $inputData.root, $acl)
PS, ['root' => $root, 'right' => $right]);
            $assert($exit === 0, 'Only the disposable fixture receives an unsafe directory rule.');
            [$exit] = $configuration('publish', $root . '/config.php', '<?php return [];');
            $assert($exit !== 0 && !file_exists($root . '/config.php') && glob($root . '/storage/updates/installation-private/.install-config-*') === [], 'Unsafe directory DACL refuses publication before writing content.');
            [$exit] = $windows(<<<'PS'
    $acl = [IO.Directory]::GetAccessControl([string] $inputData.root)
    $acl.PurgeAccessRules((New-Object Security.Principal.SecurityIdentifier('S-1-1-0')))
    [IO.Directory]::SetAccessControl([string] $inputData.root, $acl)
PS, ['root' => $root, 'right' => $right]);
            $assert($exit === 0, 'The fixture directory returns to its original private policy.');
        }
        [$exit] = $configuration('publish', $root . '/config.php', '<?php return [];');
        $assert($exit === 0, 'Protected Windows configuration publication succeeds.');
        $assertPrivate($root . '/config.php');
        [$exit] = $windows(<<<'PS'
$acl = [IO.File]::GetAccessControl([string] $inputData.path)
$acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier('S-1-1-0')), 'Read', 'Allow')))
[IO.File]::SetAccessControl([string] $inputData.path, $acl)
PS, ['path' => $root . '/config.php']);
        $assert($exit === 0, 'Only the dummy fixture config receives an unsafe file read rule.');
        [$exit] = $configuration('verify', $root . '/config.php');
        $assert($exit !== 0, 'The platform verifier rejects a resulting file readable by Everyone.');
        [$exit] = $configuration('publish', $root . '/config.php', '<?php return ["replacement" => true];');
        $assert($exit === 0 && file_get_contents($root . '/config.php') === '<?php return ["replacement" => true];', 'Same-volume replacement normalizes the resulting file DACL and preserves bytes.');
        $assertPrivate($root . '/config.php');
        // Only copied helper code is fault-injected; no production test switches.
        $helperSource = file_get_contents($workspace . '/App/Core/Installation/configuration-windows.ps1');
        $faultHelper = $root . '/failure-helper.ps1';
        $dummy = '<?php return ["private_protocol_sentinel" => true];';
        $rename = "if (![InstallationNative]::MoveFileEx(\$temporary, \$target, 9)) { throw 'Cannot publish private configuration.' }";
        foreach ([
            'before-bytes' => ['$before = Assert-Private $stream', '$before = Assert-Private $stream; throw "private_protocol_sentinel"'],
            'after-write' => ['$stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true)', '$stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true); [Environment]::Exit(73)'],
            'after-rename' => [$rename, $rename . '; [Environment]::Exit(73)'],
        ] as $phase => [$needle, $replacement]) {
            $injected = str_replace($needle, $replacement, $helperSource, $replacements);
            $assert($replacements === 1, 'Exactly one isolated helper failure point exists: ' . $phase);
            file_put_contents($faultHelper, $injected);
            $prior = file_get_contents($root . '/config.php');
            [$exit, $output, $error] = $configuration('publish', $root . '/config.php', $dummy, $faultHelper);
            $assert($exit !== 0 && $output === '' && !str_contains($error, 'private_protocol_sentinel'), 'A failed helper never reports success or echoes secret input: ' . $phase);
            $assert(file_get_contents($root . '/config.php') === ($phase === 'after-rename' ? $dummy : $prior), 'Helper failure preserves either the complete old or complete new configuration: ' . $phase);
            $assertPrivate($root . '/config.php');
            $staged = glob($root . '/storage/updates/installation-private/.install-config-*');
            $assert(count($staged) === ($phase === 'after-write' ? 1 : 0), 'Abrupt exit leaves only the expected private staged file: ' . $phase);
            foreach ($staged as $orphan) { $assertPrivate($orphan); unlink($orphan); }
            [$exit] = $configuration('check', $root . '/config.php');
            $assert($exit === 0, 'The same identity can safely invoke the helper after a failure: ' . $phase);
        }
        unlink($faultHelper);
        foreach ([str_repeat('x', 1500001), '{"private_protocol_sentinel":', null] as $raw) {
            [$exit, $output, $error] = $configuration('publish', $root . '/config.php', str_repeat('x', 1048577), raw: $raw);
            $assert($exit !== 0 && $output === '' && trim($error) === 'Private Windows configuration verification failed.' && file_get_contents($root . '/config.php') === $dummy, 'Malformed/oversized protocol fails without mutation or input disclosure.');
        }
        [$exit] = $windows(<<<'PS'
$acl = [IO.File]::GetAccessControl([string] $inputData.path)
$acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier('S-1-5-18')), 'WriteData', 'Deny')))
[IO.File]::SetAccessControl([string] $inputData.path, $acl)
PS, ['path' => $root . '/config.php']);
        $assert($exit === 0, 'Only the dummy fixture config receives an unexpected trusted-SID deny ACE.');
        [$exit] = $configuration('verify', $root . '/config.php');
        $assert($exit !== 0, 'Unexpected deny ACEs are conservatively rejected.');
        unlink($root . '/config.php');
    }
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (['App/Core/Runtime/autoload.php', 'App/Core/Support/Paths.php', 'App/Core/Installation/Installer.php', 'App/Core/Installation/configuration-windows.ps1'] as $relative) {
            if (!is_dir(dirname($root . '/' . $relative))) { mkdir(dirname($root . '/' . $relative), 0700, true); }
            copy($workspace . '/' . $relative, $root . '/' . $relative);
        }
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $admit = "define('TINYCAT', true); require " . var_export($root . '/App/Core/Runtime/autoload.php', true)
            . "; try { \\TinyCat\\Core\\Installation\\Installer::assert_config_publication_supported(); exit(2); } catch (\\RuntimeException) { echo 'REFUSED'; }";
        $missingCapability = static function (?array $environment = null) use ($admit, $root): array {
            $process = proc_open([PHP_BINARY, '-r', $admit], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start isolated capability regression.'); }
            fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $exit = proc_close($process);
            file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/installation-platform-capabilities.log', json_encode(['exit' => $exit, 'stdout' => $output, 'stderr' => $error], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
            return [$exit, $output, $error];
        };
        $helper = $root . '/App/Core/Installation/configuration-windows.ps1';
        rename($helper, $helper . '.unavailable');
        try {
            [$exit, $output, $error] = $missingCapability();
            $assert($exit === 0 && $output === 'REFUSED' && !file_exists($root . '/config.php'), 'Missing Installation helper refuses admission before configuration or administrator writes: ' . $error);
        } finally { rename($helper . '.unavailable', $helper); }
        [$exit, $output, $error] = $missingCapability(array_replace(getenv(), ['SystemRoot' => $root . '/missing-windows']));
        $assert($exit === 0 && $output === 'REFUSED' && !file_exists($root . '/config.php'), 'Missing Windows PowerShell refuses admission without fallback: ' . $error);
    }
    if ($configurationOnly) {
        echo "PASS Windows private configuration platform ({$checks} checks, isolated files, no database or schema inputs)\n";
        return;
    }
    foreach (['App', 'assets', 'tools', 'migrations'] as $directory) {
        $source = $workspace . '/' . $directory;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            if ($directory === 'assets' && preg_match('~^build(?:[/\\\\]|$)~', $relative)) { continue; }
            $target = $root . '/' . $directory . '/' . $relative;
            if ($file->isDir()) { if (!is_dir($target)) { mkdir($target, 0775, true); } }
            else { if (!is_dir(dirname($target))) { mkdir(dirname($target), 0775, true); } copy($file->getPathname(), $target); }
        }
    }
    copy($workspace . '/index.php', $root . '/index.php');
    // Exercise a signed product shape rather than an unversioned source checkout.
    $prepare = "define('TINYCAT', true); require " . var_export($root . '/App/Core/Runtime/autoload.php', true)
        . "; $" . "plan = \\TinyCat\\Core\\Modules\\Distribution::describe(" . var_export($root, true) . ", 'tinycat.core', []); file_put_contents("
        . var_export($root . '/App/distribution.json', true) . ", json_encode($" . "plan, JSON_THROW_ON_ERROR)); \\TinyCat\\Support\\AssetCompiler::publish();";
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($prepare) . ' 2>&1', $prepareOutput, $prepareExit);
    $assert($prepareExit === 0, 'The isolated core distribution is prepared: ' . implode(' ', $prepareOutput));

    $serverDb->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $http = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', $address, '-t', $root, $root . '/index.php'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root);
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if (is_resource($probe)) { fclose($probe); break; }
        usleep(50_000);
    }
    $cookie = '';
    $csrf = '';
    $request = static function (string $method, string $path, ?array $body = null, bool $token = true, bool $json = true) use ($address, &$cookie, &$csrf): array {
        $headers = ['Content-Type: application/json', 'Accept: ' . ($json ? 'application/json' : 'text/html')];
        if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($token && $csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
        // Full fresh DDL can exceed a normal request deadline on slow fixture disks.
        $timeout = $path === '/api/installation/steps/tables' ? 120 : 30;
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => $timeout]]);
        $text = file_get_contents('http://' . $address . $path, false, $context);
        $received = http_get_last_response_headers() ?? [];
        foreach ($received as $header) {
            if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $header, $match)) { $cookie = $match[1]; }
        }
        $GLOBALS['install_api_last_response'] = substr((string) $text, 0, 1500);
        preg_match('/\s(\d{3})\s/', $received[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), json_decode((string) $text, true), (string) $text];
    };
    if (!is_dir($root . '/storage/updates')) { mkdir($root . '/storage/updates', 0700, true); }
    $descriptor = file_get_contents($root . '/App/distribution.json');
    file_put_contents($root . '/App/distribution.json', '{invalid');
    file_put_contents($root . '/config.php', "<?php return ['install' => ['complete' => true]];\n");
    foreach (['preparing', 'applying', 'failed', 'restoring', 'unknown'] as $phase) {
        file_put_contents($root . '/storage/updates/operation.json', json_encode(['kind' => 'installation', 'phase' => $phase], JSON_THROW_ON_ERROR));
        [$status] = $request('GET', '/');
        $assert($status === 503, 'An unfinished installation blocks the normal HTTP entry for phase ' . $phase);
        [$status] = $request('GET', '/install/recovery');
        $assert($status === 503, 'Recovery rejects a session not bound to the journal for phase ' . $phase);
    }
    unlink($root . '/storage/updates/operation.json');
    unlink($root . '/config.php');
    file_put_contents($root . '/App/distribution.json', $descriptor);
    [$status, , $html] = $request('GET', '/install');
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $match);
    $csrf = $match[1] ?? '';
    $assert($status === 200 && $csrf !== '' && str_contains($html, 'action="/api/installation/steps/language"'), 'An installation without config or database renders an API-backed form.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'en'], false);
    $assert($status === 403 && $result['code'] === 'csrf_token_mismatch', 'Installation writes require the shared CSRF policy.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'invalid']);
    $assert($status === 422 && !$result['ok'], 'Invalid installation input uses the common error envelope.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'cs']);
    $assert($status === 200 && $result['data']['step'] === 'db' && $result['data']['redirect'] === '/install?step=db', 'JSON input advances the language step.');
    $input = ['host' => $db['host'], 'port' => (int) ($db['port'] ?? 3306), 'name' => $name, 'user' => $db['user'], 'password' => $db['password'], 'charset' => 'utf8mb4'];
    [$status, $result, $text] = $request('POST', '/api/installation/steps/db', $input);
    $assert($status === 200 && $result['data']['step'] === 'tables' && !array_key_exists('database', $result['data']), 'Database setup returns step state without exposing credentials.');
    // Inject a process-visible DDL failure only into the isolated production copy.
    $installerFile = $root . '/App/Core/Installation/Installer.php';
    $installerSource = file_get_contents($installerFile);
    $injected = str_replace('\\TinyCat\\Database\\Migrations::prepare($pdo);', '\\TinyCat\\Database\\Migrations::prepare($pdo); throw new \\RuntimeException("Injected installation failure.");', $installerSource);
    $assert($injected !== $installerSource, 'The isolated DDL failure point exists.');
    file_put_contents($installerFile, $injected);
    [$status] = $request('POST', '/api/installation/steps/tables', []);
    $assert($status === 500, 'DDL failure leaves an interrupted installation.');
    file_put_contents($installerFile, $installerSource);
    $operation = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 32, JSON_THROW_ON_ERROR);
    $assert($operation['kind'] === 'installation' && $operation['phase'] === 'failed' && $operation['step'] === 'tables', 'The real installer writes a recoverable operation.');
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $name, $db['user'], $db['password'], $options);
    $assert($pdo->query('SHOW TABLES')->fetch() !== false, 'The injected failure happens after actual DDL.');
    [$status] = $request('POST', '/api/installation/steps/tables', []);
    $assert($status === 503, 'An interrupted step cannot be repeated before restoration.');
    $boundCookie = $cookie;
    $cookie = '';
    [$status] = $request('GET', '/install/recovery', json: false);
    $assert($status === 503, 'Another session cannot enter installation recovery.');
    $cookie = $boundCookie;
    file_put_contents($root . '/config.php', "<?php return ['install' => ['complete' => true]];\n");
    file_put_contents($root . '/App/distribution.json', '{invalid');
    unlink($root . '/storage/maintenance.json');
    [$status, , $recoveryHtml] = $request('GET', '/install/recovery', json: false);
    $assert($status === 200 && str_contains($recoveryHtml, '/api/installation/recover'), 'Bound recovery renders through the production kernel despite complete config, missing maintenance and broken descriptor.');
    preg_match('/name="csrf-token" content="([^"]+)"/', $recoveryHtml, $match);
    $csrf = $match[1] ?? '';
    $assert($csrf !== '', 'Recovery uses the shared CSRF token.');
    unlink($root . '/config.php');
    file_put_contents($root . '/App/distribution.json', $descriptor);
    [$status] = $request('POST', '/api/installation/recover', ['operation' => $operation['id']], false);
    $assert($status === 403, 'Recovery refuses a POST without shared CSRF.');
    [$status] = $request('POST', '/api/installation/recover', ['operation' => 'another-operation']);
    $assert($status === 403, 'Recovery refuses a mismatched operation ID.');
    $snapshot = $root . '/storage/updates/backups/' . $operation['backup'] . '/installation-config.json';
    $snapshotSource = file_get_contents($snapshot);
    unlink($snapshot);
    [$status] = $request('POST', '/api/installation/recover', ['operation' => $operation['id']]);
    $assert($status === 500 && $pdo->query('SHOW TABLES')->fetch() !== false, 'Missing config snapshot refuses recovery before database destruction.');
    file_put_contents($snapshot, $snapshotSource);
    [$status, $restored] = $request('POST', '/api/installation/recover', ['operation' => $operation['id']]);
    $assert($status === 200 && $restored['data']['restored'] && $pdo->query('SHOW TABLES')->fetch() === false, 'Authenticated recovery restores the pre-DDL database.');
    $restoredJournal = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 32, JSON_THROW_ON_ERROR);
    $assert($restoredJournal['phase'] === 'rolled_back' && $restoredJournal['id'] === $operation['id'] && $restoredJournal['recovery_hash'] === $operation['recovery_hash'] && !is_file($root . '/storage/maintenance.json'), 'Restoration preserves operation identity and clears maintenance after completion.');
    [$status, $result] = $request('POST', '/api/installation/steps/tables', []);
    $assert($status === 200 && $result['data']['step'] === 'admin', 'The tables step creates the isolated schema through the API.');
    [$status, $result] = $request('POST', '/api/installation/steps/tables', []);
    $assert($status === 200, 'The tables step can safely be retried.');
    file_put_contents($installerFile, str_replace('throw new \\RuntimeException("Injected installation failure.");', 'exit;', $injected));
    [$status] = $request('POST', '/api/installation/steps/tables', []);
    file_put_contents($installerFile, $installerSource);
    $cliOperation = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 32, JSON_THROW_ON_ERROR);
    $assert($cliOperation['phase'] === 'applying', 'Abrupt request termination preserves the pre-commit recovery journal without catch/finally.');
    $recoverCli = static function (array $database) use ($root, $cliOperation): array {
        $process = proc_open([PHP_BINARY, $root . '/App/Console.php', 'rollback', $cliOperation['backup'], '--installation-stdin'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cliPipes, $root);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start isolated installation rescue.'); }
        fwrite($cliPipes[0], json_encode(['database' => $database], JSON_THROW_ON_ERROR)); fclose($cliPipes[0]);
        $output = stream_get_contents($cliPipes[1]); fclose($cliPipes[1]);
        $error = stream_get_contents($cliPipes[2]); fclose($cliPipes[2]);
        return [proc_close($process), $output, $error];
    };
    $wrongName = $name . '_wrong';
    $serverDb->exec('CREATE DATABASE `' . $wrongName . '` CHARACTER SET utf8mb4');
    try {
        $wrong = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $wrongName, $db['user'], $db['password'], $options);
        $wrong->exec('CREATE TABLE sentinel (id INT)');
        [$exit] = $recoverCli(array_replace($input, ['name' => $wrongName]));
        $assert($exit === 1 && $wrong->query('SHOW TABLES')->fetchColumn() === 'sentinel', 'Rescue rejects another database without destroying its objects.');
    } finally { $wrong = null; $serverDb->exec('DROP DATABASE `' . $wrongName . '`'); }
    $boundCookie = $cookie; $cookie = '';
    [$exit, $output] = $recoverCli($input);
    $cliResult = json_decode((string) $output, true);
    $assert($exit === 0 && ($cliResult['restored'] ?? false) && $pdo->query('SHOW TABLES')->fetch() !== false, 'Private stdin rescue restores the later snapshot without a web session.');
    $cookie = $boundCookie;
    $password = 'Installer-API-Regression-42';
    [$status, $result] = $request('POST', '/api/installation/steps/admin', ['username' => 'install_api_admin', 'password' => $password, 'password_confirm' => 'different']);
    $assert($status === 422, 'Invalid administrator data fails without completing installation.');
    $priorConfig = "<?php return " . var_export(['database' => $input, 'install' => ['complete' => false, 'locale' => 'cs']], true) . ";\n";
    file_put_contents($root . '/config.php', $priorConfig); chmod($root . '/config.php', 0640);
    $actionsFile = $root . '/App/Core/Installation/Actions.php';
    $actionsSource = file_get_contents($actionsFile);
    $failedPublication = str_replace('Installer::write_config($state);', 'Installer::write_config($state); throw new \\RuntimeException("Interrupted after configuration publication.");', $actionsSource);
    $assert($failedPublication !== $actionsSource, 'The isolated configuration publication failure point exists.');
    file_put_contents($actionsFile, $failedPublication);
    [$status] = $request('POST', '/api/installation/steps/admin', ['username' => 'install_api_admin', 'password' => $password, 'password_confirm' => $password]);
    $assert($status === 500 && (require $root . '/config.php')['install']['complete'], 'A failure after configuration publication remains uncommitted.');
    file_put_contents($actionsFile, $actionsSource);
    [$status] = $request('GET', '/');
    $assert($status === 503, 'Published config cannot bypass the uncommitted installation journal.');
    $adminOperation = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 32, JSON_THROW_ON_ERROR);
    $assertPrivate($root . '/storage/updates/backups/' . $adminOperation['backup'] . '/installation-config.php');
    [$status, , $recoveryHtml] = $request('GET', '/install/recovery', json: false);
    $assert($status === 200, 'The same installer session can recover the admin step after config.complete.');
    preg_match('/name="csrf-token" content="([^"]+)"/', $recoveryHtml, $match); $csrf = $match[1] ?? '';
    [$status] = $request('POST', '/api/installation/recover', ['operation' => $adminOperation['id']]);
    $assert($status === 200 && file_get_contents($root . '/config.php') === $priorConfig, 'Admin recovery restores exact prior bytes; Windows ACL is normalized.');
    $assertPrivate($root . '/config.php', 0640);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0 && !is_file($root . '/storage/deployment.json'), 'Admin recovery preserves tables but removes the uncommitted account and deployment.');
    [$status, $result] = $request('POST', '/api/installation/steps/admin', ['username' => 'install_api_admin', 'password' => $password, 'password_confirm' => $password]);
    $assert($status === 200 && $result['data']['step'] === 'done' && $result['data']['redirect'] === '/admin', 'The final API step creates the administrator and configuration.');
    $assertPrivate($root . '/config.php');
    $deployment = json_decode((string) file_get_contents($root . '/storage/deployment.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert($deployment['product'] === 'tinycat.core' && $deployment['active'] === [] && $deployment['installed'] === [], 'Web installation records the same core deployment as CLI installation');
    $activate = "define('TINYCAT', true); require " . var_export($root . '/App/Core/Runtime/autoload.php', true)
        . "; \\TinyCat\\Core\\Runtime\\Application::start(); \\TinyCat\\Core\\Modules\\Lifecycle::activate([], \\TinyCat\\Core\\Modules\\Deployment::read(" . var_export($root, true) . "));";
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($activate) . ' 2>&1', $activateOutput, $activateExit);
    $assert($activateExit === 0, 'Module lifecycle accepts the web-installed product: ' . implode(' ', $activateOutput));
    $installed = require $root . '/config.php';
    $assert($installed['install']['complete'] === true && $installed['install']['locale'] === 'cs' && $installed['database']['name'] === $name, 'Only the isolated configuration is written.');
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $name, $db['user'], $db['password'], $options);
    $row = $pdo->query("SELECT role, password FROM users WHERE username = 'install_api_admin'")->fetch();
    $assert($row['role'] === 'admin' && password_verify($password, $row['password']), 'The isolated administrator has the correct role and a verified password hash.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'cs']);
    $assert($status === 404 && $result['code'] === 'not_found', 'Completed installations reject further setup writes.');
    foreach (['/install', '/install?step=done', '/install?step=db', '/api/installation', '/api/installation/steps/unknown'] as $path) {
        [$status, , $body] = $request('GET', $path, null, true, false);
        $assert($status === 404 && !str_contains($body, 'data-installation-form'), 'Completed installations hide ' . $path);
    }
    [$status, , $body] = $request('HEAD', '/install', null, true, false);
    $assert($status === 404 && $body === '', 'Installed setup HEAD requests reveal no document.');
    $installed['install']['complete'] = false;
    file_put_contents($root . '/config.php', "<?php\nreturn " . var_export($installed, true) . ";\n");
    [$status] = $request('GET', '/install');
    $assert($status === 404, 'The wizard also stays hidden when the initialized database is detected without the config flag.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'en']);
    $assert($status === 404 && $result['code'] === 'not_found', 'An existing initialized database stays locked even if the config flag is missing and the wizard was visited.');
    [$status] = $request('POST', '/install', []);
    $assert($status === 404, 'Installed setup is hidden for page POST requests as well.');
    $assert(hash_file('sha256', $workspace . '/config.php') === $configHash, 'The real workspace configuration remains byte-for-byte unchanged.');
    echo "PASS complete installation API ({$checks} checks, isolated copy and database)\n";
} finally {
    if (is_resource($http)) { proc_terminate($http); proc_close($http); }
    if (is_file($root . '/server.log')) { copy($root . '/server.log', \TinyCat\Tools\Workspace::directory('tests') . '/installation-platform-http.log'); }
    foreach ($pipes ?? [] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    $pdo = null;
    if ($created) { $serverDb->exec('DROP DATABASE `' . $name . '`'); }
    $resolvedRoot = realpath($root);
    $resolvedStorage = realpath(\TinyCat\Tools\Workspace::directory('tests/tmp'));
    if ($resolvedRoot !== false && $resolvedStorage !== false && str_starts_with($resolvedRoot, $resolvedStorage . DIRECTORY_SEPARATOR . 'install-api-')) {
        // Windows scanners can briefly retain a handle after the HTTP worker exits.
        for ($attempt = 0; $attempt < 5 && is_dir($resolvedRoot); $attempt++) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir() && !$file->isLink()) { @rmdir($file->getPathname()); } else { @unlink($file->getPathname()); }
            }
            @rmdir($resolvedRoot);
            if (is_dir($resolvedRoot)) { usleep(100_000 * ($attempt + 1)); }
        }
        if (is_dir($resolvedRoot)) { throw new RuntimeException('Unable to remove the isolated installation fixture.'); }
    }
}
