# TinyCat 2.0.25 vs 2.5.0 performance

Generated: 2026-08-09T10:00:35+00:00

| Cache | Route | Version | Cold ms | Seq p50/p95 ms | Load p95 ms | Load req/s | SQL q/request | Failures |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| filesystem | feed | 2.0.25 | 159.98 | 107.78 / 161.36 | 624.81 | 13.05 | 24.00 | 0 |
| filesystem | feed | 2.5.0 | 182.97 | 128.50 / 144.38 | 730.73 | 10.74 | 4.00 | 0 |
| filesystem | status_thread | 2.0.25 | 80.28 | 63.21 / 92.06 | 301.41 | 26.86 | 8.00 | 0 |
| filesystem | status_thread | 2.5.0 | 402.43 | 103.43 / 127.92 | 563.79 | 14.66 | 7.00 | 0 |
| filesystem | tag_feed | 2.0.25 | 604.66 | 153.08 / 175.75 | 698.76 | 11.64 | 25.00 | 0 |
| filesystem | tag_feed | 2.5.0 | 657.93 | 175.89 / 221.07 | 816.11 | 9.56 | 5.00 | 0 |
| filesystem | author_profile | 2.0.25 | 364.44 | 114.66 / 152.25 | 467.12 | 17.06 | 25.00 | 0 |
| filesystem | author_profile | 2.5.0 | 490.38 | 155.86 / 187.16 | 662.17 | 12.10 | 17.00 | 0 |
| filesystem | search | 2.0.25 | 351.03 | 132.70 / 166.66 | 578.95 | 13.65 | 20.00 | 0 |
| filesystem | search | 2.5.0 | 478.13 | 186.27 / 321.23 | 711.00 | 11.08 | 8.00 | 0 |
| memcached | feed | 2.0.25 | 317.17 | 127.94 / 162.85 | 393.67 | 19.93 | 24.00 | 0 |
| memcached | feed | 2.5.0 | 148.76 | 117.96 / 158.56 | 474.36 | 16.30 | 4.00 | 0 |
| memcached | status_thread | 2.0.25 | 60.72 | 62.77 / 74.31 | 198.77 | 47.06 | 8.00 | 0 |
| memcached | status_thread | 2.5.0 | 102.31 | 94.13 / 96.60 | 322.81 | 27.58 | 7.00 | 0 |
| memcached | tag_feed | 2.0.25 | 129.21 | 110.16 / 120.98 | 372.51 | 22.60 | 25.00 | 0 |
| memcached | tag_feed | 2.5.0 | 138.01 | 108.61 / 126.61 | 448.60 | 18.17 | 5.00 | 0 |
| memcached | author_profile | 2.0.25 | 103.27 | 79.01 / 93.81 | 274.00 | 30.27 | 25.00 | 0 |
| memcached | author_profile | 2.5.0 | 98.58 | 92.28 / 108.20 | 370.93 | 23.18 | 17.00 | 0 |
| memcached | search | 2.0.25 | 141.85 | 95.66 / 114.60 | 370.07 | 22.22 | 20.00 | 0 |
| memcached | search | 2.5.0 | 204.41 | 122.78 / 150.35 | 426.72 | 19.47 | 8.00 | 0 |

Negative latency change favors 2.5.0; positive throughput change favors 2.5.0.
