-- Read-only. Run only AFTER bot_source_posts exists (TinyCat 5.6.0).
SELECT COUNT(*) AS known_relations FROM bot_source_posts;
SELECT COUNT(*) AS unattributed_bot_posts
FROM content c JOIN users u ON u.id=c.author_id
LEFT JOIN bot_source_posts bsp ON bsp.content_id=c.id
WHERE u.role='bot' AND bsp.content_id IS NULL;
SELECT bsp.source_id,bs.name,bs.bot_user_id,COUNT(*) AS posts,
    SUM(c.published_at <= CURRENT_TIMESTAMP) AS published,
    SUM(c.published_at > CURRENT_TIMESTAMP) AS queued
FROM bot_source_posts bsp JOIN bot_sources bs ON bs.id=bsp.source_id
JOIN content c ON c.id=bsp.content_id GROUP BY bsp.source_id,bs.name,bs.bot_user_id
ORDER BY posts DESC;
SELECT bsp.content_id,bsp.source_id,c.author_id,bs.bot_user_id
FROM bot_source_posts bsp JOIN bot_sources bs ON bs.id=bsp.source_id
JOIN content c ON c.id=bsp.content_id WHERE c.author_id<>bs.bot_user_id;
