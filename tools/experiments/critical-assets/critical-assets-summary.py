"""Summarize per-page samples, excluding explicitly invalid experiment variants."""
import argparse
import hashlib
import json
import statistics
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("directory", type=Path)
parser.add_argument("--exclude", action="append", default=[])
args = parser.parse_args()
report = json.loads((args.directory / "results.json").read_text(encoding="utf-8"))
fixture = json.loads((args.directory / "fixture.json").read_text(encoding="utf-8"))
for url, expected in report["source_sha256"].items():
    if hashlib.sha256(fixture["assets"][url].encode("utf-8")).hexdigest() != expected:
        raise SystemExit("Asset source mismatch: " + url)
critical_file = args.directory / "critical-measured.css"
if not critical_file.exists():
    critical_file = args.directory / "critical.css"
critical_bytes = critical_file.read_bytes()
critical_hash = hashlib.sha256(critical_bytes).hexdigest()
if len(critical_bytes) != report["critical"]["raw"] or report["critical"].get("sha256", critical_hash) != critical_hash:
    raise SystemExit("Critical CSS source mismatch.")
groups = {}
excluded = []
for run in report["runs"]:
    if run["variant"] in args.exclude:
        excluded.append({"variant": run["variant"], "profile": run["profile"], "name": run["name"],
                         "cache": run["cache"], "errors": run.get("errors"), "csp": run.get("csp")})
        continue
    if run.get("errors") or run.get("csp") or run["failures"] or not run["boot"] or not run["fcp"] or not run["lcp"]:
        raise SystemExit("Invalid measured run: " + str(run))
    key = "/".join(run[name] for name in ("profile", "name", "cache", "variant"))
    groups.setdefault(key, []).append(run)
summary = {}
for key, runs in groups.items():
    if len(runs) != report["rounds"]:
        raise SystemExit("Incomplete group: " + key)
    summary[key] = {"samples": len(runs), "max_cls": max(run["cls"] for run in runs)}
    for metric in ("fcp", "lcp", "transferred"):
        values = [run[metric] for run in runs]
        summary[key][metric] = {"median": statistics.median(values), "min": min(values), "max": max(values)}
    summary[key]["dcl_median"] = statistics.median(run["navigation"]["domContentLoadedEventEnd"] for run in runs)
output = {"critical": {**report["critical"], "sha256": critical_hash}, "excluded_runs": excluded, "groups": summary, "payloads": report["payloads"]}
(args.directory / "summary.json").write_text(json.dumps(output, indent=2) + "\n", encoding="utf-8")
print(json.dumps(output, indent=2))
