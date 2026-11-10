"""Compare alternating HTTP rounds and require identical normalized response hashes."""
import argparse
import json
import statistics
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("directory", type=Path)
args = parser.parse_args()
reports = {name: [json.loads(path.read_text(encoding="utf-8"))
                  for path in sorted(args.directory.glob(name + "-*.json"))]
           for name in ("baseline", "candidate")}
if any(len(rounds) != 2 for rounds in reports.values()):
    raise SystemExit("Expected two completed rounds per variant.")
summary = {}
for route in reports["baseline"][0]["scenarios"]:
    row = {}
    hashes = {}
    for variant, rounds in reports.items():
        samples = [report["scenarios"][route] for report in rounds]
        hashes[variant] = set(value for sample in samples for value in sample["body_sha256"])
        if any(not sample["valid_json"] or sample["warm"]["failures"] for sample in samples):
            raise SystemExit("Failed responses: " + route)
        row[variant] = {
            "round_p50_ms": [sample["warm"]["latency_ms"]["p50"] for sample in samples],
            "round_p95_ms": [sample["warm"]["latency_ms"]["p95"] for sample in samples],
            "median_p50_ms": statistics.median(sample["warm"]["latency_ms"]["p50"] for sample in samples),
            "median_p95_ms": statistics.median(sample["warm"]["latency_ms"]["p95"] for sample in samples),
            "median_sql_p50_ms": statistics.median(sample["sql"]["p50_ms"] for sample in samples),
            "sql_count_range": [min(sample["sql"]["count_min"] for sample in samples),
                                max(sample["sql"]["count_max"] for sample in samples)],
        }
    if hashes["baseline"] != hashes["candidate"] or len(hashes["baseline"]) != 1:
        raise SystemExit("Response mismatch: " + route)
    row["matching_body_sha256"] = next(iter(hashes["baseline"]))
    row["p50_change_percent"] = (row["candidate"]["median_p50_ms"] / row["baseline"]["median_p50_ms"] - 1) * 100
    summary[route] = row
(args.directory / "summary.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
print(json.dumps(summary, indent=2))
