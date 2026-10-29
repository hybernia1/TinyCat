-- NOT run automatically. Requires TinyCat 5.6.0 migration and a verified backup.
-- First review/export 02_unique_candidates.sql and all domain collisions.
-- This script inserts source relations ONLY, never changes post text or times.
-- Existing relations are preserved. Default ROLLBACK makes this a dry run.
-- To persist, explicitly replace the final ROLLBACK with COMMIT after review.

START TRANSACTION;

INSERT INTO bot_source_posts (content_id, source_id)
WITH
feed_authorities AS (
    SELECT id AS source_id, bot_user_id, name AS source_name, feed_url,
        LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(feed_url, '://', -1), '/', 1), '?', 1), '#', 1)) AS authority
    FROM bot_sources WHERE LOWER(feed_url) REGEXP '^https?://'
),
feed_hosts AS (
    SELECT source_id, bot_user_id, source_name, feed_url,
        TRIM(TRAILING '.' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(authority, '@', -1), ':', 1)) AS host
    FROM feed_authorities
),
feed_domains AS (
    SELECT source_id, bot_user_id, source_name, feed_url,
        CASE WHEN host LIKE 'www.%' THEN SUBSTRING(host, 5) ELSE host END AS domain
    FROM feed_hosts WHERE host REGEXP '^[a-z0-9][a-z0-9.-]*[a-z0-9]$' AND LOCATE('.', host) > 0
),
bot_posts AS (
    SELECT c.id AS content_id, c.author_id, c.published_at, LEFT(c.body, 180) AS excerpt
    FROM content c INNER JOIN users u ON u.id = c.author_id WHERE u.role = 'bot'
),
link_authorities AS (
    SELECT DISTINCT p.content_id,
        LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(l.normalized_url, '://', -1), '/', 1), '?', 1), '#', 1)) AS authority
    FROM bot_posts p INNER JOIN content_links cl ON cl.content_id = p.content_id
    INNER JOIN links l ON l.id = cl.link_id WHERE LOWER(l.normalized_url) REGEXP '^https?://'
),
link_hosts AS (
    SELECT content_id, TRIM(TRAILING '.' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(authority, '@', -1), ':', 1)) AS host
    FROM link_authorities
),
post_domains AS (
    SELECT DISTINCT content_id, CASE WHEN host LIKE 'www.%' THEN SUBSTRING(host, 5) ELSE host END AS domain
    FROM link_hosts WHERE host REGEXP '^[a-z0-9][a-z0-9.-]*[a-z0-9]$' AND LOCATE('.', host) > 0
),
domain_totals AS (
    SELECT content_id, COUNT(*) AS domain_count, GROUP_CONCAT(domain ORDER BY domain SEPARATOR ', ') AS domains
    FROM post_domains GROUP BY content_id
),
candidates AS (
    SELECT DISTINCT p.content_id, f.source_id
    FROM bot_posts p INNER JOIN post_domains d ON d.content_id = p.content_id
    INNER JOIN feed_domains f ON f.bot_user_id = p.author_id AND f.domain = d.domain
),
candidate_totals AS (
    SELECT content_id, COUNT(*) AS source_count, MIN(source_id) AS candidate_source_id,
        GROUP_CONCAT(source_id ORDER BY source_id SEPARATOR ',') AS source_ids
    FROM candidates GROUP BY content_id
),
classified AS (
    SELECT p.*, COALESCE(d.domain_count, 0) AS domain_count, d.domains,
        COALESCE(c.source_count, 0) AS source_count, c.candidate_source_id, c.source_ids,
        CASE
            WHEN COALESCE(d.domain_count, 0) = 0 THEN 'no_link_domain'
            WHEN COALESCE(c.source_count, 0) = 0 THEN 'no_feed_match'
            WHEN c.source_count > 1 THEN 'ambiguous_sources'
            WHEN d.domain_count > 1 THEN 'multiple_link_domains'
            ELSE 'unique_single_domain'
        END AS match_status
    FROM bot_posts p LEFT JOIN domain_totals d ON d.content_id = p.content_id
    LEFT JOIN candidate_totals c ON c.content_id = p.content_id
)
SELECT c.content_id, c.candidate_source_id
FROM classified c LEFT JOIN bot_source_posts existing ON existing.content_id = c.content_id
WHERE c.match_status = 'unique_single_domain' AND existing.content_id IS NULL;

SELECT ROW_COUNT() AS would_insert_relations;

ROLLBACK;
