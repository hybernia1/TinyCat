<?php
declare(strict_types=1);

// Explicit adoption of an authenticated legacy global installation.
return static function (\PDO $pdo): bool {
    if (!\TinyCat\Database\SchemaInspection::exists($pdo, 'users')) { return false; }
    \TinyCat\Database\SchemaInspection::requireMigration($pdo, '20261113_001_shared_interactions', '083cee738cc4821c8e4d84365e2059e3e1f291e4943385def6a3213bd58228c3');
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'users', ['id', 'username', 'password', 'role', 'status', 'email', 'locale', 'theme', 'avatar_exists', 'created_at', 'updated_at']);
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'password_reset_tokens', ['id', 'user_id', 'token_hash', 'expires_at', 'used_at']);
    return true;
};
