"""Summarize controlled AJAX runs; failures are cumulative, requests are drained."""
import json
import math
import statistics
from pathlib import Path

root = Path(__file__).resolve().parents[2]
directory = root / "storage/performance/ajax-final"
summary = {}
for prefix in ("controlled-", "mobile-controlled-", "releasecheck-", "mobile-releasecheck-", "isolated-", "mobile-isolated-", "safe-", "mobile-safe-"):
    source = directory / (prefix + "long-run-results.json")
    if not source.exists():
        continue
    report = json.loads(source.read_text(encoding="utf-8"))
    variants = {}
    for variant in ("baseline", "final"):
        runs = [run for run in report["runs"] if run["variant"] == variant]
        times = sorted(value for run in runs for value in run["short"]["navigation_ms"])
        variants[variant] = {
            "navigation_median_ms": statistics.median(times),
            "navigation_p95_ms": times[math.ceil(len(times) * .95) - 1],
            "failures": sum(len(run["end"]["failures"]) for run in runs),
            "final_heap_bytes": [run["finalHeap"]["usedSize"] for run in runs],
            "final_live_nodes": [run["end"]["live_nodes"] for run in runs],
            "final_listeners": [run["finalDom"]["jsEventListeners"] for run in runs],
            "polling_requests": [
                {path: sum(request["at"] >= report["polling_started_at"] and request["path"] == path
                           for request in report["server_requests"][variant + ":" + str(run["round"])] )
                 for path in ("/api/notifications", "/api/scheduler/tasks", "/api/site/sidebar")}
                for run in runs
            ],
            "prune": [run["short"]["prune"] for run in runs],
        }
    summary[prefix] = {"source_sha256": report["source_sha256"], "viewport": report["viewport"],
                       "elapsed_ms": report["elapsed_ms"], "variants": variants}
(directory / "summary.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
print(json.dumps(summary, indent=2))
