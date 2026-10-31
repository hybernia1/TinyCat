"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { pathToFileURL } = require("node:url");
const { execFileSync } = require("node:child_process");

const candidates = [process.env.TINYCAT_TEST_CHROME,
  "C:/Program Files/Google/Chrome/Application/chrome.exe",
  "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
  "/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser",
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"].filter(Boolean);
const browser = candidates.find(file => fs.existsSync(file));
if (!browser) {
  console.log("SKIP rendered admin viewport: set TINYCAT_TEST_CHROME to a Chromium executable");
  process.exit(0);
}

const temporary = fs.mkdtempSync(path.join(os.tmpdir(), "tinycat-admin-viewport-"));
try {
  const sources = ['', '--modal', '--accounts'].map(mode => execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.resolve(__dirname, "../presentation/admin-viewport-fixture.php"), ...(mode ? [mode] : [])], { encoding: "utf8", maxBuffer: 8e6 }));
  const source = JSON.stringify(sources).replace(/<\/script/gi, "<\\/script");
  const page = `<!doctype html><html><body><script>
    (async function () {
      const sources = ${source}, widths = [320,375,390,599,600,760,1024], all = [];
      for (const source of sources) for (const height of [360,480,812]) for (const width of widths) {
        const frame = document.createElement('iframe');
        frame.style.cssText = 'position:fixed;top:0;left:0;border:0;width:'+width+'px;height:'+height+'px';
        await new Promise(resolve => {
          frame.onload = () => {
            const timer = setInterval(() => {
              const metrics = frame.contentDocument.querySelector('#layout-metrics');
              if (!metrics) return;
              clearInterval(timer);
              all.push(...JSON.parse(metrics.textContent));
              resolve();
            }, 10);
          };
          frame.srcdoc = source; document.body.append(frame);
        });
        frame.remove();
      }
      const output = document.createElement('pre'); output.id = 'all-metrics';
      output.textContent = JSON.stringify(all); document.body.append(output);
    }());
  </script></body></html>`;
  const target = path.join(temporary, "fixture.html");
  fs.writeFileSync(target, page);
  const result = execFileSync(browser, ["--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check", "--disable-background-networking", "--disable-sync", "--hide-scrollbars", "--virtual-time-budget=40000", "--user-data-dir=" + path.join(temporary, "profile"), "--dump-dom", pathToFileURL(target).href], { encoding: "utf8", timeout: 60000, maxBuffer: 16e6, stdio: ["ignore", "pipe", "pipe"] });
  const match = result.match(/<pre id="all-metrics">([\s\S]*?)<\/pre>/);
  assert.ok(match, "The browser must render every viewport fixture");
  const cases = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&"));
  assert.equal(cases.length, 252);
  assert.deepEqual([...new Set(cases.map(item => item.width))].sort((a,b) => a-b), [320,375,390,599,600,760,1024]);
  for (const item of cases) {
    const label = JSON.stringify(item);
    assert.ok(item.documentWidth <= item.width && item.bodyWidth <= item.width, "Page overflow: " + label);
    assert.ok(!item.open || (item.filterLeft >= 0 && item.filterRight <= item.width), "Filter outside viewport: " + label);
    assert.ok(!item.open || item.filterWidth <= 300, "Filter width must stay capped: " + label);
    assert.ok(!item.open || (item.filterTop >= 0 && item.filterBottom <= item.filterLimit), "Filter outside visible scroll area: " + label);
    assert.ok(!item.open || item.filterScrollWidth <= item.filterClientWidth, "Filter contents must not create horizontal scrolling: " + label);
    assert.ok(["auto", "scroll"].includes(item.tableOverflow), "Table overflow stays local: " + label);
    if (item.tableScrollWidth > item.tableWidth) {
      assert.ok(item.tableScrollLeft > 0, "Wide tables must remain scrollable: " + label);
    }
    assert.ok(item.identityAligned, "Details align with the primary name and keep their secondary typography: " + label);
    assert.ok(item.textLimited && item.fullTextKept, "Long text stays bounded while preserving full labels and link destinations: " + label);
    assert.ok(item.tooltipFits && item.tooltipClosed, "Keyboard tooltips fit the viewport and Escape preserves existing descriptions: " + label);
    assert.ok(item.touchSafe && item.outsideCloses && item.hoverWorks && item.frameTitleKept && item.disposeCloses && item.shortHidden && item.lazyHintsReady, "Tooltip hover, touch, safe text, lazy panels and frame semantics remain usable: " + label);
    assert.ok(item.actionsAligned, "Action cells stay right aligned with controls on one line: " + label);
    assert.ok(item.navigationFits && item.navigationChanges, "The compact section switch fits below the header and activates the same tab panels: " + label);
    assert.ok(item.sortingWorks, "Sorting stays available through the original header links: " + label);
    assert.ok(item.chipsWork, "Removing one filter preserves other filters, sorting and page size, resets the page and retains keyboard focus: " + label);
    assert.ok(item.summaryClean, "Filter summaries contain badges only and collapse when empty: " + label);
    assert.ok(item.tableLayout && item.sortAvailable && item.fallbackUsable && item.complexSpansKept && item.emptyKept, "Native tables keep headers and sorting at every viewport, with local scrolling, complex spans and empty states: " + label);
  }
  for (const context of ['page', 'modal', 'accounts']) {
    assert.ok(cases.some(item => item.context === context && item.width < 600), "Exercise mobile tables in " + context);
    assert.ok(cases.some(item => item.context === context && item.width >= 600), "Exercise desktop tables in " + context);
  }
  console.log("PASS responsive admin tables, native sorting, filters and tooltips in pages and modals (252 cases)");
} finally {
  const resolved = fs.realpathSync(temporary), parent = fs.realpathSync(os.tmpdir());
  assert.equal(path.dirname(resolved), parent, "Cleanup must stay in the temporary directory");
  assert.ok(path.basename(resolved).startsWith("tinycat-admin-viewport-"));
  fs.rmSync(resolved, { recursive: true, maxRetries: 5, retryDelay: 100 });
}
