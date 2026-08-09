# TinyCat 2.0.25 release baseline

This record closes Stage 0 of the
[2.0.26 monolith backport plan](release-2.0.26-monolith-plan.md). It identifies
the exact released source and the local measurements against which every
2.0.26 stage is evaluated.

## Source baseline

- Git tag: `v2.0.25`
- Commit: `c81288bf3d7b47cdb6cabebefc90690191a448a9`
- Release branch: `codex/release-2.0.26`, created directly from the tag
- 2.5 experiment archive: `codex/archive-2.5-experiment` at `f0fe5aa`
- Production PHP files below `App/`: 18
- Class/interface-bearing files below `App/`: 15
- `Core.php`: 2,011 lines
- `functions.php`: 6,399 lines
- Combined `PackageManager.php`: 1,836 lines

No production source from 2.5 was copied to the release branch. Only the
standalone importer, comparison runner, historical reports and planning
documentation were transferred.

## Local environment

- Windows 11, AMD64
- PHP 8.4.12, FastCGI for HTTP runs, 512 MB CLI memory limit
- MySQL 8.4.3
- curl 8.12.1
- Memcached PHP extension 3.4.0
- Production-equivalent comparison run with OPCache configured and enabled
- Separate sensitivity run with OPCache disabled

The local CLI currently has OPCache disabled. This does not alter the archived
HTTP report, which records the FastCGI configuration used by its run.

## Deterministic medium dataset

| Relation | Rows |
|---|---:|
| Users | 3,001 |
| Posts | 18,009 |
| Tags | 40 |
| Post/tag relations | 89,851 |
| Post likes | 206,968 |
| Comments | 179,848 |
| Comment likes | 361,300 |
| Follows | 48,121 |
| Notifications | 449,416 |
| Reports | 549 |

Both measured installations had identical counts and representative IDs. The
load profile used 40 sequential requests and 120 concurrent-load requests at
concurrency 8 after 8 warm-up requests, independently for filesystem and
Memcached cache modes.

## Verification performed on the exact tag

- All 124 shipped PHP files passed PHP 8.4 syntax validation.
- The four shipped cache-facade tests passed.
- The released tag has no general `tests/run.php`, Composer definition or
  preflight runner. Adding a behavior-oriented development harness is therefore
  Stage 1 work, not part of the untouched baseline.
- The archived HTTP comparison reported zero failures and zero fatal responses
  across the five public routes and both cache drivers.

## Archived measurements

- [Production-equivalent OPCache report](../storage/performance/comparison-2.0.25-vs-2.5.0.md)
- [Production-equivalent raw data](../storage/performance/comparison-2.0.25-vs-2.5.0.json)
- [OPCache-disabled sensitivity report](../storage/performance/comparison-2.0.25-vs-2.5.0.no-opcache.md)
- [OPCache-disabled raw data](../storage/performance/comparison-2.0.25-vs-2.5.0.no-opcache.json)

These reports are historical evidence for selecting backports. The final
release gate will run the same matrix against exact 2.0.25 and the completed
2.0.26 candidate over at least three alternating rounds.
