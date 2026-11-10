-- Read-only inventory. Run each SELECT separately in HeidiSQL.
SELECT DATABASE() AS database_name, VERSION() AS server_version;
SELECT COUNT(*) AS posts_total FROM content;
SELECT u.id AS bot_user_id, u.username, COUNT(c.id) AS posts
FROM users u LEFT JOIN content c ON c.author_id=u.id WHERE u.role='bot'
GROUP BY u.id,u.username ORDER BY posts DESC;
SELECT id,bot_user_id,name,feed_url,enabled FROM bot_sources ORDER BY bot_user_id,id;
SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bot_source_posts';
