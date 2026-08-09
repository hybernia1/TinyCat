# Stage 8 comparative acceptance

Stage 8 compares exact tag `v2.0.25` (`d80c09e`) with the 2.0.26 candidate at
`3645c26`. The machine-readable aggregate and every gate decision are in
[`stage-8-acceptance-results.json`](stage-8-acceptance-results.json).

## Method

Both Apache/FastCGI installations used MySQL 8.4.3 and byte-identical copies
of the deterministic Stage 7 graph: 3,141,482 relational rows, including
419,468 comments and 1,259,052 comment reactions. Dataset parity was checked
before every round.

Five rounds were measured with OPCache enabled and five with it disabled.
Within each mode the first measured version alternated on every round. Every
round tested filesystem cache and Memcached across feed, status detail, tag,
author and search. Each route received an independently cold cache, five
single-request warmups, 20 warmed sequential measurements, a concurrency
warmup and 60 requests at concurrency 8. The accepted set therefore contains
16,200 measured HTTP responses plus warmups.

A development-only prepend probe reported actual web-runtime OPCache state,
per-request PHP peak memory, included files, loaded classes and user CPU time.
MySQL global counters, Memcached counters and the aggregate FastCGI worker
working set were sampled around load. The original `php.ini` was backed up and
restored after every run.

One first attempt at the fifth OPCache-disabled round aborted on a baseline
transport result. Apache's access log contains only HTTP 200 responses for the
attempt and neither vhost logged a PHP warning or fatal. The complete round
was rerun after a clean Apache restart and passed. The aborted attempt is not
mixed into the median dataset.

## Production OPCache results

The table shows the median of five round-level p95 measurements. Negative
change favors the candidate.

| Cache | Route | 2.0.25 seq p95 ms | 2.0.26 seq p95 ms | Seq change | Load change | Candidate SQL q/request |
|---|---|---:|---:|---:|---:|---:|
| filesystem | Feed | 85.856 | 67.907 | -20.91% | -10.66% | 4 |
| filesystem | Status | 49.806 | 46.579 | -6.48% | -19.25% | 4 |
| filesystem | Tag | 93.917 | 70.166 | -25.29% | -3.94% | 5 |
| filesystem | Author | 61.629 | 59.993 | -2.65% | -8.70% | 9 |
| filesystem | Search | 79.442 | 74.719 | -5.95% | -2.31% | 8 |
| Memcached | Feed | 66.634 | 59.499 | -10.71% | -21.06% | 4 |
| Memcached | Status | 50.201 | 45.775 | -8.82% | -5.90% | 4 |
| Memcached | Tag | 81.018 | 66.139 | -18.37% | -21.77% | 5 |
| Memcached | Author | 66.799 | 54.522 | -18.38% | -22.85% | 9 |
| Memcached | Search | 78.478 | 67.335 | -14.20% | -18.45% | 8 |

The geometric sequential/load p95 changes are `-12.74%/-9.17%` for filesystem
and `-14.18%/-18.23%` for Memcached. Every production route is below the
allowed +5% p95 limit and neither geometric aggregate regresses.

## Resource and sensitivity results

- Both versions report a 2 MiB per-request allocator peak on the measured
  routes. Candidate change is 0%.
- Both load 36 PHP files and 270 classes on the representative feed request.
  All route-level included-file and loaded-class changes are 0%.
- The largest candidate FastCGI working-set change is +4.26%, below the +5%
  release limit; all other measured route changes are at most +0.31%.
- Candidate warmed SQL questions are `4/4/5/9/8` against budgets
  `4/7/5/17/8` for feed/status/tag/author/search under both cache drivers and
  both OPCache modes.
- With OPCache disabled, geometric sequential p95 is 9.66% faster on
  filesystem and 14.32% faster on Memcached. Concurrent load is 2.69% slower
  in aggregate on filesystem and 8.28% faster on Memcached. Every sequential
  route remains inside +5%, and identical file/class counts rule out the large
  source-loading penalty observed in experimental 2.5.

## Release-gate decision

| Gate | Result | Evidence |
|---|---|---|
| Zero errors and identical data | PASS | No 5xx/fatal/warning in ten completed rounds; table counts match. |
| Production route and aggregate p95 | PASS | Every route is faster; geometric load p95 is -9.17%/-18.23%. |
| PHP and FastCGI memory | PASS | PHP 0% change; maximum FastCGI change +4.26%. |
| Query budgets | PASS | `4/4/5/9/8`, all at or below their fixed budgets. |
| OPCache-disabled sensitivity | PASS | Sequential aggregates improve; filesystem load aggregate +2.69%; source counts unchanged. |

The 2.0.26 candidate is accepted for Stage 9 packaging. This decision applies
to commit `3645c26`; later production changes require repeating the relevant
acceptance evidence.
