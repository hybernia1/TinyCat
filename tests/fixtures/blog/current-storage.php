<?php
declare(strict_types=1);

// A current version must not adopt core tables whose signed history is missing.
$core = ['tinycat.security' => \TinyCat\Core\Security\Schema::schema()];
$beforeLimits = $pdo->query('SELECT * FROM ip_action_limits ORDER BY ip_address, action_name, bucket_start')->fetchAll(PDO::FETCH_ASSOC);
$pdo->beginTransaction();
try {
    $pdo->exec("DELETE FROM migrations WHERE kind = 'schema' AND stream = 'tinycat.security'");
    $pdo->exec("DELETE FROM module_installations WHERE module_id = 'tinycat.security'");
    try {
        \TinyCat\Database\ModuleSchemas::pending($pdo, $core);
        throw new LogicException('Unmanaged core storage was accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'Refusing to adopt an unmanaged table: ip_action_limits'), 'Current core rejects missing signed ownership instead of invoking global-table adoption');
    }
    $assert($beforeLimits === $pdo->query('SELECT * FROM ip_action_limits ORDER BY ip_address, action_name, bucket_start')->fetchAll(PDO::FETCH_ASSOC), 'Rejected adoption leaves existing core records untouched');
} finally {
    $pdo->rollBack();
}
$assert(!\TinyCat\Database\ModuleSchemas::pending($pdo, $core), 'Restoring the original state and history makes current ownership verifiable');
