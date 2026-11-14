<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;

/** Account writes with explicitly supplied domain participants; no product discovery. */
final class Lifecycle
{
    /** @param array<mixed> $user
     * @param array<string, Participant> $participants */
    public static function setActive(array $user, bool $active, array $participants = []): void
    {
        Transactions::run(static function () use ($user, $active, $participants): void {
            Connection::update('users', ['status' => $active ? 'active' : 'ban'], ['id' => (int) $user['id']]);
            foreach ($participants as $participant) { ($participant->statusChanged)($user, $active); }
        });
    }

    /** @param array<string, Participant> $participants
     * @return array<mixed>|null */
    public static function delete(int $userId, array $participants = []): ?array
    {
        if ($userId < 1) { return null; }
        // This operation owns the commit boundary for irreversible file cleanup.
        if (Connection::get()->inTransaction()) { throw new \LogicException('Account deletion must own its transaction.'); }
        $effects = [];
        $user = Transactions::run(static function () use ($userId, $participants, &$effects): ?array {
            $user = Connection::one(Connection::lockingSelect('SELECT * FROM users WHERE id = ? LIMIT 1'), [$userId]);
            if ($user === null) { return null; }
            foreach ($participants as $participant) { $effects[] = ($participant->prepareDelete)($user); }
            Connection::delete('users', ['id' => $userId]);
            foreach ($effects as $effect) { ($effect->afterDelete)(); }
            return $user;
        });
        foreach ($effects as $effect) { ($effect->afterCommit)(); }
        return $user;
    }
}
