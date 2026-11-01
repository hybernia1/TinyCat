"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), os = require("node:os"), path = require("node:path");
const { execFileSync } = require("node:child_process"), { pathToFileURL } = require("node:url");
const browser = [process.env.TINYCAT_TEST_CHROME, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser", "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"].filter(Boolean).find(file => fs.existsSync(file));
if (!browser) { console.log("SKIP native YouTube thumbnails: browser unavailable"); process.exit(0); }
const root = path.resolve(__dirname, "../..");
const mobile = "mobile.svg", desktop = "desktop.svg";
const data = { link: { url: "https://www.youtube.com/watch?v=abc123", title: "Video", embed_allowed: true, thumbnail_url: desktop, thumbnail_mobile_url: mobile } };
const html = execFileSync(process.env.TINYCAT_TEST_PHP || "php", ["-r", 'define("TINYCAT", true); require "App/autoload.php"; echo TinyCat\\Support\\Components::render("status/link", json_decode($argv[1], true));', JSON.stringify(data)], { cwd: root, encoding: "utf8" }).replace(/<svg\b[\s\S]*?<\/svg>/g, "").replace('loading="lazy"', 'loading="eager"');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), "tinycat-youtube-thumbnails-"));
try {
  fs.writeFileSync(path.join(temporary, mobile), '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90"><rect width="120" height="90" fill="red"/></svg>');
  fs.writeFileSync(path.join(temporary, desktop), '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="blue"/></svg>');
  const target = path.join(temporary, "fixture.html");
  fs.writeFileSync(target, `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>${html}<pre id="results"></pre><script>
    const image=document.querySelector('picture img');
    function result(){document.querySelector('#results').textContent=JSON.stringify({width:innerWidth,dpr:devicePixelRatio,src:image.currentSrc});}
    image.onload=result; image.onerror=result; if(image.complete)result(); setTimeout(result,1000);
  </script></body></html>`);
  for (const width of [390,750,800,1280]) {
    const output = execFileSync(browser, ["--headless=new", "--disable-gpu", "--no-first-run", "--disable-background-networking", "--allow-file-access-from-files", "--force-device-scale-factor=3", "--window-size=" + width + ",700", "--virtual-time-budget=2000", "--user-data-dir=" + path.join(temporary, "profile-" + width), "--dump-dom", pathToFileURL(target).href], { encoding: "utf8", timeout: 30000, maxBuffer: 2e6, stdio: ["ignore", "pipe", "pipe"] });
    const match = output.match(/<pre id="results">([\s\S]*?)<\/pre>/);
    assert.ok(match && match[1], "Native image selection finished");
    const result = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, "&"));
    assert.equal(result.width <= 759, width <= 759); assert.ok(result.dpr >= 3);
    assert.equal(result.src, pathToFileURL(path.join(temporary, width <= 759 ? mobile : desktop)).href, "Only the requested resolution loads at " + width);
  }
  console.log("PASS native YouTube thumbnail selection at four widths and DPR 3");
} finally {
  const resolved = fs.realpathSync(temporary);
  assert.equal(path.dirname(resolved), fs.realpathSync(os.tmpdir()));
  assert.ok(path.basename(resolved).startsWith("tinycat-youtube-thumbnails-"));
  fs.rmSync(resolved, { recursive: true, maxRetries: 5, retryDelay: 100 });
}
