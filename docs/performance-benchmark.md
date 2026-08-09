# Comparative performance benchmark

TinyCat includes a deterministic demo-data importer and an HTTP comparison
runner for testing two installed releases against the same relational graph.
Both utilities are CLI-only, live below `tools/`, and are excluded from release
packages.

## Demo dataset

The importer writes directly through PDO so it does not depend on either
release's application facades. A seed and fixed time anchor make IDs, bodies,
timestamps, tags and relations reproducible. It creates users, posts, tags,
profile links, follows, post likes, nested comments, comment likes,
notifications and moderation reports.

```bash
php tools/demo-import.php \
  --config=/path/to/tinycat/config.php \
  --profile=medium \
  --batch-size=500 \
  --reset=1
```

The `small`, `medium`, `large` and `million` profiles are starting points and
every size can be overridden. The medium profile currently produces 3,000
generated users, 4-8 posts per user, 6-14 comments per post, 8-24 follows per
user and multiple reactions per post and comment.

The `million` profile is the bounded release-scale fixture. It produces 2,500
generated users, 6-8 posts per user, 20-28 comments per post across four tree
levels, 12-24 follows per user and 2-4 likes per comment. The importer rejects
a completed `million` run that contains fewer than one million relational
rows.

Each source batch is its own transaction. After it commits, the importer
writes an atomic checkpoint and reports the phase, cursor, row rate, memory
use, recorded peak and ETA.
An interrupted import resumes without repeating committed work:

```bash
php tools/demo-import.php \
  --config=/path/to/tinycat/config.php \
  --profile=medium \
  --batch-size=500 \
  --reset=0 \
  --resume=1
```

Use `--jsonl=1` for machine-readable progress and `--max-batches=N` to test
pause/resume behavior deliberately. `tools/seed.php` remains a compatibility
entry point for the former seeder name.

Only use `--reset=1` against a disposable benchmark installation. It clears
TinyCat content and account tables while preserving the installed schema.

For a disposable MySQL scale rehearsal with a fixed memory ceiling, isolated
cold/warm application workers, write and maintenance samples, slow-log setting
inspection and `EXPLAIN ANALYZE`, run:

```bash
php -d memory_limit=128M tools/scale-benchmark.php \
  --output=docs/stage-7-scale-results.json
```

The Stage 7 results and index decision are documented in
[`stage-7-scale-validation.md`](stage-7-scale-validation.md).

## HTTP comparison

Prepare two locally reachable installations, import the same profile, seed,
anchor and batch size into both, then run:

```bash
php tools/compare-performance.php \
  --baseline-root=/path/to/tinycat-2.0.25 \
  --baseline-url=http://127.0.0.1:8098 \
  --candidate-root=/path/to/tinycat-2.5.0 \
  --candidate-url=http://127.0.0.1:8097 \
  --sequential-requests=40 \
  --load-requests=120 \
  --concurrency=8 \
  --warmup-requests=8 \
  --output=storage/performance/comparison.json
```

Before sending load, the runner verifies table counts and representative IDs
are identical. It measures the public feed, a comment-heavy status, a tag feed,
an active author and search under both filesystem and Memcached cache modes.
Every route gets an independent cold cache, warm-up, sequential sample and
concurrent sample.

The JSON and Markdown reports contain cold latency, p50/p95/p99 latency, time
to first byte, throughput, response size, status and fatal-error checks,
approximate MySQL questions per request, temporary-table counters, Memcached
hits/misses and the post-load FastCGI working set. The runner uses a unique
Memcached prefix per scenario and restores both original `config.php` files in
a `finally` block.

Run the benchmark on an otherwise idle host with production-equivalent PHP
settings. In particular, enable OPCache: class-oriented code is unfairly
penalized when every source file must be parsed for every request. Keep PHP,
web-server, database and cache-server versions constant, and treat a single
local run as directional rather than a universal capacity claim.

The measurements led to keeping 2.5 as an experiment and backporting only its
proven improvements into the released monolithic line. That work is tracked in
the [2.0.26 monolith backport plan](release-2.0.26-monolith-plan.md).
