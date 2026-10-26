"use strict";
const {execFileSync} = require("node:child_process");
const {readFileSync} = require("node:fs");
const {createHash} = require("node:crypto");
const assert = require("node:assert/strict");
const path = require("node:path");
const fixture = path.resolve(__dirname, "../presentation/asset-graph-fixture.php");
const css = JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || "php", [fixture, "--styles"], {encoding:"utf8"}));
const hashes = [];
function parse(source, media = []) {
  let start = 0, depth = 0, quote = "", comment = false, open = -1;
  for (let i = 0; i < source.length; i++) {
    const c = source[i], next = source[i+1];
    if (comment) { if (c === "*" && next === "/") { comment = false; i++; } continue; }
    if (quote) { if (c === "\\") i++; else if (c === quote) quote = ""; continue; }
    if (c === "/" && next === "*") { comment = true; i++; continue; }
    if (c === '"' || c === "'") { quote = c; continue; }
    if (c === "{") { if (depth++ === 0) open = i; }
    if (c === "}" && --depth === 0) {
      const head = source.slice(start, open).replace(/\/\*[\s\S]*?\*\//g, "").trim();
      if (head.startsWith("@media")) parse(source.slice(open+1,i), [...media,head]);
      else {
        const rule = source.slice(start,i+1).replace(/\/\*[\s\S]*?\*\//g, "").replace(/\s+/g," ").trim();
        hashes.push(createHash("sha256").update(JSON.stringify([media,rule])).digest("hex"));
      }
      start = i+1;
    }
  }
  assert.equal(depth,0,"Balanced CSS blocks");
}
parse(css);
assert.deepEqual(hashes, JSON.parse(readFileSync(path.resolve(__dirname,"../fixtures/asset-style-baseline.json"),"utf8")), "Moving styles preserves every rule and cascade order");
console.log(`PASS stylesheet cascade parity (${hashes.length} rules)`);
