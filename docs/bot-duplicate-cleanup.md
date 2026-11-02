# Automatic cleanup of duplicate bot posts

`duplicate_bot_posts` runs inside the existing scheduled `cleanup` task. It has
no separate administration interface and does not change RSS importing or GUID
history. Each invocation merges at most 500 newer copies; `has_more` allows the
next invocation to continue.

A pair qualifies only when:

- Both posts belong to the same active bot account.
- Both are published and their publication time has arrived.
- Their non-empty text matches exactly, including case and whitespace.
- Their link IDs and tag IDs are identical sets.
- Both have no attachment, or their attachment path, dimensions and byte count
  are identical. Different image files are not guessed to be equivalent.

The lowest qualifying post ID survives, with its original text, author and
publication time. Different metadata creates separate matching groups even when
their text is identical. Missing or deleted RSS sources do not prevent cleanup.

Each pair is rechecked under database row locks and merged in one transaction.
Likes become a union of users. Comments keep their IDs, parent relationships,
edit history and likes. Reports and notifications move to the surviving post.
Notification keys containing the content ID are updated; collisions collapse
without losing unread state. No new notifications or emails are sent.

The newer post is then deleted through the existing post-deletion service.
Shared image files remain in place; the normal orphan-file task handles files
that eventually become unreferenced. A failed pair rolls back completely;
earlier successful pairs in the batch remain committed.

The only new table is `content_redirects(old_id, content_id)`. Its target has a
foreign key to `content` with `ON DELETE CASCADE`. Old public post addresses return
301 to the surviving public post. Read-only post and outbound-link API resources
also resolve the mapping; editing an old ID does not silently edit the target.
Aliases are flattened if their target is merged again. Hidden, unpublished or
inactive targets are not exposed by redirects.

Migration: `20261102_001_compact_content_redirects.php`. It also preserves valid
mappings from the earlier unreleased, wider local table while reducing it to two
columns. The existing source and GUID-history tables remain unchanged.

Verification:

```sh
php tests/bots/duplicate-cleanup.php
php tests/content-links.php
php tests/cron/run.php
php tests/cron/retention.php
php tests/http/api-contract.php
```
