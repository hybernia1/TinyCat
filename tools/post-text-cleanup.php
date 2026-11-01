<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('TINYCAT')) { define('TINYCAT', true); }
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Status\LinkParser;
use TinyCat\Status\PostText;
use TinyCat\Status\Tags;

/** A saved plan is also the exact before/after backup. No metadata is rewritten. */
final class PostTextCleanup
{
    public static function safe(string $before, string $after): bool
    {
        preg_match_all(LinkParser::pattern(), $before, $oldLinks);
        preg_match_all(LinkParser::pattern(), $after, $newLinks);
        preg_match_all('/(?<![\p{L}\p{N}_])@[\p{L}\p{N}_-]+/u', $before, $oldMentions);
        preg_match_all('/(?<![\p{L}\p{N}_])@[\p{L}\p{N}_-]+/u', $after, $newMentions);
        return $after !== '' && $oldLinks === $newLinks && $oldMentions === $newMentions
            && Tags::status_tag_tokens_from_text($before) === Tags::status_tag_tokens_from_text($after);
    }

    public static function plan(PDO $pdo): array
    {
        $plan = ['version' => 1, 'database' => self::database($pdo), 'created_at' => gmdate(DATE_ATOM), 'scanned' => 0, 'skipped' => [], 'changes' => []];
        $cursor = 0;
        do {
            $query = $pdo->prepare('SELECT id, author_id, published_at, body FROM content WHERE id > ? ORDER BY id LIMIT 500');
            $query->execute([$cursor]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $plan['scanned']++;
                $after = PostText::normalize($row['body']);
                if ($after === $row['body']) { continue; }
                if (!self::safe($row['body'], $after)) { $plan['skipped'][] = $cursor; continue; }
                $plan['changes'][] = $row + ['after' => $after];
            }
        } while (count($rows) === 500);
        return $plan;
    }

    private static function database(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? (string) $pdo->query('SELECT DATABASE()')->fetchColumn() : 'sqlite';
    }

    public static function apply(PDO $pdo, array $plan, bool $restore = false): int
    {
        if (($plan['version'] ?? null) !== 1 || ($plan['database'] ?? '') !== self::database($pdo) || !is_array($plan['changes'] ?? null)) {
            throw new RuntimeException('Invalid cleanup plan.');
        }
        if ($pdo->inTransaction()) { throw new RuntimeException('Cleanup requires its own transaction.'); }
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($plan['changes'], 200) as $batch) {
                $ids = array_map(static fn (array $row): int => (int) $row['id'], $batch);
                if (count(array_unique($ids)) !== count($ids)) { throw new RuntimeException('Duplicate plan IDs.'); }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $lock = $pdo->prepare('SELECT id, author_id, published_at, body FROM content WHERE id IN (' . $placeholders . ') ORDER BY id'
                    . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $lock->execute($ids);
                $currentRows = [];
                foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $current) { $currentRows[(int) $current['id']] = $current; }
                $cases = [];
                $parameters = [];
                foreach ($batch as $row) {
                    if (PostText::normalize($row['body']) !== $row['after'] || !self::safe($row['body'], $row['after'])) {
                        throw new RuntimeException('Plan no longer matches cleanup rules.');
                    }
                    $current = $currentRows[(int) $row['id']] ?? null;
                    $expected = $restore ? $row['after'] : $row['body'];
                    if (!$current || $current['body'] !== $expected || (int) $current['author_id'] !== (int) $row['author_id']
                        || $current['published_at'] !== $row['published_at']) {
                        throw new RuntimeException('Concurrent change at post ' . $row['id'] . '; entire batch rolled back.');
                    }
                    $cases[] = 'WHEN ? THEN ?';
                    array_push($parameters, (int) $row['id'], $restore ? $row['body'] : $row['after']);
                }
                $update = $pdo->prepare('UPDATE content SET body = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . $placeholders . ')');
                $update->execute([...$parameters, ...$ids]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        }
        return count($plan['changes']);
    }
}

function postTextCleanupMain(array $arguments): void
{
    [$command, $file] = array_pad(array_slice($arguments, 1), 2, '');
    if (!in_array($command, ['plan', 'apply', 'restore'], true) || $file === '') {
        throw new RuntimeException('Usage: php tools/post-text-cleanup.php plan|apply|restore /private/path/plan.json');
    }
    if ($command === 'plan') {
        $plan = PostTextCleanup::plan(Connection::get());
        $handle = fopen($file, 'x');
        if ($handle === false) { throw new RuntimeException('Backup must be a new writable file.'); }
        chmod($file, 0600);
        try {
            $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json) !== strlen($json)) { throw new RuntimeException('Incomplete backup.'); }
        } finally { fclose($handle); }
        echo json_encode(['scanned' => $plan['scanned'], 'changes' => count($plan['changes']), 'skipped' => $plan['skipped']], JSON_THROW_ON_ERROR), "\n";
        return;
    }
    $plan = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    echo $command, ': ', PostTextCleanup::apply(Connection::get(), $plan, $command === 'restore'), "\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    postTextCleanupMain($argv);
}
