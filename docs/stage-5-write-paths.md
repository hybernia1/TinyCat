# Stage 5 write-path and policy report

Stage 5 compares the 2.0.26 monolith after commit `837af44` with the behavior
implemented by the archived 2.5 experiment. Only observable policy and
transaction improvements were retained. No service, repository, port,
controller or compatibility class was copied from 2.5.

## Differential decision matrix

| Write path | Decision in 2.0.26 | Verification |
|---|---|---|
| Status create | Retain the existing aggregate transaction. Avoid loading the full status for JSON-only responses. | Creates content and tag relations; response identity is stable. |
| Status edit | Retain the existing aggregate transaction; nested tag/link synchronizers are safe when called directly too. | Existing identity and 12 tag relations persist together. |
| Status delete | Put likes, comments, comment likes, notifications, tags, links, image metadata, reports and content deletion in one transaction. Delete the image file only after commit. | A forced final-delete failure restores every table; success removes the aggregate and returns the file path. |
| Tags | Remove the arbitrary maximum of ten unique tags. Keep the 32-character tag bound and 2,000-character content storage bound. | Parsing, validation, create and edit accept 12 unique tags. A forced attach failure restores old tags and new term rows. |
| Links | Make relation replacement transactional and propagate non-unique database failures. | A forced second attach failure restores the previous link relation. |
| Images/avatars | Keep status image files outside database rollback and delete newly uploaded avatar files if the user-row update fails. | Status deletion returns cleanup data only after commit; the failure cleanup branch is statically analyzed. Deeper decoder hardening remains Stage 6. |
| Comment create/nesting | Keep the single primary insert and post-commit notification effects. Flatten replies deeper than one level to the root. | Root, reply and nested reply creation plus parent normalization pass. |
| Comment edit | Retain the existing row-lock transaction, history and authorization checks. | Policy checks and the existing response/static-analysis suites pass. |
| Comment delete | Delete child/root likes, notifications, replies and root in one transaction. | A forced root-delete failure restores all rows; success removes the complete comment aggregate. |
| Status/comment reactions | Keep one-row mutations without an otherwise empty transaction layer. | Both add/remove cycles pass and counts remain consistent. |
| Follow/unfollow | Keep one-row relation mutations and the email as a subsequent optional effect. | Follow and unfollow cycles pass. |
| Profile/admin user edit | Save the user row and complete profile-link replacement in one transaction. | A forced second-link failure restores the old user row and old links. Both public profile and admin route use the same procedural owner. |
| Account deletion | Retain the existing database transaction and post-commit asset deletion. | Existing account/update tests remain green. |
| Password recovery | Replace old/new tokens atomically. Consume an unexpired token conditionally and update the active user's password in the same transaction. | Forced replacement failure restores the old token; a consumed token cannot overwrite the password a second time. |
| Reports/moderation | Retain the existing report-review transaction. Preserve removal notifications by storing them without the soon-to-be-deleted content foreign key. | The removal call explicitly drops the target; report race handling now swallows only a proven unique violation. |

## Error handling and boundaries

`db_unique_violation()` recognizes PostgreSQL `23505`, MySQL/MariaDB duplicate
entry 1062 and SQLite unique/primary-key failures. Tag/link/report race paths
use it instead of swallowing every `Throwable`; connectivity, trigger, foreign
key and other write failures now escape and roll back. Tests separately prove
that a unique constraint is recognized and a NOT NULL constraint is not.

Every status mutation still passes through `Api::statusAction()`, with
authentication, CSRF validation and the request interval guard executed before
dispatch. Ownership, administrator, moderation-lock and mute/rate-limit checks
remain at their original entry points. External file deletion remains after a
successful database commit so rollback never points the database at a file
that was already removed.

## Verification

`tests/write-path-integrity.php` runs 74 policy and persistence checks against
SQLite, including deliberately failing triggers in the middle or at the end of
an aggregate mutation. The full preflight passes 19 groups:

- deterministic style and PHP 8.4 lint for 156/157 files;
- PHPStan level 8 with no new or enlarged baseline entry;
- public route and presentation contracts;
- hot-read query counts unchanged at `3/6/4/8/7`;
- extension, updater, signed package and rollback tests;
- restart-safe disposable MySQL installer rehearsal.

## Decision

Accept Stage 5. Multi-table business aggregates are atomic, arbitrary ten-tag
validation is gone, recovery tokens cannot be reused, and moderation removal
notifications survive their content foreign-key deletion. Single-row writes
remain single-row writes; adding transaction/service ceremony around them
would not improve atomicity. Security and image-decoder hardening not required
for these behavior changes remains explicitly assigned to Stage 6.
