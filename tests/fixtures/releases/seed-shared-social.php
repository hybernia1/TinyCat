<?php
declare(strict_types=1);

/** Representative data for a disposable shared-storage baseline, never a live installer. */
return static function (PDO $pdo): void {
    $name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (preg_match('/^tinycat_release_update_[a-f0-9]{12}$/D', $name) !== 1) { throw new RuntimeException('A disposable release rehearsal database is required.'); }
    $user = $pdo->prepare("INSERT INTO users(username,password,role,status,locale,bio) VALUES(?,?,'user','active','en','Fixture profile')");
    $password = password_hash('Private-Rehearsal-Only-2026', PASSWORD_ARGON2ID);
    $users = [];
    for ($i = 0; $i < 60; $i++) { $user->execute(['release_fixture_' . $i, $password]); $users[] = (int) $pdo->lastInsertId(); }
    $pdo->exec("INSERT INTO terms(name) VALUES('rehearsal')"); $term = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO links(normalized_url,url_hash,provider,link_type,title) VALUES(?,?,'','web','Retained link')")->execute(['https://93.184.216.34/rehearsal', hash('sha256', 'https://93.184.216.34/rehearsal')]); $link = (int) $pdo->lastInsertId();
    $post = $pdo->prepare("INSERT INTO content(author_id,body,published_at) VALUES(?,?,'2000-01-01 12:00:00')");
    $tag = $pdo->prepare('INSERT INTO content_tags(content_id,term_id) VALUES(?,?)');
    $attach = $pdo->prepare('INSERT INTO content_links(content_id,link_id) VALUES(?,?)');
    $like = $pdo->prepare('INSERT INTO content_likes(content_id,user_id) VALUES(?,?)');
    $comment = $pdo->prepare("INSERT INTO content_comments(content_id,parent_id,user_id,body,created_at) VALUES(?,?,?,?,'2000-01-01 12:01:00')");
    $reaction = $pdo->prepare('INSERT INTO comment_likes(comment_id,user_id) VALUES(?,?)');
    $follow = $pdo->prepare('INSERT INTO user_followers(user_id,follower_id) VALUES(?,?)');
    $notification = $pdo->prepare("INSERT INTO notifications(user_id,actor_id,content_id,comment_id,type,notification_key,created_at,updated_at) VALUES(?,?,?,?,'content_comment',?,'2000-01-01 12:01:00','2000-01-01 12:01:00')");
    $pdo->beginTransaction();
    try {
        foreach ($users as $i => $author) {
            $actor = $users[($i + 1) % count($users)]; $follow->execute([$author,$actor]);
            for ($p = 0; $p < 4; $p++) {
                $post->execute([$author, 'Retained post ' . $i . ':' . $p . ' #rehearsal https://93.184.216.34/rehearsal']); $id = (int) $pdo->lastInsertId();
                $tag->execute([$id,$term]); $attach->execute([$id,$link]); $like->execute([$id,$actor]);
                $parent = null;
                for ($c = 0; $c < 10; $c++) {
                    $comment->execute([$id,$c % 2 === 0 ? null : $parent,$actor,'Retained comment ' . $c]); $commentId = (int) $pdo->lastInsertId();
                    if ($c % 2 === 0) { $parent = $commentId; }
                    $reaction->execute([$commentId,$author]);
                    $notification->execute([$author,$actor,$id,$commentId,'rehearsal:' . $id . ':' . $commentId]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
};
