# Stage 9: TinyCat 2.0.26 release candidate

Stage 9 prepares a local, signed 2.0.26 release candidate. Nothing was tagged,
pushed, uploaded or published. The release remains blocked on an explicit
publication decision even though the technical gates below pass.

## Release identity

- Runtime and README version: `2.0.26`
- Supported update source: `2.0.25`
- Minimum PHP: `8.4.0`
- Package: `tinycat-2.0.26.zip`
- Package size: `1,431,323` bytes
- Package SHA-256:
  `ac548bef4db7418332506c5d2767cdf8924d7b81c5f010a789191e4cc775137e`
- Manifest SHA-256:
  `fcf85e979b9e8c97e00a0ed9402513f95fa6f4b346c83863011e4341819fd90a`
- Signature SHA-256:
  `1b715246a8b59bcfed1552c6e99154dd62098329ae25f971c1c2967a887f9051`

The local files are below `dist/release-2.0.26/`, which is ignored by Git. The
signature verifies with the public key pinned in the released 2.0.25 updater.

## Artifact boundary

The signed manifest contains 122 files, no deletions, and the two migration
files already shipped by 2.0.25:

- `migrations/20260806_001_comment_history.php`
- `migrations/20260806_003_content_images.php`

The rehearsal records both migrations as already applied by 2.0.25. The updater
therefore reports no pending migration, applies none and does not create an
unnecessary database backup. Schema and application data hashes remain equal.

Configuration, uploads, storage, tools, tests, dependencies, Git metadata,
benchmark data and internal stage reports are absent from the ZIP. The new
repository `CHANGELOG.md` is also intentionally absent: 2.0.25 does not allow a
previously unknown top-level managed file. Adding it to this package would make
the released updater reject the whole release before installation.

ZIP contents are inserted in sorted order from bytes rather than filesystem
metadata, and every local and central-directory timestamp is normalized from
`SOURCE_DATE_EPOCH` (the ZIP epoch by default). Two independent builds from the
same source are byte-identical. The final artifact was rebuilt after committing
the release source from a clean worktree and retained the hashes above.

## Exact 2.0.25 product update

`tools/release-update-rehearsal.php` created a disposable MySQL database and an
installation from the exact `v2.0.25` tag, then imported data in deterministic
batches with denser comments and comment reactions. Before update it contained:

| Relation | Rows |
|---|---:|
| Users | 61 |
| Posts | 247 |
| Comments | 2,704 |
| Post likes | 995 |
| Comment likes | 5,432 |
| Follows | 320 |
| Notifications | 5,824 |
| Post tags | 1,200 |
| Tags | 40 |
| Content links | 31 |
| Settings | 40 |
| Migration records | 2 |

The installation also contained nested upload and storage sentinels, its own
configuration, and an installed/enabled extension requiring TinyCat 2.0.25.
Eighteen update and rollback assertions verified:

- the exact 2.0.25 application and extension booted before the update;
- the production signature, complete inventory and compatibility passed;
- 2.0.26 and the same extension booted after the update;
- configuration, uploads, storage and extension bytes were unchanged;
- representative row counts, content hash and information-schema hash were
  unchanged;
- rollback restored the exact managed 2.0.25 inventory and booted it with the
  extension and data intact;
- the disposable database and installation were removed.

## Fresh artifact and complete preflight

`tools/artifact-preflight.php` verified and extracted the signed ZIP rather than
using working-tree runtime files. It completed 363 checks: exact manifest/file
inventory, every file hash, all exclusions, PHP lint for every packaged PHP
file, 34 public-route checks, and the 14-check restart-safe fresh MySQL
installation directly from the extracted artifact.

The complete repository preflight then passed all 20 groups: Composer metadata
and advisories, deterministic style, PHP 8.4 lint, PHPStan level 8, security,
monolith boundaries, behavior, routes, query budgets, extensions, updater,
signed artifact, rollback and fresh MySQL installation. Production remains at
18 `App/` PHP files and 15 class-bearing files; hot-read query counts remain
`3/6/4/8/7` within budgets `4/7/5/17/8`.

## Decision

Stage 9 passes. The local artifact is reproducible, signed, compatible with the
released updater, and has completed both a representative exact-version update
with rollback and a fresh product installation. Publishing is deliberately not
part of this stage execution.
