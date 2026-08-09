# TinyCat 2.0.25 vs 2.5.0 performance

Generated: 2026-08-09T10:06:48+00:00

| Cache | Route | Version | Cold ms | Seq p50/p95 ms | Load p95 ms | Load req/s | SQL q/request | Failures |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| filesystem | feed | 2.0.25 | 74.68 | 71.98 / 83.62 | 332.24 | 23.60 | 24.00 | 0 |
| filesystem | feed | 2.5.0 | 140.10 | 62.65 / 93.91 | 361.63 | 23.21 | 4.00 | 0 |
| filesystem | status_thread | 2.0.25 | 50.97 | 32.21 / 65.35 | 155.44 | 53.08 | 8.00 | 0 |
| filesystem | status_thread | 2.5.0 | 137.53 | 47.05 / 120.89 | 184.85 | 44.98 | 7.00 | 0 |
| filesystem | tag_feed | 2.0.25 | 212.83 | 76.44 / 161.78 | 399.03 | 19.72 | 25.00 | 0 |
| filesystem | tag_feed | 2.5.0 | 163.13 | 65.15 / 140.28 | 416.54 | 20.74 | 5.00 | 0 |
| filesystem | author_profile | 2.0.25 | 211.57 | 60.79 / 138.31 | 253.45 | 31.87 | 25.00 | 0 |
| filesystem | author_profile | 2.5.0 | 238.92 | 63.23 / 184.50 | 288.53 | 29.77 | 17.00 | 0 |
| filesystem | search | 2.0.25 | 205.29 | 66.01 / 175.65 | 300.58 | 25.38 | 20.00 | 0 |
| filesystem | search | 2.5.0 | 315.40 | 63.61 / 96.87 | 340.81 | 24.83 | 8.00 | 0 |
| memcached | feed | 2.0.25 | 208.84 | 62.88 / 107.98 | 216.41 | 39.29 | 24.00 | 0 |
| memcached | feed | 2.5.0 | 74.70 | 62.03 / 76.09 | 167.49 | 47.43 | 4.00 | 0 |
| memcached | status_thread | 2.0.25 | 28.25 | 30.81 / 34.04 | 51.17 | 114.54 | 8.00 | 0 |
| memcached | status_thread | 2.5.0 | 72.87 | 31.79 / 45.48 | 69.44 | 99.25 | 7.00 | 0 |
| memcached | tag_feed | 2.0.25 | 82.64 | 65.81 / 80.21 | 233.15 | 37.06 | 25.00 | 0 |
| memcached | tag_feed | 2.5.0 | 69.98 | 48.97 / 64.82 | 182.06 | 47.84 | 5.00 | 0 |
| memcached | author_profile | 2.0.25 | 50.21 | 31.58 / 46.18 | 118.93 | 73.32 | 25.00 | 0 |
| memcached | author_profile | 2.5.0 | 56.28 | 32.49 / 48.11 | 121.14 | 68.24 | 17.00 | 0 |
| memcached | search | 2.0.25 | 77.17 | 49.32 / 63.46 | 180.77 | 46.23 | 20.00 | 0 |
| memcached | search | 2.5.0 | 81.86 | 47.87 / 62.65 | 185.82 | 45.66 | 8.00 | 0 |

Negative latency change favors 2.5.0; positive throughput change favors 2.5.0.

## Load comparison

| Cache | Route | 2.5 p95 change | 2.5 throughput change |
|---|---|---:|---:|
| filesystem | feed | +8.85% | -1.67% |
| filesystem | status_thread | +18.93% | -15.25% |
| filesystem | tag_feed | +4.39% | +5.18% |
| filesystem | author_profile | +13.84% | -6.58% |
| filesystem | search | +13.38% | -2.17% |
| memcached | feed | -22.61% | +20.71% |
| memcached | status_thread | +35.71% | -13.35% |
| memcached | tag_feed | -21.91% | +29.11% |
| memcached | author_profile | +1.86% | -6.93% |
| memcached | search | +2.79% | -1.23% |

## Memcached impact

| Version | Route | p95 change vs filesystem | Throughput change |
|---:|---|---:|---:|
| 2.0.25 | feed | -34.86% | +66.48% |
| 2.0.25 | status_thread | -67.08% | +115.80% |
| 2.0.25 | tag_feed | -41.57% | +87.92% |
| 2.0.25 | author_profile | -53.07% | +130.05% |
| 2.0.25 | search | -39.86% | +82.14% |
| 2.5.0 | feed | -53.69% | +104.39% |
| 2.5.0 | status_thread | -62.44% | +120.65% |
| 2.5.0 | tag_feed | -56.29% | +130.67% |
| 2.5.0 | author_profile | -58.01% | +129.19% |
| 2.5.0 | search | -45.48% | +83.88% |
