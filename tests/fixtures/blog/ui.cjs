"use strict";
// Uses the existing Blog fixture server and its test-only bearer identity.
const fs = require("node:fs"), path = require("node:path"), assert = require("node:assert/strict");
const root = path.resolve(__dirname, "../../.."), modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root, "storage/ui-webkit-tools/node_modules/playwright");
if (!fs.existsSync(modulePath)) { console.log("SKIP Blog browser layout: Playwright unavailable"); process.exit(0); }
const { chromium, webkit } = require(modulePath);
const executablePath = [process.env.TINYCAT_TEST_CHROME, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"].filter(Boolean).find(file => fs.existsSync(file));
(async () => {
  const browser = await (executablePath ? chromium : webkit).launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
  try {
    const context = await browser.newContext({ extraHTTPHeaders: { Authorization: "Bearer admin" } });
    const page = await context.newPage(), errors = [];
    page.on("pageerror", error => { errors.push(error.message); console.error('Blog browser error:', error.message); });
    const base = process.argv[2], article = process.argv[3], output = process.env.TINYCAT_UI_SCREENSHOT_DIR;
    if (output) { fs.mkdirSync(output, { recursive: true }); }
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      for (const [name, url] of [["modules", "/admin/modules"], ["sources", "/admin/article-sources"], ["articles", "/admin/articles"], ["editor", "/admin/articles/" + article], ["blog", "/blog"], ["article", "/articles/" + article]]) {
        const response = await page.goto(base + url, { waitUntil: "networkidle" });
        assert.equal(response.status(), 200, url);
        assert.equal(await page.locator("h1, .admin-topbar-title").count(), 1, "Exactly one page title: " + url);
        assert.equal(await page.evaluate(() => getComputedStyle(document.body).marginTop), "0px", "Shared styles are delivered: " + url);
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), "No document overflow: " + width + " " + url);
        if (name === 'articles') {
          assert(await page.locator('#blog-articles .table-actions .btn-icon').evaluateAll(buttons => buttons.length > 0 && buttons.every(button => button.getBoundingClientRect().width <= 48)), 'Row actions retain the shared compact icon dimensions');
        }
        if (output) { await page.screenshot({ path: path.join(output, name + "-" + width + ".png"), fullPage: false }); }
      }
    }
    await page.goto(base + '/admin/article-sources', { waitUntil: 'networkidle' });
    assert.equal(await page.locator('.modal').count(), 0, 'Source hydration creates no hidden editor');
    const sourceDocument = await page.evaluate(() => window.performance.timeOrigin);
    await page.locator('[data-modal-url="/api/article-sources/creation"]').click();
    const sourceEditor = page.locator('.modal[data-modal-kind="editor"]');
    await sourceEditor.waitFor({ state: 'visible' });
    await sourceEditor.locator('[name="name"]').fill('Browser source');
    await sourceEditor.locator('[name="feed_url"]').fill('https://93.184.216.34/browser-feed.xml');
    assert.equal(await sourceEditor.locator('[name="publication_status"]').inputValue(), 'hidden');
    const csrfToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    await page.route('**/api/article-sources/preview', async route => {
      const response = await context.request.post(base + '/api/article-sources/preview', { headers: { 'X-CSRF-Token': csrfToken }, data: { document: '<rss><channel><item><guid>browser-preview</guid><title>Browser preview article</title><description>Preview body</description></item></channel></rss>' } });
      await route.fulfill({ response });
    });
    await sourceEditor.locator('[data-article-source-preview]').click();
    await sourceEditor.getByText('Browser preview article', { exact: true }).waitFor();
    if (output) { await page.screenshot({ path: path.join(output, 'source-editor-390.png'), fullPage: false }); }
    assert.equal(await sourceEditor.locator('[name="name"]').inputValue(), 'Browser source', 'Preview preserves the source form draft');
    await page.unroute('**/api/article-sources/preview');
    const sourceCreation = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/article-sources');
    await sourceEditor.locator('.modal-footer button[type="submit"]').click();
    const sourceResponse = await sourceCreation;
    assert.equal(sourceResponse.status(), 201);
    const sourceId = (await sourceResponse.json()).data.item.id;
    await sourceEditor.waitFor({ state: 'detached' });
    await page.locator('[data-modal-url="/api/article-sources/' + sourceId + '/management"]').click();
    await sourceEditor.waitFor({ state: 'visible' });
    assert.equal(await sourceEditor.locator('[name="feed_url"]').getAttribute('readonly'), '');
    await sourceEditor.locator('[name="name"]').fill('Preserved source draft');
    await page.evaluate(() => TinyCat.lists.refresh(document.querySelector('#blog-sources')));
    assert.equal(await sourceEditor.locator('[name="name"]').inputValue(), 'Preserved source draft', 'Source refresh preserves the resource draft');
    const sourceSave = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/article-sources/' + sourceId);
    await sourceEditor.locator('.modal-footer button[type="submit"]').click();
    assert.equal((await sourceSave).status(), 200);
    await sourceEditor.waitFor({ state: 'detached' });
    await page.locator('#blog-sources .table-primary').filter({ hasText: 'Preserved source draft' }).waitFor();
    const sourceDeletion = page.locator('form[action="/api/article-sources/' + sourceId + '"]');
    await sourceDeletion.waitFor();
    await sourceDeletion.locator('button').click();
    const sourceConfirm = page.locator('.modal[data-modal-kind="confirm"]');
    await sourceConfirm.waitFor({ state: 'visible' });
    const sourceDeleted = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/article-sources/' + sourceId);
    await sourceConfirm.locator('button.btn-danger').click();
    assert.equal((await sourceDeleted).status(), 200);
    await sourceDeletion.waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => window.performance.timeOrigin), sourceDocument, 'Source CRUD retains the list document');
    await page.goto(base + '/articles/' + article, { waitUntil: 'networkidle' });
    const thread = page.locator('[data-discussion]'), commentForm = thread.locator('form.status-comment-form:not(.is-reply)');
    assert.equal(await page.locator('.modal').count(), 0, 'Discussion hydration never creates hidden editors');
    await commentForm.locator('[data-status-editor-input]').fill('Browser shared comment');
    let mutation = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/articles/' + article + '/comments');
    await commentForm.locator('button[type="submit"]').click();
    assert.equal((await mutation).status(), 201);
    const comment = thread.locator('.status-comment').filter({ hasText: 'Browser shared comment' }).first();
    await comment.waitFor();
    assert.equal(await commentForm.locator('[data-status-editor-input]').innerText(), '', 'Successful comment clears only the submitted editor');
    await commentForm.locator('[data-status-editor-input]').fill('Preserved discussion draft');
    const commentId = await comment.getAttribute('data-comment-id');
    mutation = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/comments/' + commentId + '/likes/me'));
    await comment.locator('[data-comment-like-button]').first().click();
    assert.equal((await mutation).status(), 200);
    await page.waitForFunction(id => document.querySelector('[data-comment-like-button][data-comment-id="' + id + '"]').getAttribute('aria-pressed') === 'true', commentId);
    assert.equal(await commentForm.locator('[data-status-editor-input]').innerText(), 'Preserved discussion draft', 'Reaction refresh preserves an unrelated draft');
    await comment.locator('[data-modal-url]').click();
    const discussionEditor = page.locator('.modal[data-modal-kind="editor"]');
    await discussionEditor.waitFor({ state: 'visible' });
    await discussionEditor.locator('[data-status-editor-input]').fill('Browser edited discussion');
    mutation = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/comments/' + commentId));
    await discussionEditor.locator('form[data-api-method="PATCH"] button[type="submit"], .modal-footer button[type="submit"]').first().click();
    assert.equal((await mutation).status(), 200);
    await discussionEditor.waitFor({ state: 'detached' });
    await page.waitForFunction(id => document.querySelector('.status-comment[data-comment-id="' + id + '"] .status-comment-body').textContent.includes('Browser edited discussion'), commentId);
    assert.equal(await commentForm.locator('[data-status-editor-input]').innerText(), 'Preserved discussion draft', 'Editing a comment preserves the publication draft');
    await commentForm.locator('[data-status-editor-input]').fill('');
    await page.evaluate(() => TinyCat.forms.markClean(document.querySelector('form.status-comment-form:not(.is-reply)')));
    await page.goto(base + "/admin/articles/" + article, { waitUntil: "networkidle" });
    let nativeDialogs = 0;
    page.on("dialog", async dialog => { nativeDialogs++; await dialog.dismiss(); });
    const editor = page.locator('.modal[data-modal-kind="editor"]');
    await editor.waitFor({ state: 'visible' });
    const body = editor.locator('[data-status-editor-input]');
    await body.fill((await body.innerText()) + "\nBrowser editor save.");
    const draft = await body.innerText();
    assert.equal(await editor.locator('[name="title"]').inputValue(), 'Combined article', 'Resource editor pre-fills the article title');
    await page.evaluate(() => TinyCat.lists.refresh(document.querySelector('#blog-articles')));
    assert.equal(await body.innerText(), draft, 'Refreshing the list preserves the editor draft');
    const saved = page.waitForResponse(response => response.request().method() === "POST" && new URL(response.url()).pathname === "/api/articles/" + article);
    await editor.locator('.modal-footer button[type="submit"]').click();
    assert.equal((await saved).status(), 200);
    await editor.waitFor({ state: 'detached' });
    assert.equal(nativeDialogs, 0, "Saving the resource editor uses the shared lifecycle");
    await page.goto(base + '/admin/articles', { waitUntil: 'networkidle' });
    assert.equal(await page.locator('.modal').count(), 0, 'Hydration never creates an editor');
    const originalDocument = await page.evaluate(() => window.performance.timeOrigin);
    const read = async action => {
      const previous = await page.locator('#blog-articles > *').elementHandle();
      const [response] = await Promise.all([
        page.waitForResponse(r => r.request().method() === 'GET' && r.url().includes('/api/articles/management')),
        action()
      ]);
      await page.waitForFunction(element => !element.isConnected, previous);
      return response;
    };
    await page.locator('.admin-search-form input[name="q"]').fill('unlikely-no-article-match');
    assert.equal((await read(() => page.locator('.admin-search-form').evaluate(form => form.requestSubmit()))).status(), 200);
    await page.locator('#blog-articles .empty-state').waitFor();
    assert.equal(await page.evaluate(() => window.performance.timeOrigin), originalDocument, 'Search uses AJAX');
    await read(() => page.locator('[data-filter-remove="q"]').click());
    await page.locator('#blog-articles [data-filter-menu] summary').click();
    await read(() => page.locator('#blog-article-filter select[name="status"]').selectOption('hidden'));
    assert.equal(await page.locator('#blog-article-filter select[name="status"]').inputValue(), 'hidden');
    assert(await page.locator('#blog-articles [data-filter-menu]').evaluate(node => node.open), 'Filter stays open after AJAX');
    await page.keyboard.press('Escape');
    await page.locator('[data-filter-remove="status"]').waitFor();
    await read(() => page.locator('[data-filter-remove="status"]').click());
    await page.keyboard.press('Escape');
    await page.locator('[data-modal-url="/api/articles/creation"]').click();
    await editor.waitFor({ state: 'visible' });
    await editor.locator('[name="title"]').fill('Browser AJAX article');
    await editor.locator('[data-status-editor-input]').fill('Browser-only fixture article.');
    const created = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/articles');
    await editor.locator('.modal-footer button[type="submit"]').click();
    const createdResponse = await created;
    assert.equal(createdResponse.status(), 201);
    const createdId = (await createdResponse.json()).data.id;
    await editor.waitFor({ state: 'detached' });
    const deletion = page.locator('form[action="/api/articles/' + createdId + '"]');
    await deletion.waitFor();
    await deletion.locator('button').click();
    const confirm = page.locator('.modal[data-modal-kind="confirm"]');
    await confirm.waitFor({ state: 'visible' });
    const deleted = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/articles/' + createdId);
    await confirm.locator('button.btn-danger').click();
    assert.equal((await deleted).status(), 200);
    await deletion.waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => window.performance.timeOrigin), originalDocument, 'Filtering, creation and deletion retain the list document');
    await page.setViewportSize({ width: 1440, height: 900 });
    await read(() => page.locator('#blog-articles .table-sort').first().click());
    await page.waitForURL(/sort=title/);
    await read(() => page.locator('#blog-articles select[name="per_page"]').selectOption('10'));
    await page.waitForURL(/per_page=10/);
    await read(() => page.locator('#blog-articles .pagination a').last().click());
    await page.waitForURL(/page=/);
    assert.equal(new URL(page.url()).searchParams.get('sort'), 'title', 'Paging retains sorting');
    assert.equal(await page.evaluate(() => window.performance.timeOrigin), originalDocument, 'Sorting and paging use the shared AJAX transport');
    await page.goto(base + "/admin/modules", { waitUntil: "networkidle" });
    const toggle = async (id = "tinycat.blog") => {
      const form = page.locator('form[action="/api/modules/activation"]').filter({ has: page.locator('input[name="id"][value="' + id + '"]') });
      await form.locator("button").click();
      const dialog = page.locator('.modal[id^="confirm-modal-"]');
      await dialog.waitFor({ state: "visible" });
      const result = page.waitForResponse(response => response.url().endsWith("/api/modules/activation") && response.request().method() === "POST");
      const [response] = await Promise.all([
        result,
        page.waitForNavigation({ waitUntil: "networkidle" }),
        dialog.locator("button.btn-primary").click()
      ]);
      assert.equal(response.status(), 201);
    };
    const workspace = process.argv[4];
    const upload = async version => {
      await page.locator('[data-modal-url="/api/modules/packages/creation"]').click();
      const dialog = page.locator('.modal[data-modal-kind="editor"]');
      await dialog.waitFor({ state: 'visible' });
      await dialog.locator('input[type="file"]').setInputFiles(path.join(workspace, 'upload-module-' + version + '.zip'));
      await dialog.locator('.modal-footer button[type="submit"]').click();
      const confirmation = page.locator('.modal[data-modal-kind="confirm"]');
      await confirmation.waitFor({ state: 'visible' });
      const result = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/modules/packages');
      const [response] = await Promise.all([result, page.waitForNavigation({ waitUntil: 'networkidle' }), confirmation.locator('button.btn-primary').click()]);
      assert.equal(response.status(), 201, 'Module ZIP upload succeeds before navigation');
      const inventoryResponse = await context.request.get(base + '/api/modules');
      assert.equal(inventoryResponse.status(), 200, await inventoryResponse.text());
      const inventory = (await inventoryResponse.json()).data;
      return inventory.items.find(item => item.id === 'fixture.upload');
    };
    assert.equal(await page.locator('.modal').count(), 0, 'Module upload editor is loaded only on demand');
    let uploaded = await upload('1.0.0');
    assert(uploaded && uploaded.package_version === '1.0.0' && !uploaded.active, 'New ZIP installs as an inactive module');
    assert(!fs.existsSync(path.join(workspace, 'storage/upload-fixture-loaded')), 'Neither upload inspection nor inventory executes inactive uploaded PHP');
    assert.equal((await context.request.get(base + '/fixture-module')).status(), 404);
    await toggle('fixture.upload');
    assert.equal(await (await context.request.get(base + '/fixture-module')).text(), 'Uploaded module is active');
    assert(fs.existsSync(path.join(workspace, 'storage/upload-fixture-loaded')), 'Explicit activation loads module PHP');
    await page.goto(base + '/admin/modules', { waitUntil: 'networkidle' });
    await toggle('fixture.upload');
    fs.unlinkSync(path.join(workspace, 'storage/upload-fixture-loaded'));
    assert.equal((await context.request.get(base + '/fixture-module')).status(), 404);
    uploaded = await upload('2.0.0');
    assert(uploaded.package_version === '2.0.0' && !uploaded.active, 'ZIP upgrade preserves inactive state');
    assert(!fs.existsSync(path.join(workspace, 'storage/upload-fixture-loaded')), 'Updating an inactive module does not initialize it');
    await toggle();
    assert.equal((await context.request.get(base + "/blog")).status(), 404, "Browser disables Blog through the common confirmation and API");
    await page.goto(base + "/admin/modules", { waitUntil: "networkidle" });
    await toggle();
    assert.equal((await context.request.get(base + "/blog")).status(), 200, "Browser reactivates the retained Blog");
    await page.goto(base + "/admin/modules", { waitUntil: "networkidle" });
    await toggle("tinycat.social");
    for (const [name, url] of [["core-modules", "/admin/modules"], ["core-blog", "/blog"], ["core-articles", "/admin/articles"], ["core-editor", "/admin/articles/new"]]) {
      assert.equal((await page.goto(base + url, { waitUntil: "networkidle" })).status(), 200);
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), "Core presentation fits the mobile viewport: " + url);
      if (output) { await page.screenshot({ path: path.join(output, name + "-390.png") }); }
    }
    await page.goto(base + "/admin/modules", { waitUntil: "networkidle" });
    await toggle("tinycat.social");
    assert.deepEqual(errors, [], "No browser runtime errors");
    console.log("PASS Blog and source AJAX CRUD, preview, draft preservation, independent activation and sixteen responsive layouts");
  } finally { await browser.close(); }
})().catch(error => {
  if (process.env.TINYCAT_UI_DIAGNOSTICS_DIR) {
    fs.mkdirSync(process.env.TINYCAT_UI_DIAGNOSTICS_DIR, { recursive: true });
    fs.writeFileSync(path.join(process.env.TINYCAT_UI_DIAGNOSTICS_DIR, 'blog-browser-error.txt'), String(error.stack || error));
  }
  console.error(error); process.exitCode = 1;
});
