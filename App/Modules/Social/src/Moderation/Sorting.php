<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

/** SQL allowlists and navigation owned by these lists. */
final class Sorting
{
    public const LISTS = [
        'reports' => ['/api/moderation/reports', '/admin/moderation/reports', '#moderation-reports', ['content' => 'c.body', 'reason' => 'cr.reason', 'status' => 'CASE WHEN rc.open_count > 0 THEN 0 ELSE 1 END'], 'CASE WHEN rc.open_count > 0 THEN 0 ELSE 1 END, rc.latest_reported_at DESC, cr.id DESC', 'cr.id'],
    ];
}
