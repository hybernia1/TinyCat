# Stage 7 scale validation

Stage 7 validates the 2.0.26 monolith candidate on a disposable MySQL 8.4.3
database. The machine-readable evidence is in
[`stage-7-scale-results.json`](stage-7-scale-results.json). This is candidate
scale evidence, not the baseline/candidate acceptance comparison assigned to
Stage 8.

## Dataset and bounded import

The new deterministic `million` profile generated 3,141,482 relational rows:

| Relation | Rows |
|---|---:|
| Users | 2,501 |
| Statuses | 17,485 |
| Status tags | 87,240 |
| Follows | 45,245 |
| Status likes | 175,649 |
| Comments | 419,468 |
| Comment likes | 1,259,052 |
| Notifications | 1,127,209 |
| Other measured relations | 7,633 |

The graph includes 95,139 third-level and 50,529 fourth-level comments. The
runner intentionally stopped after three committed batches, resumed from the
checkpoint, and completed 1,999 independently committable batches in 315
seconds. With PHP fixed at `memory_limit=128M`, importer peak memory was 18
MiB. Batch progress now reports current memory and peak memory together with
cursor, throughput and ETA. No import phase loads an unbounded table into PHP;
source reads use keyset cursors and insert arrays are capped by batch size.

## Application measurements

Cold means the first invocation in a fresh PHP CLI process. Warm results use
two warmups followed by ten measured invocations in that same process. Every
worker had a 128 MiB limit and a 6 MiB measured peak. These local figures are
directional; Stage 8 will perform alternating HTTP rounds against exact
2.0.25 and 2.0.26 installations.

| Scenario | Cold ms | Warm p50 ms | Warm p95 ms | Cold SQL questions | Warm questions/run |
|---|---:|---:|---:|---:|---:|
| Feed | 8.265 | 3.780 | 4.627 | 3 | 2 |
| Status detail | 7.771 | 0.937 | 1.024 | 3 | 1 |
| Tag feed | 20.094 | 4.506 | 5.733 | 4 | 3 |
| Author | 14.560 | 5.324 | 6.220 | 8 | 7 |
| Search | 30.040 | 6.416 | 8.980 | 3 | 3 |
| Status write/delete cycle | 16.515 | 22.893 | 35.393 | 26 | 26 |
| Cleanup of 500 orphan terms | 33.889 | 59.508 | 74.003 | 2 | 2 |

The write scenario creates a status, synchronizes two tags, creates a comment
and removes the status through the production cleanup owner. The maintenance
scenario pre-seeds orphan rows outside the timed region and measures one
bounded 500-row cleanup task. All scenarios completed without a warning,
fatal error or memory-limit failure.

## Query-plan and index decision

The local MySQL slow log was inspected read-only. It was disabled, with a
10-second threshold and `FILE` output, so it contained no usable statement
sample. The harness did not mutate global database configuration; it captured
`EXPLAIN ANALYZE` for every hot SQL shape instead.

| Shape | Observed plan and actual time |
|---|---|
| Feed | Reverse `content_feed_index` scan, 24 rows; 0.553 ms. |
| Status comments | `content_comments_content_index`, 28 comments, primary user lookups and covering comment-like lookups; 3.21 ms. |
| Tag feed | `content_tags_term_index`, 2,317 matching relations and bounded top-N sort; 28.3 ms. |
| Author feed | Reverse covering `content_author_index`, eight rows; 0.023 ms. |
| Search | `content_body_fulltext`, 4,739 matches followed by a bounded top-N sort; 40.3 ms. |

No production SQL or index was changed in Stage 7. The existing plans use the
intended indexes, application latency stays bounded, and another composite
index would add write and storage cost without removing the FULLTEXT or tag
result-set sort. The evidence therefore does not justify a schema change.

## Reproduction

Run on a local MySQL installation configured by `config.php`:

```bash
php -d memory_limit=128M tools/scale-benchmark.php \
  --output=docs/stage-7-scale-results.json
```

The runner creates a database named only inside the validated
`tinycat_scale_test_*` namespace, installs the current schema, verifies
pause/resume, imports the graph, runs each application scenario in an isolated
process, captures plans, writes the report, and removes the database and its
temporary configuration.
