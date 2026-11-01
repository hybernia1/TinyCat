"use strict";
(async () => {
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const root = path.resolve(__dirname, "../..");
let checks = 0;
function check(condition, message) { checks++; assert.ok(condition, message); }
class Node {
  constructor(tag, text = "") {
    this.style = { setProperty() {}, removeProperty() {} };
    this.tag = tag; this.attributes = {}; this.children = []; this.value = ""; this._text = text;
    this.dataset = new Proxy({}, { get: (_, key) => this.getAttribute("data-" + String(key).replace(/[A-Z]/g, c => "-" + c.toLowerCase())), set: (_, key, value) => { this.setAttribute("data-" + String(key).replace(/[A-Z]/g, c => "-" + c.toLowerCase()), value); return true; }, deleteProperty: (_, key) => { this.removeAttribute("data-" + String(key).replace(/[A-Z]/g, c => "-" + c.toLowerCase())); return true; } });
    this.classList = { add: value => { this.className += " " + value; }, toggle: (value, enabled) => { const names = this.className.split(/\s+/).filter(x => x !== value); if (enabled) names.push(value); this.className = names.join(" "); } };
  }
  set className(value) { this.setAttribute("class", value); }
  set id(value) { this.setAttribute("id", value); }
  get id() { return this.getAttribute("id") || ""; }
  get className() { return this.getAttribute("class") || ""; }
  set value(value) { this._value = String(value); if (this.tag === "select") this.children.forEach(child => { child.selected = child.getAttribute("value") === this._value; }); }
  get value() { if (this.tag === "select") { const option = this.children.find(child => child.selected) || this.children[0]; return option ? option.getAttribute("value") : ""; } return this._value; }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text + this.children.map(x => x.textContent).join(""); }
  set innerHTML(_) { throw new Error("A module renderer must never interpret HTML."); }
  setAttribute(name, value) { this.attributes[name] = String(value); if (name === "value") this._value = String(value); if (name === "selected") this.selected = true; if (name === "disabled") this.disabled = true; }
  getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
  removeAttribute(name) { delete this.attributes[name]; }
  appendChild(node) { if (node.tag === "fragment") { node.children.slice().forEach(child => this.appendChild(child)); return node; } if (node.parent) node.remove(); node.parent = this; this.children.push(node); return node; }
  append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
  insertBefore(node, before) { node.remove(); const index = this.children.indexOf(before); if (index < 0) throw new Error('Missing insertion target'); node.parent = this; this.children.splice(index, 0, node); return node; }
  replaceChildren(...nodes) { this._text = ""; this.children = []; this.append(...nodes); }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  get childNodes() { return this.children; }
  get tagName() { return this.tag.toUpperCase(); }
  get options() { return this.tag === "select" ? this.children : undefined; }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(x => x !== this); }
  querySelectorAll(selector) {
    const match = node => {
      if (selector.includes(",")) return selector.split(",").some(tag => node.tag === tag.trim());
      const attr = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
      if (attr) return node.getAttribute(attr[1]) !== null && (attr[2] === undefined || node.getAttribute(attr[1]) === attr[2]);
      return selector[0] === "." ? node.className.split(/\s+/).includes(selector.slice(1)) : node.tag === selector;
    };
    const found = []; const visit = node => { for (const child of node.children) { if (match(child)) found.push(child); visit(child); } }; visit(this); return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  closest() { return null; }
  getBoundingClientRect() { return { top: 20, bottom: 60, left: 20, right: 300, height: 40 }; }
  cloneNode(deep) { const node = new Node(this.tag); node.attributes = { ...this.attributes }; node.value = this.attributes.value || ""; if (deep) this.children.forEach(child => node.appendChild(child.cloneNode(true))); return node; }
  dispatchEvent() { return true; }
  addEventListener() {}
}
const labels = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--catalog"], { encoding: "utf8" }));
const definitions = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--templates"], { encoding: "utf8" }));
const document = { addEventListener() {}, querySelector: () => null, body: new Node("body"), createElement: tag => new Node(tag), createElementNS: (_, tag) => new Node(tag), createTextNode: text => new Node("text", text), createDocumentFragment: () => new Node("fragment"), getElementById: id => document.body.querySelector('[id="' + id + '"]') };
document.documentElement = { dataset: JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--dates"], { encoding: "utf8" })) };
const window = { innerHeight: 812, innerWidth: 390, clearTimeout() {}, setTimeout() { return 0; }, setInterval() { return 0; }, TinyCat: {}, history: { state: null }, location: new URL("https://tinycat.test/") };
const context = vm.createContext({ window, document, Node, AbortController, URL, URLSearchParams, console, Set, Element: Node, RadioNodeList: class {}, CustomEvent: class {} });
const moduleAssets = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--all"], { encoding: "utf8", maxBuffer: 8e6 }));
for (const asset of moduleAssets) vm.runInContext(asset.source, context, { filename: asset.path });
const T = window.TinyCat;
// Minimal DOM fixture covers filter data; native popover interaction has its own browser suite.
T.popovers.open = menu => { menu.open = true; };
const mediaController = T.status.controllers.media;
check(mediaController.allowedStatusVideoUrl("https://www.youtube.com/embed/abc123", "https://www.youtube.com") !== "", "Players use the origin supplied by central provider rules.");
check(mediaController.allowedStatusVideoUrl("https://evil.example/embed/abc123", "https://www.youtube.com") === "", "A player cannot change the server-approved origin.");
check(mediaController.allowedStatusVideoUrl("http://www.youtube.com/embed/abc123", "https://www.youtube.com") === "", "Players require HTTPS.");
const playerRoot = new Node("div"), playerButton = new Node("button");
playerRoot.dataset.embedUrl = "https://www.youtube.com/embed/abc123";
playerRoot.dataset.embedOrigin = "https://www.youtube.com";
playerButton.setAttribute("data-status-video-load", "");
let loadPlayer;
playerButton.addEventListener = (event, callback) => { if (event === "click") loadPlayer = callback; };
const playerMount = new Node("div"), playerSource = new Node("a");
playerMount.setAttribute("data-status-video-mount", "");
playerMount.appendChild(playerButton);
playerSource.setAttribute("href", "https://www.youtube.com/watch?v=abc123");
playerRoot.append(playerMount, playerSource);
mediaController.initStatusVideoRoot(playerRoot);
check(playerRoot.querySelectorAll("iframe").length === 0, "Initializing a video card does not load an external frame.");
loadPlayer();
check(playerRoot.querySelector("iframe").src.startsWith("https://www.youtube.com/embed/abc123?"), "The external player is created only after clicking.");
check(playerRoot.querySelector("a") === playerSource, "Loading a player preserves the original source link.");
T.components.register(definitions);
let filterMenuFixture = T.components.render('ui/filter-trigger', { id: 'filter-panel-fixture' });
document.body.appendChild(filterMenuFixture);
filterMenuFixture.querySelector('[data-filter-panel="filter-panel-fixture"]').parentElement = filterMenuFixture;
const filterFormFixture = new Node('form');
filterFormFixture.dataset.modalCloseOnSuccess = 'true';
const filterFieldFixture = new Node('input'); filterFieldFixture.setAttribute('name', 'status'); filterFieldFixture.value = 'error';
const filterClearFixture = new Node('a'); filterClearFixture.setAttribute('data-modal-close', '');
filterFormFixture.append(filterFieldFixture, filterClearFixture);
T.ui.filterPanel('filter-panel-fixture', filterFormFixture);
check(filterMenuFixture.querySelector('[data-filter-panel="filter-panel-fixture"]').querySelector('form') === filterFormFixture && !filterFormFixture.hidden && !filterFormFixture.getAttribute('data-modal-close-on-success') && filterClearFixture.getAttribute('data-modal-close') === null, 'Dropdown filters mount their real form beside the trigger and cannot close an enclosing editor modal.');
check(filterFieldFixture.getAttribute('data-submit-on-change') !== null, 'Changing a visible filter uses the shared automatic-submit behavior.');
check(filterMenuFixture.getAttribute('data-popover') !== null && filterMenuFixture.querySelector('.popover-panel') && filterMenuFixture.querySelector('summary').className.includes('btn-secondary'), 'Filters reuse the shared popover surface and highlight an active selection with the standard secondary button.');
filterFieldFixture.value = ''; T.filters.hydrate();
check(filterMenuFixture.querySelector('summary').className.includes('btn-ghost'), 'An unfiltered list uses the standard quiet ghost trigger.');
filterFieldFixture.value = 'error'; T.filters.hydrate();
filterMenuFixture.open = true;
filterFormFixture.dataset.filterFocus = 'status';
filterFormFixture.closest = selector => selector === '[data-filter-menu]' ? filterMenuFixture : selector === '[data-filter-content]' ? filterFormFixture : null;
filterMenuFixture.querySelector('[data-filter-panel="filter-panel-fixture"]').scrollTop = 64;
const openFilterState = T.filters.capture(filterFormFixture);
filterMenuFixture.remove();
filterMenuFixture = T.components.render('ui/filter-trigger', { id: 'filter-panel-fixture' }); document.body.appendChild(filterMenuFixture);
filterMenuFixture.querySelector('[data-filter-panel="filter-panel-fixture"]').parentElement = filterMenuFixture;
T.filters.hydrate();
check(filterMenuFixture.querySelector('input').value === 'error' && T.filters.get('filter-panel-fixture') === filterFormFixture, 'Replacing list controls reattaches the filter form without losing its selected state.');
filterFieldFixture.focus = options => { filterFieldFixture.focusedWithoutScrolling = options.preventScroll; };
T.filters.restore(openFilterState);
check(filterMenuFixture.open && filterMenuFixture.querySelector('[data-filter-panel="filter-panel-fixture"]').scrollTop === 64 && filterFieldFixture.focusedWithoutScrolling, 'Automatically applying a filter preserves the open popover, scroll and keyboard focus after list replacement.');
filterMenuFixture.open = false;
const closedFilterState = T.filters.capture(filterFormFixture);
T.filters.restore(closedFilterState);
check(closedFilterState === null && !filterMenuFixture.open, 'A menu closed while the request was pending is not reopened by its response.');
const replacementFilter = new Node('form'), replacementField = new Node('select');
replacementField.setAttribute('name', 'status'); replacementField.focus = () => { replacementField.restoredFocus = true; }; replacementFilter.appendChild(replacementField);
T.ui.filterPanel('filter-panel-fixture', replacementFilter);
T.filters.restore(openFilterState);
check(filterMenuFixture.open && replacementField.restoredFocus && T.filters.get('filter-panel-fixture') === replacementFilter, 'Filters whose forms are regenerated restore the open state and focus to the new control.');
T.filters.remove('filter-panel-fixture'); filterMenuFixture.remove();
check(!T.filters.get('filter-panel-fixture') && !document.getElementById('filter-panel-fixture'), 'Disposing scoped filters removes both their form and stored reference.');
{
  const summary = T.components.render('ui/list-summary', { target: '#summary-fixture', pagination: { total: 12 }, filters: { q: '<needle>' } });
  document.body.appendChild(summary);
  const form = new Node('form'); form.dataset.ajaxTarget = '#summary-fixture';
  const statusField = T.ui.select('common.status', 'status', 'active', { '': 'All', active: 'Active', ban: 'Inactive' });
  const statusSelect = statusField.querySelector('select'); statusSelect.closest = () => statusField;
  const roleField = T.ui.select('common.role', 'role', 'admin', { '': 'All', admin: 'Administrator' });
  roleField.querySelector('select').closest = () => roleField;
  form.append(statusField, roleField, T.ui.hidden('sort', 'username'), T.ui.hidden('per_page', '10'));
  T.ui.filterPanel('summary-filter-fixture', form);
  check(!summary.querySelector('[data-list-result-count]') && summary.textContent.includes('<needle>') && !summary.querySelector('needle'), 'The list summary contains only active filter badges and safely rendered search text, without a record-count prefix.');
  check(summary.querySelector('[data-list-summary-filters]').children.length === 2 && summary.textContent.includes(T.ui.text('common.status') + ': Active') && summary.textContent.includes(T.ui.text('common.role') + ': Administrator'), 'Multiple active filters show their localized field and option labels instead of raw codes.');
  statusSelect.value = ''; roleField.querySelector('select').value = ''; T.filters.hydrate();
  check(summary.querySelector('[data-list-summary-filters]').children.length === 0, 'Cleared filters disappear from the summary without including sorting or page-size metadata.');
  T.filters.remove('summary-filter-fixture'); summary.remove();
}
{
  const apiRequest = T.api.request, form = new Node('form');
  let pendingFilterSignal;
  T.ui.filterPanel('pending-filter-fixture', form);
  T.api.request = (_url, options) => { pendingFilterSignal = options.signal; return new Promise(() => {}); };
  T.forms.request(form, '/api/filter-fixture', { method: 'GET' });
  T.filters.remove('pending-filter-fixture');
  check(pendingFilterSignal.aborted && !T.filters.get('pending-filter-fixture'), 'Removing a filter cancels its pending transport rather than updating a closed workspace later.');
  T.api.request = apiRequest;
}
const emptyState = T.components.render('ui/empty-state', { label: 'Nothing <safe>', icon: 'clock' });
check(emptyState.className === 'empty-state' && emptyState.getAttribute('role') === 'status' && emptyState.querySelector('.empty-state-icon').querySelector('use').getAttribute('href') === '#clock' && emptyState.querySelector('.empty-state-label').textContent === 'Nothing <safe>' && !emptyState.querySelector('safe'), 'Shared empty states display a contextual icon and safely rendered label with status semantics.');
check(T.components.render('ui/empty-state', {}).querySelector('.empty-state-label').textContent === T.ui.text('common.no_records'), 'Lists without a custom empty message use the shared no-records translation.');
for (const [tone, icon, css] of [['ok', 'check-circle', 'success'], ['critical', 'x-circle', 'danger'], ['warning', 'alert', 'warning']]) {
  const indicator = T.components.render('ui/status-badge', { tone, label: 'Result <safe>' });
  check(indicator.className.includes('status-indicator-' + css) && indicator.getAttribute('role') === 'img' && indicator.getAttribute('aria-label') === 'Result <safe>' && indicator.getAttribute('title') === 'Result <safe>' && indicator.querySelector('use').getAttribute('href') === '#' + icon && indicator.textContent === '', 'Binary outcomes use accessible, titled success/error icons without a visible text label.');
}
check(T.components.render('scheduler/status', { status: 'running' }).textContent === T.ui.text('cron.statuses.running'), 'Running work retains a distinct progress label instead of a success or error icon.');

T.i18n.register("en", labels);
const embedModal = T.status.modal(new URL("https://tinycat.test/api/statuses/42/embed"), { data: { frame_url: "https://tinycat.test/status/42/embed", snippet: '<iframe title="example"></iframe>' } }, "status-embed-modal-42");
check(embedModal.getAttribute("role") === "dialog" && embedModal.querySelector("iframe").src === "https://tinycat.test/status/42/embed", "Embedding opens the standard dialog with a same-origin preview.");
check(embedModal.querySelector("textarea").getAttribute("readonly") !== null && embedModal.querySelector("textarea").value === '<iframe title="example"></iframe>', "The embed modal exposes code as selectable text, never interpreted markup.");
assert.throws(() => T.status.modal(new URL("https://tinycat.test/api/statuses/42/embed"), { data: { frame_url: "https://evil.test/status/42/embed", snippet: "" } }, "foreign-embed"), /Invalid embed preview/);

const socialCard = T.components.render("status/link", { link: { url: "https://facebook.com/ExamplePage/posts/123", title: "Author", description: "Post text", provider: "facebook", provider_name: "Facebook", card_kind: "social", card_icon: "message-circle" } });
check(socialCard.querySelector('[data-card-kind="social"]').textContent.includes("Facebook"), "Social cards have a provider header and keep post text local.");
const audioCard = T.components.render("status/link", { link: { url: "https://open.spotify.com/track/example", title: "Track", provider: "spotify", provider_name: "Spotify", card_kind: "audio", card_icon: "music" } });
check(audioCard.querySelector('[data-card-kind="audio"]').querySelectorAll("use").some(icon => icon.getAttribute("href").endsWith("#music")), "Audio cards use the local music icon.");
const podcastCard = T.components.render("status/link", { link: { url: "https://example.com/episode", title: "Podcast", audio_url: "https://example.com/episode.mp3", card_kind: "audio" } });
const podcastRoot = podcastCard.querySelector(".status-audio-card");
check(podcastRoot.querySelectorAll("audio").length === 0 && podcastRoot.querySelectorAll("button").length === 1, "RSS audio uses the same play placeholder as embedded audio.");
let loadPodcast;
podcastRoot.querySelector("button").addEventListener = (event, callback) => { if (event === "click") loadPodcast = callback; };
const originalCreateElement = document.createElement;
let playCalls = 0;
document.createElement = tag => { const element = originalCreateElement(tag); if (tag === "audio") element.play = () => { playCalls++; return Promise.resolve(); }; return element; };
mediaController.initStatusVideoRoot(podcastRoot);
check(playCalls === 0 && podcastRoot.querySelectorAll("audio").length === 0, "Initializing audio cards does not start playback or create media elements.");
loadPodcast();
document.createElement = originalCreateElement;
const podcastPlayer = podcastRoot.querySelector("audio");
check(podcastPlayer && podcastPlayer.getAttribute("src") === "https://example.com/episode.mp3" && podcastPlayer.getAttribute("preload") === "none" && podcastPlayer.getAttribute("controls") !== null && playCalls === 1, "Clicking the shared play button mounts and plays native audio.");
check(podcastRoot.querySelector(".status-link-copy").textContent.includes("Podcast") && podcastRoot.querySelectorAll("iframe").length === 0, "Native playback retains the same summary and stays inside the audio card.");
let stoppedAudio = 0, releasedAudio = 0;
podcastPlayer.pause = () => stoppedAudio++;
podcastPlayer.load = () => releasedAudio++;
mediaController.dispose({ querySelectorAll: () => [podcastPlayer] });
check(stoppedAudio === 1 && releasedAudio === 1, "Disposing replaced content stops native playback and releases its media request.");
const reelCard = T.components.render("status/link", { link: { url: "https://facebook.com/reel/123456789", title: "Reel", provider: "facebook", provider_name: "Facebook", card_kind: "reel", embed_url: "https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Freel%2F123456789", embed_origin: "https://www.facebook.com", embed_width_param: "width", embed_allowed: true } });
const reelRoot = reelCard.querySelector('[data-card-kind="reel"]');
reelRoot.getBoundingClientRect = () => ({ width: 320 });
let loadReel;
reelRoot.querySelector("button").addEventListener = (event, callback) => { if (event === "click") loadReel = callback; };
mediaController.initStatusVideoRoot(reelRoot);
check(reelRoot.querySelectorAll("iframe").length === 0, "Facebook reels never load an external player during card initialization.");
loadReel();
check(new URL(reelRoot.querySelector("iframe").src).searchParams.get("width") === "320" && reelRoot.querySelector("a").getAttribute("href").includes("/reel/"), "Facebook reel players fit the card width and retain their source link after clicking.");
const soundcloudCard = T.components.render("status/link", { link: { url: "https://soundcloud.com/forss/flickermood", title: "Flickermood", provider: "soundcloud", provider_name: "SoundCloud", card_kind: "audio", embed_url: "https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fforss%2Fflickermood&auto_play=false", embed_origin: "https://w.soundcloud.com", embed_autoplay_param: "auto_play", embed_autoplay_value: "true", embed_height: 166, embed_allowed: true } });
const soundcloudRoot = soundcloudCard.querySelector('[data-card-kind="audio"]');
let loadSoundcloud;
soundcloudRoot.querySelector("button").addEventListener = (event, callback) => { if (event === "click") loadSoundcloud = callback; };
mediaController.initStatusVideoRoot(soundcloudRoot);
check(soundcloudRoot.querySelectorAll("iframe").length === 0, "SoundCloud never loads an external widget before clicking.");
loadSoundcloud();
const soundcloudFrame = soundcloudRoot.querySelector("iframe"), soundcloudUrl = new URL(soundcloudFrame.src);
check(soundcloudUrl.searchParams.get("auto_play") === "true" && !soundcloudUrl.searchParams.has("autoplay") && soundcloudFrame.height === "166", "Audio players use their provider's autoplay parameter and compact height.");
check(soundcloudRoot.querySelector("a") !== null && soundcloudFrame.allow === "autoplay" && !soundcloudFrame.allowFullscreen, "Audio playback preserves the source link without requesting video-only permissions.");
check(soundcloudRoot.className.includes("status-audio-card") && soundcloudRoot.querySelector(".status-link-copy").textContent.includes("Flickermood"), "SoundCloud retains the same audio summary as RSS after playback starts.");
const spotifyCard = T.components.render("status/link", { link: { url: "https://open.spotify.com/track/1234567890123456789012", title: "Spotify track", card_kind: "audio", embed_allowed: true, provider: "spotify", provider_name: "Spotify" } });
check(spotifyCard.querySelectorAll(".status-audio-card").length === 1 && spotifyCard.querySelectorAll(".status-link-copy").length === 1 && spotifyCard.querySelectorAll("audio").length === 0, "Spotify uses the shared audio card with its provider player.");
const sortHeading = T.components.render("ui/sort-heading", {
  label: "Name <safe>", order: { url: "/api/users?sort=username&direction=desc&page=1", history: "/admin/users?sort=username&direction=desc&page=1", target: "#users-list", ascending: true, descending: false }
});
const sortLink = sortHeading.querySelector("a");
check(sortLink.getAttribute("href") === "/api/users?sort=username&direction=desc&page=1" && sortLink.getAttribute("data-history") === "/admin/users?sort=username&direction=desc&page=1", "Sort headings share server-prepared AJAX and history URLs.");
check(sortLink.textContent === "Name <safe>" && sortLink.querySelectorAll("[data-active]").length === 1, "Sort headings render literal labels and highlight only the active direction.");
check(sortLink.querySelectorAll("use").map(node => node.getAttribute("href")).join(",") === "#arrow-up", "Ascending sorting shows one shared SVG arrow.");
for (const descending of [true, false]) {
  const heading = T.components.render('ui/sort-heading', { label: 'Name', order: { descending, ascending: false } });
  check(heading.querySelectorAll('use').length === 1 && heading.querySelector('use').getAttribute('href') === (descending ? '#arrow-down' : '#arrow-up') && heading.querySelectorAll('[data-active]').length === (descending ? 1 : 0), 'Descending and inactive headings retain one arrow with the correct direction and active state.');
}
const plainHeading = T.components.render("ui/sort-heading", { label: "Name" });
check(plainHeading.textContent === "Name" && plainHeading.querySelector("a") === null, "Embedded detail tables without sort state retain plain headings.");
const dateCases = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--date-fixtures"], { encoding: "utf8" }));
const originalDates = { ...document.documentElement.dataset };
document.documentElement.dataset.dateRelative = "false";
dateCases.absolute.forEach(item => { document.documentElement.dataset.dateFormat = item.format; check(T.dates.format(item.value) === item.expected, "PHP/JS date preset and DST offset parity: " + item.format); });
const localeLabels = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--catalogs"], { encoding: "utf8", maxBuffer: 8e6 }));
const csLabels = localeLabels.cs;
dateCases.relative.forEach(item => { if (T.i18n.locale() !== item.locale) T.i18n.register(item.locale, localeLabels[item.locale]); check(T.dates.relative(new Date(item.value), item.base) === item.expected, "PHP/JS relative-time threshold and plural parity: " + item.locale + " " + item.value); });
T.i18n.register("en", labels);
document.documentElement.dataset = originalDates;
const pluralCases = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--translation-fixtures"], { encoding: "utf8" }));
pluralCases.forEach(item => {
  if (T.i18n.locale() !== item.locale) T.i18n.register(item.locale, localeLabels[item.locale]);
  check(item.category === new Intl.PluralRules(item.locale).select(item.count), "Plural category matches CLDR: " + item.locale + " " + item.count);
  check(T.i18n.pluralCategory(item.count, item.locale) === item.category && T.i18n.plural(item.key, item.count) === item.expected, "PHP/JS plural and regional fallback parity: " + item.locale + " " + item.key + " " + item.count);
});
T.i18n.register("en", labels);
check(T.i18n.format("{{name}} :name {name}", { name: "Zofie" }) === "{{name}} :name Zofie", "Only canonical placeholders are interpolated, with literal legacy examples preserved.");
const relativeOrigin = Date.parse("2026-10-26T12:00:00Z");
T.i18n.register("ru", localeLabels.ru);
check(T.dates.relative(new Date(relativeOrigin + 21 * 365 * 86400000), relativeOrigin) === "через 21 год", "Russian numeric one category retains 21 instead of using the exact-one phrase.");
T.i18n.register("uk", localeLabels.uk);
check(T.dates.relative(new Date(relativeOrigin - 21 * 60000), relativeOrigin) === "21 хвилину тому", "Ukrainian relative minutes retain the numeric count and accusative form.");
T.i18n.register("en", labels);
check(T.dates.relative(new Date(relativeOrigin - 86400000), relativeOrigin) === "yesterday", "Exact-one day phrases stay unchanged.");
assert.throws(() => T.i18n.pluralCategory(Infinity, "cs")); checks++;
T.api.registerMessages({ example_saved: "common.save", example_invalid: "common.request_failed" });
check(T.api.message({ code: "example_saved", params: {}, message: "Server fallback" }) === T.i18n.t("common.save"), "Known message codes use the client catalog.");
check(T.api.message({ code: "unknown_event", params: {}, message: "Readable fallback" }) === "Readable fallback", "Unknown codes preserve the readable API fallback.");
check(T.api.errors({ data: { error: { details: { errors: { name: [{ code: "example_invalid", params: {}, message: "Fallback" }] } } } } }).name[0] === T.i18n.t("common.request_failed"), "Field issue codes use the same client message mapping.");
const countedSidebar = T.site.sidebar({ tags: [], authors: [{ id: 2, username: "Member", url: "/author/2", avatar_url: "", posts_count: 5 }], footer: [] }, "/api/site/sidebar");
check(countedSidebar.textContent.includes(T.i18n.plural("public.active_user_posts", 5)), "A translation parameter named count is a value binding, not the count operator.");
const canonicalFieldErrors = T.api.errors;
const followForm = new Node("form"), followButton = new Node("button"), followAction = new Node("input");
followForm.appendChild(followButton);
followForm.elements = { namedItem: () => followAction };
[true, false, true].forEach(following => {
  T.users.follow(followForm, { following });
  check(followButton.querySelectorAll("svg").length === 1 && followButton.querySelector("use").getAttribute("href").endsWith(following ? "#check" : "#plus"), "Follow toggles retain exactly one icon matching the current state.");
  check(followAction.value === (following ? "unfollow" : "follow") && followButton.querySelector("span").textContent === T.ui.text(following ? "public.unfollow" : "public.follow"), "Follow label and next action stay synchronized.");
});
const schedulerModel = { items: [{ name: "cleanup", options: { cleanup_batch: 500 }, latest: null, run_url: "/api/scheduler/tasks/cleanup/runs" }], runs: [{ id: 1, detail_url: "/api/scheduler/runs/1", task: "cleanup", started_at: "2026-10-26T12:00:00Z", status: "failed", mode: "admin", duration_ms: 25, summary: { changed: 0 }, options: { cleanup_batch: 500 }, error: "<img src=x onerror=bad>" }], task_choices: ["feeds", "cleanup"], status_choices: ["failed"], page_choices: { 25: 25 }, filters: { task: "", status: "" }, navigation: { visible: false } };
const schedulerRoot = new Node("div"), schedulerSource = new Node("form");
schedulerSource.setAttribute("action", "/api/scheduler/tasks/cleanup/runs");
check(T.scheduler.result(schedulerSource, { data: schedulerModel, meta: { pagination: { page: 1, per_page: 50 } } }, schedulerRoot), "Scheduler actions refresh the normalized list through the registered client module.");
check(schedulerRoot.querySelectorAll("button").some(button => button.getAttribute("aria-label") === T.ui.text("cron.run_now") && button.className.includes("btn-icon") && button.querySelector(".sr-only")), "Run-now actions use the standard icon button with an accessible hidden label.");
check(schedulerRoot.querySelectorAll("select").find(field => field.getAttribute("name") === "per_page").value === "50", "Scheduler controls read pagination from the shared API metadata.");
check(document.getElementById("scheduler-filter-modal").querySelectorAll("input").find(field => field.getAttribute("name") === "per_page").value === "50", "The filter modal preserves the API's page-size setting.");
const schedulerPageForm = schedulerRoot.querySelectorAll("form").find(form => form.className.includes("admin-per-page-form"));
check(schedulerPageForm.querySelectorAll("input").every(field => field.getAttribute("name") !== "per_page"), "The scheduler page-size form sends no duplicate hidden per_page value.");
const schedulerDetail = T.scheduler.modal(new URL("https://tinycat.test/api/scheduler/runs/1"), { data: { item: Object.assign({}, schedulerModel.runs[0], { sources: [{ source_name: "Fixture RSS source", status: "interrupted", error: "<b>source failure</b>" }] }) } }, "scheduler-run-detail-fixture");
const skippedDetail = T.scheduler.modal(new URL("https://tinycat.test/api/scheduler/runs/1"), { data: { item: Object.assign({}, schedulerModel.runs[0], { summary: { skipped_items: [{ source_id: 7, source_name: "Skipped source", item_hash: "fixture-item-hash", code: "blocked_url", params: { host: "<b>blocked.example</b>" } }] } }) } }, "scheduler-skipped-fixture");
check(skippedDetail.textContent.includes("Skipped source") && skippedDetail.textContent.includes("fixture-item-hash"), "Skipped feed diagnostics identify the source and item in the scheduler detail.");
check(skippedDetail.textContent.includes(T.i18n.t("moderation.messages.blocked_url", { host: "<b>blocked.example</b>" })) && skippedDetail.querySelectorAll("b").length === 0, "Content rejection reasons are localized with parameters and rendered as text.");
check(schedulerDetail.textContent.includes("<img src=x onerror=bad>") && schedulerDetail.querySelectorAll("img").length === 0, "Scheduler errors render as literal text in the standard detail modal.");
check(schedulerDetail.textContent.includes("Fixture RSS source") && schedulerDetail.querySelectorAll(".status-indicator-danger").some(indicator => indicator.getAttribute("aria-label") === T.ui.text("bots.run_statuses.interrupted")) && schedulerDetail.textContent.includes("<b>source failure</b>") && schedulerDetail.querySelectorAll("b").length === 0, "The common run modal renders linked source states and escapes their diagnostic errors.");
check(!schedulerRoot.textContent.includes("<img src=x onerror=bad>") && schedulerRoot.querySelector('[data-modal-url="/api/scheduler/runs/1"]'), "Run history opens details lazily through the standard resource modal.");
{
  const query = document.querySelector, queryAll = document.querySelectorAll;
  const field = new Node("input"), command = new Node("code"), rotate = new Node("form");
  field.value = "";
  command.textContent = 'curl -X POST -H "Authorization: Bearer " "https://tinycat.test/api/scheduler/executions?task=feeds"';
  rotate.setAttribute("action", "/api/scheduler/tokens");
  document.querySelector = selector => selector === "[data-scheduler-token]" ? field : query(selector);
  document.querySelectorAll = selector => selector === "[data-scheduler-command]" ? [command] : queryAll(selector);
  try {
    T.scheduler.result(rotate, { data: { token: "first-token" } });
    check(field.value === "first-token" && command.textContent.includes('Authorization: Bearer first-token"'), "Creating the first scheduler token updates the ready-to-copy HTTP command.");
    T.scheduler.result(rotate, { data: { token: "rotated-token" } });
    check(command.textContent.includes('Authorization: Bearer rotated-token"') && !command.textContent.includes("first-token"), "Rotating a scheduler token replaces the command credential.");
  } finally { document.querySelector = query; document.querySelectorAll = queryAll; }
}
const schedulerPageLink = new Node("a");
schedulerPageLink.setAttribute("href", "/api/scheduler/tasks?page=2&status=failed");
check(T.scheduler.result(schedulerPageLink, { data: schedulerModel, meta: { pagination: { page: 2, per_page: 50 } } }, schedulerRoot), "Scheduler pagination links are recognized through their href.");
check(schedulerRoot.querySelectorAll("use").some(icon => icon.getAttribute("href").endsWith("#play")), "Run-now actions retain the sprite icon after rerendering.");
const sharedPagination = T.components.render("ui/pagination", { target: "#users-list", navigation: { visible: true, from: 1, to: 25, total: 50, previous: null, next: { url: "/api/users?page=2", history_url: "/admin/users?page=2" }, pages: [{ number: 1, current: true, gap_before: false }, { number: 2, current: false, gap_before: false, url: "/api/users?page=2", history_url: "/admin/users?page=2" }] } });
check(sharedPagination.querySelector(".pagination-list") && sharedPagination.querySelector(".is-active").getAttribute("aria-current") === "page", "Shared pagination groups controls and visibly marks the current page.");
check(sharedPagination.querySelector(".pagination-prev").getAttribute("aria-disabled") === "true" && sharedPagination.querySelector(".pagination-prev").getAttribute("href") === null, "Pagination cannot navigate before the first page.");
check(sharedPagination.querySelector(".pagination-next").getAttribute("data-ajax-target") === "#users-list", "Pagination next links retain the standard AJAX target.");
const unsafe = '<img src=x onerror="window.pwned=true">';
const user = { id: 2, username: unsafe, url: "/author/2", avatar_url: "", email: "test@example.test", bio: unsafe, locale: "en", theme: "system" };
let node = T.users.profiles([user]);
check(node.textContent.endsWith(unsafe), "Following profiles preserve literal names without inserting markup.");
check(node.querySelectorAll("img").length === 0, "Malicious names cannot create images or event handlers.");
node = T.status.segments([{ type: "text", text: unsafe }, { type: "mention", text: "@2", url: "/author/2" }]);
check(node.textContent === unsafe + "@2" && node.querySelector("a").getAttribute("href") === "/author/2", "Structured text preserves literal content and explicit mention targets.");
assert.throws(() => T.status.segments([{ type: "link", text: "unsafe", url: "javascript:alert(1)" }])); checks++;
const post = { id: 10, url: "/status/10", author: user, body: unsafe, editable_body: unsafe, segments: [{ type: "text", text: unsafe }], published_at: "2026-01-01T12:00:00Z", published_label: "Today", image: { url: "", alt: "" }, links: [], likes_count: 2, comments_count: 1, liked: false, capabilities: { edit: false, delete: false, report: false, interact: true } };
node = T.status.card(post);
check(node.dataset.statusId === "10" && node.querySelector(".status-body").textContent === unsafe, "Status cards use resource identity and safe DOM text.");
check(node.id === "status-10" && !T.status.card(post, false).id, "Feed cards keep permalink anchors while modal cards avoid duplicate IDs.");
check(node.querySelectorAll("details").length === 0, "Status menus follow server capabilities.");
check(node.querySelector('[data-status-count="likes"]').textContent === "2", "Resource counts are rendered in the existing status controls.");
check(node.querySelector(".public-content-meta") !== null && node.querySelector(".status-manage").querySelector("a").getAttribute("href") === post.url, "API cards retain the server card's small timestamp and permalink control.");
check(T.ui.avatar(user).querySelector("use").getAttribute("href").endsWith("#user"), "Missing avatars use the same SVG user icon as server-rendered cards.");
const modalPost = Object.assign({}, post, { capabilities: { edit: true, delete: true, report: false, interact: true }, image: { url: "/uploads/statuses/fixture.webp", alt: "Fixture" } });
const detailData = { item: modalPost, viewer: user, comments: [], options: { images_enabled: true } };
const commentEditDialog = T.status.modal(new URL("https://tinycat.test/api/statuses/10?panel=comment-edit&comment_id=20"), { data: { ...detailData, comments: [{ id: 20, editable_body: unsafe, capabilities: { edit: true }, replies: [] }] } }, "comment-edit-fixture");
check(commentEditDialog.querySelector(".status-modal-form") && commentEditDialog.querySelector("textarea").textContent === unsafe, "Comment editing renders a spaced form with literal editable text.");
check(commentEditDialog.querySelector("textarea").getAttribute("aria-labelledby") === "comment-edit-fixture-label", "Comment editor has an explicit visible label.");
const historyDialog = T.status.modal(new URL("https://tinycat.test/api/statuses/10/comments/20/revisions"), { data: { items: [{ at: "2026-01-01T12:00:00Z", changes: { body: { from: "Hello old world", to: "Hello new world" } } }] } }, "history-fixture");
check(historyDialog.querySelector("del").textContent === "old" && historyDialog.querySelector("ins").textContent === "new", "Revision history highlights changed text while retaining context.");
check(T.ui.avatar({ ...user, avatar_url: "/avatar.webp" }).querySelector("img").getAttribute("alt") === unsafe, "Feed avatar images always have alternative text.");
const statusDialog = T.status.modal(new URL("https://tinycat.test/api/statuses/10"), { data: detailData }, "status-layout-fixture");
check(statusDialog.querySelector(".modal-backdrop") && statusDialog.querySelector(".status-post-modal-content"), "Status dialogs retain the shared backdrop and original content layout.");
check(statusDialog.querySelector(".status-comment-input-shell") && statusDialog.querySelector(".status-comment-form").children.some(child => child.className.split(/\s+/).includes("avatar")), "The comment form retains a separate avatar column and a full-width editor shell.");
check(statusDialog.querySelector(".status-time-button").getAttribute("data-modal-open") === null, "A status dialog does not recursively open itself from its timestamp.");
const editDialog = T.status.modal(new URL("https://tinycat.test/api/statuses/10?panel=edit"), { data: detailData }, "status-editor-fixture");
check(editDialog.querySelector(".status-compose-main") && editDialog.querySelector('[data-status-image-input]') && editDialog.querySelector('[data-status-image-remove-input]').getAttribute("value") === "0", "Status editing preserves the composer layout, image picker and explicit removal state.");
check(editDialog.querySelector(".modal-footer").querySelector('[type="submit"]').getAttribute("form") === editDialog.querySelector("form").id && !editDialog.querySelector(".modal-body").querySelector('[type="submit"]'), "Post editors keep the image picker in the body and save in the shared footer.");
check(editDialog.querySelector('[name="body"]').value === post.editable_body, "Opening the status editor preserves the exact editable text.");
const profileDialog = T.users.modal(new URL("https://tinycat.test/api/users/2/management?panel=account&focus=email"), { data: { user, options: { languages: { en: "English" }, password_max_length: 72 }, capabilities: { password: true } } }, "profile-layout-fixture");
check(profileDialog.querySelector('[data-editor-sections]') && profileDialog.querySelector('[data-editor-section="account"]'), 'The account-menu target uses the shared sectioned editor instead of a separate profile modal.');
check(profileDialog.querySelector('.modal-tabs').parent === profileDialog.querySelector('.modal-panel') && !profileDialog.querySelector('.modal-header').querySelector('.tabs') && !profileDialog.querySelector('.modal-body').querySelector('.tabs') && profileDialog.querySelector('.modal-body').querySelectorAll('form').length === 3, 'Fixed account tabs do not overlay the body or move its independent forms.');
check(profileDialog.querySelector('[data-tab-panel="email"]').hidden === false && profileDialog.querySelector('[data-tab-panel="account"]').hidden === true, "Profile focus selects the requested tab without mixing all settings into one form.");
check(profileDialog.querySelector(".modal-footer").querySelector('[data-tab-submit]').getAttribute("form") === profileDialog.querySelector('[data-tab-panel="email"]').querySelector("form").id, "Profile dialogs associate their single footer save with the initially selected tab.");
const profileTabs = profileDialog.querySelector('[data-tabs]');
const profileTab = profileTabs.querySelector('[data-tab="account"]');
profileTab.closest = () => profileTabs;
profileTabs.closest = () => profileDialog.querySelector('.modal-panel');
T.tabs.activate(profileTab, false);
check(profileDialog.querySelector('[data-tab-submit]').getAttribute('form') === profileDialog.querySelector('[data-tab-panel="account"]').querySelector('form').id, "Switching tabs retargets the footer save without submitting another form.");
check(profileDialog.querySelector('[data-tab-select]').value === 'account' && profileDialog.querySelector('[data-tab-select]').querySelectorAll('option').length === 4 && profileDialog.querySelector('[data-tab-select-icon]').querySelector('svg'), 'The mobile section switch shares tab selection, all section labels and the active icon.');
check(profileDialog.querySelector('[data-tab-panel="email"]').querySelector("form").getAttribute("action") === "/api/users/me/email", "The email panel submits to its own endpoint.");
check(profileDialog.dataset.modalKind === 'editor' && profileDialog.dataset.modalSize === 'workspace' && profileTabs.querySelectorAll('[data-tab]').length === 4 && profileTabs.querySelectorAll('[role="tab"]').every(tab => tab.querySelector('svg')), 'Frontend profile editing shares the administration frame and icon tabs.');
check(profileDialog.querySelector('[name="username"]').getAttribute('disabled') !== null && !profileDialog.querySelector('[name="scope"]') && !profileDialog.querySelector('[name="role"]') && !profileDialog.querySelector('[name="status"]'), 'The shared appearance keeps the username immutable and does not expose administration controls.');
const avatarTabProfile = T.users.modal(new URL('https://tinycat.test/api/users/2/management?focus=avatar'), { data: { user, options: { languages: { en: 'English' } }, capabilities: { password: false } } }, 'profile-avatar-tab-fixture');
check(!avatarTabProfile.querySelector('[data-tab-panel="avatar"]').hidden && avatarTabProfile.querySelector('.modal-footer').hidden && avatarTabProfile.querySelector('[data-modal-url="/api/users/2/avatar"]') && !avatarTabProfile.querySelector('[data-tab="security"]'), 'The avatar tab opens the shared avatar editor, hides unrelated saves and respects security capabilities.');
const avatarDialog = T.users.modal(new URL("https://tinycat.test/api/users/2/avatar"), { data: { user } }, "avatar-layout-fixture");
check(avatarDialog.querySelector('[data-avatar-upload-input]') && avatarDialog.querySelector('[data-avatar-upload-preview]'), "The avatar dialog provides the existing upload and preview lifecycle hooks.");
check(avatarDialog.querySelector('.avatar-upload-preview').className.split(/\s+/).includes('avatar'), "The avatar preview retains the shared avatar layout and fallback alignment.");
check(avatarDialog.querySelector('.avatar-editor-controls') && avatarDialog.querySelector('.avatar-editor-actions'), "Avatar selection and footer actions have separate layout groups.");
const avatarSave = avatarDialog.querySelector('[data-avatar-save]');
check(avatarSave.getAttribute("form") === avatarDialog.querySelector('form').id && avatarSave.getAttribute("disabled") !== null, "Avatar save belongs to the upload form and starts disabled until a file is ready.");
const existingAvatarDialog = T.users.modal(new URL("https://tinycat.test/api/users/2/avatar"), { data: { user: { ...user, avatar_url: "/avatar.webp" } } }, "avatar-existing-fixture");
check(existingAvatarDialog.querySelector('.avatar-editor-remove') && !existingAvatarDialog.querySelector('.avatar-editor-remove').className.split(/\s+/).includes('btn-primary'), "Avatar removal is a secondary action in the common footer.");
const generatedAvatarDialog = T.users.modal(new URL("https://tinycat.test/api/users/2/avatar"), { data: { user: { ...user, avatar_url: "/avatar/generated/v1/2.svg", avatar_custom: false }, appearance_options: JSON.parse(fs.readFileSync(path.join(root, "App/Users/avatar-options.json"), "utf8")) } }, "avatar-generated-fixture");
check(generatedAvatarDialog.querySelector('[data-avatar-upload-preview]').getAttribute('src') === '/avatar/generated/v1/2.svg' && !generatedAvatarDialog.querySelector('.avatar-editor-remove'), "Generated avatars appear in the preview and cannot be removed as custom uploads.");
check(generatedAvatarDialog.querySelector('[data-avatar-appearance-form]') && !existingAvatarDialog.querySelector('[data-avatar-appearance-form]'), "Only generated avatars offer the appearance editor.");
check(generatedAvatarDialog.querySelector('[data-avatar-gender]') && generatedAvatarDialog.querySelector('[name="appearance"]'), "Gender edits submit a versioned appearance JSON object.");
check(generatedAvatarDialog.querySelector('[data-avatar-creator-toggle]') && !existingAvatarDialog.querySelector('[data-avatar-creator-toggle]'), "The creator arrow appears only beside generated avatars.");
check(generatedAvatarDialog.querySelector('[data-avatar-appearance-form]').hidden === true && generatedAvatarDialog.querySelectorAll('[data-avatar-parameter]').length === 6, "The creator starts collapsed and offers all six parameters from its schema.");
const creatorUpload = generatedAvatarDialog.querySelector('[data-avatar-upload]');
const creatorUploadForm = generatedAvatarDialog.querySelector('form');
const creatorEditor = generatedAvatarDialog.querySelector('.avatar-editor');
creatorUpload.closest = selector => selector === 'form' ? creatorUploadForm : selector === '.avatar-editor' ? creatorEditor : selector === '.modal' ? generatedAvatarDialog : null;
function bindCreatorEvents(node) {
  node.events = {};
  node.addEventListener = (type, callback) => { node.events[type] = callback; };
  node.focus = () => { node.focused = true; };
  node.children.forEach(bindCreatorEvents);
}
bindCreatorEvents(creatorEditor);
T.users.avatarUpload.hydrate(generatedAvatarDialog);
const creatorToggle = creatorEditor.querySelector('[data-avatar-creator-toggle]');
const creatorSettings = creatorEditor.querySelector('[data-avatar-appearance-form]');
creatorToggle.events.click();
check(creatorToggle.getAttribute('aria-expanded') === 'true' && !creatorSettings.hidden && creatorSettings.querySelector('select').focused, "The creator arrow opens its panel and moves keyboard focus to the first parameter.");
const furParameter = creatorSettings.querySelector('[data-avatar-parameter="fur"]');
furParameter.value = 'lilac'; furParameter.events.change();
const previewUrl = creatorEditor.querySelector('[data-avatar-upload-preview]').src;
const submittedAppearance = JSON.parse(creatorSettings.querySelector('[name="appearance"]').value);
check(previewUrl.includes('fur=lilac') && submittedAppearance.fur === 'lilac' && !generatedAvatarDialog.querySelector('[data-avatar-save]').disabled, "Changing a parameter previews the matching image, serializes JSON and enables saving.");
creatorToggle.events.click();
check(creatorSettings.hidden && !generatedAvatarDialog.querySelector('[data-avatar-save]').disabled, "Collapsing the creator preserves unsaved choices and allows saving.");
furParameter.value = 'auto'; furParameter.events.change();
check(generatedAvatarDialog.querySelector('[data-avatar-save]').disabled, "Restoring the original appearance disables an unnecessary save.");
const outfitParameter = creatorSettings.querySelector('[data-avatar-parameter="outfit"]');
const backgroundParameter = creatorSettings.querySelector('[data-avatar-parameter="background"]');
outfitParameter.value = 'sage'; outfitParameter.events.change();
backgroundParameter.value = 'peach'; backgroundParameter.events.change();
const colourPreview = creatorEditor.querySelector('[data-avatar-upload-preview]').src;
check(colourPreview.includes('outfit=sage') && colourPreview.includes('background=peach') && JSON.parse(creatorSettings.querySelector('[name="appearance"]').value).background === 'peach', "Outfit and background selections preview and serialize together.");
const botAccount = { id: 3, username: "FixtureBot", status: "active", bio: unsafe, avatar_url: "", source_count: 2, active_source_count: 0, run_count: 0, post_count: 4, updated_at: "2026-10-26T12:00:00Z" };
const botAccountTable = T.components.render("bots/accounts", { items: [botAccount], filters: {}, pagination: { per_page: 25 }, navigation: { visible: false } });
check(!botAccountTable.querySelector("tbody").querySelector("td").querySelector("a") && botAccountTable.querySelector('[data-modal-url="/api/bots/accounts/3?panel=detail"]'), "Bot account names are plain text and editing uses the same icon-modal interaction as user rows.");
for (const active of [true, false]) {
  const status = active ? 'active' : 'ban', tone = active ? 'success' : 'danger';
  const cases = [
    ['bots/accounts', { items: [{ ...botAccount, status }], filters: {}, pagination: { per_page: 25 }, navigation: { visible: false } }, 'FixtureBot'],
    ['users/admin-row', { user: { ...user, username: 'ListUser', status } }, 'ListUser'],
    ['bots/sources', { items: [{ id: 12, name: 'List source', interval_minutes: 60, enabled: active, bot_username: 'FixtureBot' }], filters: {}, bots: [], sorting: {}, state: {} }, 'List source']
  ];
  for (const [component, model, name] of cases) {
    const view = T.components.render(component, model), row = component === 'users/admin-row' ? view : view.querySelector('tbody').querySelector('tr');
    const identity = row.querySelector('td').children[0], indicator = identity.children[0];
    check(indicator.className.includes('status-indicator-' + tone) && !!indicator.getAttribute('aria-label') && identity.children[1].textContent === name, 'Administration identities show an accessible status icon before their plain name: ' + component);
    check(row.className.includes('table-row-inactive') === !active, 'Inactive rows keep the shared muted appearance: ' + component);
  }
}
const botAccountDialog = T.bots.modal(new URL("https://tinycat.test/api/bots/accounts/3?panel=detail"), { data: { item: botAccount } }, "bot-account-edit-fixture");
const botTitle = botAccountDialog.querySelector('[id="bot-account-edit-fixture-title"]');
check(botTitle.children[0].querySelector('.status-indicator-success') && botTitle.children[1].textContent === 'FixtureBot' && !botAccountDialog.querySelector('[href="/author/3"]'), "Bot dialogs put status before the nickname in the header and omit the public profile link.");
const adminUserDialog = T.users.modal(new URL("https://tinycat.test/api/users/2/management?panel=administration"), { data: { user: { ...user, status: "ban" }, administration: { roles: ["user"], statuses: ["active", "ban"] }, options: {} } }, "admin-user-title-fixture");
const adminUserTitle = adminUserDialog.querySelector('[id="admin-user-title-fixture-title"]');
check(adminUserTitle.children[0].querySelector('.status-indicator-danger') && adminUserTitle.children[1].textContent === user.username, "User administration shares the header status and nickname layout, including inactive accounts.");
check(botAccountDialog.querySelector("form").dataset.apiMethod === "PATCH" && botAccountDialog.querySelector("form").dataset.ajaxTarget === "#bot-detail-account" && !botAccountDialog.querySelector("form").dataset.modalCloseOnSuccess, "The unified bot account tab saves through PATCH and stays open for the other tabs.");
check(botAccountDialog.querySelector("textarea").value === unsafe && botAccountDialog.querySelector('[data-modal-url="/api/users/3/avatar"]') && !botAccountDialog.querySelector('[name="avatar"]') && !botAccountDialog.querySelector('[name="remove_avatar"]'), "Bot edit modals retain biography drafts and open the shared avatar editor instead of uploading through the account form.");
const comment = { id: 20, content_id: 10, parent_id: 0, author: user, body: unsafe, segments: [{ type: "text", text: unsafe }], editable_body: unsafe, created_at: "2026-01-01T12:00:00Z", created_label: "Today", likes_count: 0, liked: false, capabilities: { edit: true, delete: true, interact: true }, replies: [] };
node = T.status.comment(comment, "modal");
check(node.dataset.contentId === "10" && node.querySelector(".status-comment-body").textContent === unsafe, "Comment DOM retains its parent status and safe text.");
check(node.querySelector('[name="parent_id"]').getAttribute("value") === "20", "Reply forms address the comment parent explicitly.");
const form = new Node("form"); form.appendChild(new Node("button")); form.querySelector("button").setAttribute("aria-pressed", "false");
check(T.status.method(form, "react") === "PUT", "An unliked resource requests PUT.");
form.querySelector("button").setAttribute("aria-pressed", "true");
check(T.status.method(form, "react") === "DELETE", "A liked resource requests DELETE rather than a toggle.");
check(T.status.method(form, "comment_update") === "PATCH" && T.status.method(form, "comment_delete") === "DELETE", "Comment edits and deletions use resource methods.");
node = T.notifications.items([{ id: 1, type: "follow", actor: { username: unsafe }, excerpt: unsafe, url: "/status/10", unread: true, created_at: "2026-01-01T12:00:00+00:00" }], false);
check(node.querySelector("strong").textContent === T.i18n.t("notifications.messages.follow", { actor: unsafe }) && node.querySelectorAll("img").length === 0, "Notifications translate event data and render actors and excerpts as literal text.");
check(node.querySelector('[data-api-method="PATCH"]').getAttribute("action") === "/api/notifications/1", "Notification actions address the individual resource.");
const response = { data: { user, options: { languages: { en: "English" }, password_max_length: 1024 }, capabilities: { password: false } }, meta: {} };
node = T.users.modal(new URL("https://tinycat.test/api/users/2/management"), response, "profile-test");
check(node.getAttribute("role") === "dialog" && node.getAttribute("aria-labelledby") === "profile-test-title", "Profile dialogs retain accessible names.");
check(!node.querySelector('[name="current_password"]'), "Other account profiles never show a password change form.");
check(node.querySelector('[name="bio"]').value === unsafe, "Profile editors retain literal draft data.");
const disposedListViews = [];
T.lifecycle.register("list-content-test", { dispose: root => disposedListViews.push(root) });
const target = new Node("div"), oldUsersView = new Node("section"); target.appendChild(oldUsersView);
const oldCreateDialog = T.users.modal(new URL('/api/users/creation',window.location.href), {data:{user:{},options:{roles:['admin','user'],statuses:['active','ban'],password_max_length:1024,username_pattern:'[A-Za-z0-9]+'}}}, 'modal-users-creation');
oldCreateDialog.querySelector('[name="username"]').value = 'UnsavedAccount';
T.users.list(target, { data: { items: [{ ...user, role: "admin", status: "active", updated_at_formatted: "Today", can_delete: false }], filters: { q: "admin", role: "admin", status: "active", updated_from: "", updated_to: "" }, roles: { admin: "Admin", user: "User" }, statuses: { active: "Active" }, role_options: { "": "All", admin: "Admin", user: "User" }, status_options: { "": "All", active: "Active" }, options: { password_max_length: 1024, username_pattern: "[A-Za-z0-9]+", username_hint: "Letters" } }, meta: { pagination: { page: 1, last_page: 1, per_page: 25 } } });
check(target.querySelector(".admin-filter-actions").querySelector('[data-filter-panel="users-filter-modal"]') && target.querySelector(".admin-filter-actions").querySelector('[name="per_page"]'), "AJAX user lists keep filters and page size together in the compact toolbar.");
const filterDialog = document.getElementById("users-filter-modal");
check(!filterDialog.querySelector('[type="submit"]') && filterDialog.querySelector('[name="role"]').getAttribute('data-submit-on-change') !== null && filterDialog.querySelector('[name="q"]').getAttribute('data-submit-on-change') === null, 'Filters apply immediately without an Apply button while hidden search state remains passive.');
check(filterDialog && filterDialog.querySelector('[name="per_page"]').value === "25" && filterDialog.querySelector('[name="role"]').value === "admin", "AJAX list replacement preserves the filter dialog and its current select values.");
check(filterDialog.dataset.filterContent === "" && filterDialog.querySelector('[name="per_page"]').getAttribute("type") === "hidden", "Locally owned filter dialogs remain reusable and discard restores the current filters.");
const adminAvatarFields = T.components.render("users/admin-fields", { user, create: false, options: { roles: {}, statuses: {} } });
check(adminAvatarFields.querySelector('[data-modal-url="/api/users/2/avatar"]') && !adminAvatarFields.querySelector('[name="avatar"]') && !adminAvatarFields.querySelector('[name="remove_avatar"]'), "User administration reuses the frontend avatar editor without duplicate upload or removal controls.");
const createDialog = document.getElementById("modal-users-creation");
check(disposedListViews.includes(oldUsersView) && !disposedListViews.includes(target), "User list replacement disposes its old view while preserving the list container.");
check(!disposedListViews.includes(oldCreateDialog) && createDialog === oldCreateDialog && createDialog.querySelector('[name="username"]').value === "UnsavedAccount", "Refreshing users preserves the open creation resource and its draft.");
check(createDialog.querySelectorAll('[data-tab]').length === 2, "User creation uses the standard Account and Access sections.");
const userTabs = createDialog.querySelector('[data-tabs]');
const accessPanel = userTabs.querySelector('[data-tab-panel="access"]');
const requiredPassword = accessPanel.querySelector('[name="password"]');
userTabs.closest = () => createDialog.querySelector('.modal-panel');
userTabs.querySelectorAll('[data-tab]').forEach(tab => { tab.closest = () => userTabs; });
accessPanel.closest = () => userTabs;
requiredPassword.type = 'password';
requiredPassword.closest = selector => selector === '[data-tab-panel]' ? accessPanel : selector === '[data-captcha], .field' ? requiredPassword.parent : null;
createDialog.querySelector('form').elements = { namedItem: name => name === 'password' ? requiredPassword : null };
check(accessPanel.hidden && requiredPassword.getAttribute('required') !== null, "Required fields retain validation even on initially hidden tabs.");
const revealedInvalid = T.forms.applyErrors(createDialog.querySelector('form'), { password: ['Required password'] });
check(!accessPanel.hidden && revealedInvalid === requiredPassword && requiredPassword.getAttribute('aria-invalid') === 'true', "Server validation reveals the tab containing the first invalid field.");
const createSubmit = createDialog.querySelector(".modal-footer").querySelector('[type="submit"]');
check(createSubmit && createSubmit.getAttribute("form") === createDialog.querySelector("form").id && !createDialog.querySelector(".modal-body").querySelector('[type="submit"]'), "Administration saves have one footer action associated with the editor form.");
check(avatarDialog.querySelector(".modal-footer").querySelector('[data-avatar-save]') && !avatarDialog.querySelector(".modal-body").querySelector('[data-avatar-save]'), "The shared avatar editor uses the same fixed footer placement.");
check(createDialog.dataset.modalDisposeOnClose === "true" && createDialog.querySelector("form").dataset.modalCloseOnSuccess === "true", "Creation resources are disposable and use shared close-on-success handling.");
check(!target.querySelector('[data-api-method="DELETE"]'), "Protected-account capabilities remove deletion controls from AJAX lists.");
const existingBio = new Node("p", "Retained profile bio");
document.querySelectorAll = selector => selector.includes("data-author-bio") ? [existingBio] : [];
const avatarForm = new Node("form"); avatarForm.setAttribute("action", "/api/users/2/avatar"); avatarForm.setAttribute("method", "post"); avatarForm.closest = () => null;
T.users.result(avatarForm, { data: { user: { id: 2, username: "Member", avatar_url: "" } } }, null);
check(existingBio.textContent === "Retained profile bio", "Partial avatar resources never erase profile fields they do not contain.");
let localeReloads = 0;
document.body.dataset.currentUserId = "2";
window.setTimeout = callback => callback();
window.location.reload = () => { localeReloads++; };
T.users.result(avatarForm, { data: { user: { id: 2, locale: "cs" } } }, null);
await Promise.resolve();
check(localeReloads === 1, "Changing the current user's locale reloads the document and its hashed catalog.");
T.users.result(avatarForm, { data: { user: { id: 3, locale: "cs" } } }, null);
T.users.result(avatarForm, { data: { user: { id: 2, locale: "en" } } }, null);
check(localeReloads === 1, "Other accounts and unchanged locales do not reload the current catalog.");
const localeNavigations = [];
document.documentElement.dataset.interfacePrefix = "pl";
document.documentElement.dataset.interfaceLocales = "cs,en,pl,fr";
window.location.assign = url => localeNavigations.push(url);
avatarForm.dataset.originalLocale = "cs";
T.users.result(avatarForm, { data: { user: { id: 2, locale: "cs" } } }, null);
await Promise.resolve();
check(localeNavigations.length === 0 && localeReloads === 1, "Saving an unchanged profile language preserves an explicit URL language.");
T.users.result(avatarForm, { data: { user: { id: 2, locale: "fr" } } }, null);
await Promise.resolve();
check(localeNavigations[0] === "/fr/", "Changing the profile language explicitly switches the URL and catalog together.");
const savedLeaveGuard = T.navigation.canLeave;
T.navigation.canLeave = () => Promise.resolve(false);
T.users.result(avatarForm, { data: { user: { id: 2, locale: 'fr' } } }, null);
await Promise.resolve(); await Promise.resolve();
check(localeNavigations.length === 1, 'A locale save cannot navigate away when another draft blocks leaving.');
T.navigation.canLeave = savedLeaveGuard;
delete avatarForm.dataset.originalLocale;
delete document.documentElement.dataset.interfacePrefix;
delete document.documentElement.dataset.interfaceLocales;
delete window.location.assign;
delete document.body.dataset.currentUserId;
delete window.location.reload;
const templateKeys = new Set();
function collectLabels(value) { if (!value || typeof value !== "object") return; if (typeof value.t === "string") templateKeys.add(value.t); Object.values(value).forEach(collectLabels); }
collectLabels(definitions);
check([...templateKeys].every(key => Object.hasOwn(labels, key)), "Every shared component label has a real translation.");
const sourceRoot = new Node("div");
T.bots.sources(sourceRoot, { bots: [user], items: [{ id: 7, bot_user_id: 2, bot_username: unsafe, name: unsafe, feed_url: "https://example.test/feed", enabled: true, interval_minutes: 60, last_error: unsafe }] });
check(sourceRoot.textContent.includes(unsafe) && sourceRoot.querySelectorAll("img").length === 0, "Bot source values remain literal text during AJAX rendering.");
const sourceActions = sourceRoot.querySelectorAll("form");
check(sourceActions.some(form => form.getAttribute("action") === "/api/bots/sources/7" && form.dataset.apiMethod === "PATCH" && form.querySelector('[name="enabled"]').getAttribute("value") === "0"), "Source pause requests an explicit disabled state at the resource URL.");
check(sourceActions.some(form => form.getAttribute("action") === "/api/bots/sources/7/runs" && form.dataset.apiMethod === "POST"), "Manual feed imports create a run resource.");
node = T.bots.modal(new URL('https://tinycat.test/api/bots/sources/7/management'), { data: { item: { id: 7, bot_user_id: 2, name: unsafe, post_template: unsafe }, bots: [user] } }, 'source-template-fixture').querySelector('form');
check(node.dataset.apiMethod === "PATCH" && node.querySelector('[name="post_template"]').value === unsafe, "Source editing uses a local form and preserves the template as text.");
const sourceEditor = T.bots.modal(new URL("https://tinycat.test/api/bots/sources/7/management"), { data: { item: { id: 7, bot_user_id: 2, name: "Fresh source", feed_url: "https://example.test/feed", post_template: "{{title}}", enabled: true }, bots: [user] } }, "source-editor-test");
check(T.bots.modal(new URL("https://tinycat.test/api/bots/accounts/2/management"), { data: { item: user } }, "bot-editor-test") === null, "The duplicate bot editor is retired.");
for (const editor of [createDialog, sourceEditor, botAccountDialog]) {
  check(editor.dataset.modalKind === "editor" && editor.dataset.modalSize === "workspace", "User and bot editors share the responsive administration frame.");
  check(editor.querySelector('.modal-tabs').parent === editor.querySelector('.modal-panel') && !editor.querySelector('.modal-header').querySelector('.tabs') && !editor.querySelector('.modal-body').querySelector('.tabs') && editor.querySelector('[data-tabs]') === editor.querySelector('.modal-panel'), 'Tabbed editors keep navigation in a separate row between header and body and their fields in the body.');
  check(editor.querySelector('.modal-panel').children.map(child => child.className).join('|') === 'modal-header|modal-tabs|modal-body|modal-footer', 'Header, navigation, scrolling body and footer occupy four independent rows.');
}
const sourceCreateEditor = T.bots.modal(new URL("https://tinycat.test/api/bots/sources/management"), { data: { item: { enabled: true, post_template: '{{title}}', bot_user_id: 2 }, bots: [user] } }, "source-create-test");
const reportEditor = T.status.modal(new URL('https://tinycat.test/api/moderation/report-options?id=10'), { data: { reasons: ['spam'] } }, 'report-contract-fixture');
const authEditor = T.users.modal(new URL('https://tinycat.test/api/users/authentication'), { data: { mode: 'login', registration_enabled: true, captcha: { enabled: false }, options: {}, old: {} } }, 'auth-contract-fixture');
for (const dialog of [commentEditDialog, editDialog, profileDialog, avatarDialog, createDialog, sourceEditor, sourceCreateEditor, botAccountDialog, reportEditor, authEditor]) {
  check(dialog.dataset.modalKind === 'editor', 'Every editable surface opts into the common mobile and keyboard contract.');
  check(dialog.querySelector('.modal-footer') && !dialog.querySelector('.modal-body').querySelector('[type="submit"]'), 'Editor primary actions stay in the shared footer, outside the scrollable body.');
}
for (const dialog of [reportEditor, authEditor]) {
  const submit = dialog.querySelector('.modal-footer').querySelector('[type="submit"]');
  check(submit.getAttribute('form') === dialog.querySelector('form').id && dialog.querySelector('form').dataset.confirmUnsaved === 'true', 'Compact forms retain native submit association and protect unfinished input.');
}
const savedAccountClose = T.dialog.close;
let unexpectedAccountClose = false;
T.dialog.close = () => { unexpectedAccountClose = true; };
const accountDraft = profileDialog.querySelector('[name="bio"]'); accountDraft.value = 'Draft in another tab';
const savedEmailForm = profileDialog.querySelector('[data-editor-section="email"]');
savedEmailForm.closest = () => profileDialog;
T.users.result(savedEmailForm, { data: { user: { id: 2, email: 'saved@example.test' } } }, null);
check(!unexpectedAccountClose && accountDraft.value === 'Draft in another tab', 'Saving one account tab leaves the editor and other tab drafts intact.');
check(!profileDialog.querySelector('[name="current_password"]').parent.parent.dataset.modalCloseOnSuccess, 'A password change cannot implicitly close the other account forms.');
T.dialog.close = savedAccountClose;
check(sourceEditor.querySelectorAll('[data-tab]').length === 3 && sourceCreateEditor.querySelectorAll('[data-tab]').length === 3 && sourceCreateEditor.querySelector('form').dataset.apiMethod === 'POST', "Source create and edit share the same three-section modal.");
check(sourceEditor.querySelector('[name="name"]').value === "Fresh source" && sourceEditor.querySelector('[data-bot-source-preview]'), "Remote source editors use fetched values and retain the preview workflow.");
const reportRoot = new Node("div"), oldReportView = new Node("section"); reportRoot.appendChild(oldReportView);
T.moderation.reports(reportRoot, [{ id: 12, content_id: 9, author_name: unsafe, excerpt: unsafe, open_count: 1, report_count: 1, status: "open" }]);
check(disposedListViews.includes(oldReportView) && !disposedListViews.includes(reportRoot), "Moderation replacement uses child-scoped lifecycle cleanup.");
check(reportRoot.querySelector('[data-modal-url]').dataset.modalUrl === "/api/moderation/reports/12" && reportRoot.querySelectorAll("form").length === 0, "Moderation rows open a review detail before exposing decisions.");
const moderationData = { content: { id: 9, author_name: unsafe, body: unsafe, image_url: "", status: "published" }, reports: [{ id: 12, status: "open", reason: "spam", reporter_name: unsafe, note: unsafe, created_at: "2026-01-01", reviewed_at: null }] };
moderationData.preview = { item: { ...post, capabilities: { edit:false, delete:false, report:false, interact:false } }, comments: [{ id:81, content_id:10, author:user, created_at:"2026-01-01", segments:[{type:"text",text:unsafe}], capabilities:{edit:false,delete:false,interact:false}, likes_count:2, replies:[{id:82,content_id:10,parent_id:81,author:user,segments:[{type:"text",text:"Reply evidence"}],capabilities:{edit:false,delete:false,interact:false},replies:[]}] }] };
const moderationDialog = T.moderation.modal(new URL("https://tinycat.test/api/moderation/reports/12"), { data: moderationData }, "moderation-detail-test");
check(moderationDialog.querySelectorAll('[role="tab"]').length === 2, "Report details use the shared accessible tabs.");
check(moderationDialog.textContent.includes(unsafe) && moderationDialog.querySelectorAll("script").length === 0, "Post previews and report notes render untrusted content as text.");
check(moderationDialog.querySelectorAll("form").length === 2 && moderationDialog.querySelectorAll("form").every(form => form.dataset.apiMethod === "PATCH" && form.dataset.modalCloseOnSuccess === "true"), "Review decisions use the shared mutation lifecycle and close after success.");
check(moderationDialog.querySelectorAll("table").length === 1 && moderationDialog.querySelectorAll("tbody").length === 1, "Report history uses a standard native table.");
check(moderationDialog.querySelector('[data-modal-url]').dataset.modalUrl === "/api/moderation/reports/12?panel=report", "Each report has the standard eye preview trigger.");
check(moderationDialog.querySelectorAll(".status-card").length === 1 && moderationDialog.querySelectorAll(".status-comment").length === 2, "Moderation previews reuse the frontend card and nested comment renderers.");
check(moderationDialog.querySelectorAll(".status-comment-form").length === 0 && moderationDialog.querySelectorAll('[data-modal-open="auth-modal"]').length === 0, "Evidence previews show the thread without comment editors or login prompts.");
const reportPreview = T.moderation.modal(new URL("https://tinycat.test/api/moderation/reports/12?panel=report"), {data:moderationData}, "report-preview-test");
check(reportPreview.textContent.includes(unsafe) && reportPreview.querySelectorAll("script").length === 0, "The eye preview exposes the full report note as literal text.");
check(moderationDialog.querySelector(".modal-editor-actions") && moderationDialog.querySelector('[data-modal-close]'), "Moderation decisions use the shared editor footer with cancellation.");
const emptyHistory = T.components.render("moderation/history", {reports:[]});
check(emptyHistory.querySelectorAll("table").length === 0 && emptyHistory.querySelector(".empty-state"), "Empty report history uses the shared empty state without a redundant table.");
moderationData.reports[0].status = "resolved";
moderationData.content.status = "hidden";
const hiddenReview = T.moderation.modal(new URL("https://tinycat.test/api/moderation/reports/12"), {data:moderationData}, "hidden-review-test");
check(hiddenReview.querySelectorAll("form").length === 1 && hiddenReview.querySelector("form").getAttribute("action").startsWith("/api/moderation/posts/9/publication") && hiddenReview.querySelector("form").dataset.apiMethod === "POST", "Hidden content has one explicit administrative publication action instead of an edit lock.");
moderationData.content.status = "published";
const reviewedDialog = T.moderation.modal(new URL("https://tinycat.test/api/moderation/reports/12"), { data: moderationData }, "moderation-reviewed-test");
check(reviewedDialog.querySelectorAll("form").length === 0, "Reviewed reports preserve their preview without showing stale decisions.");
check(reviewedDialog.dataset.modalKind === "content" && !reviewedDialog.querySelector(".modal-footer"), "Reviewed evidence remains an ordinary read-only dialog without an editor footer.");
check(reportRoot.querySelectorAll("img").length === 0 && reportRoot.textContent.includes(unsafe), "Report excerpts never become markup.");
const footer = new Node("div");
T.site.rich(footer, [{ type: "text", text: unsafe }, { type: "element", name: "script", children: [] }, { type: "element", name: "a", attributes: { href: "mailto:hello@example.test", onclick: "unsafe" }, children: [{ type: "text", text: "Email" }] }]);
check(footer.querySelectorAll("script").length === 0 && footer.querySelector("a").getAttribute("onclick") === null && footer.textContent.startsWith(unsafe), "Structured footer rendering ignores executable nodes and event attributes.");
assert.throws(() => T.site.rich(new Node("div"), [{ type: "element", name: "a", attributes: { href: "javascript:alert(1)" } }])); checks++;
const parityFixtures = JSON.parse(fs.readFileSync(path.join(root, "tests/fixtures/components.json"), "utf8"));
const serverTrees = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php")], { encoding: "utf8" }));
function semanticTree(rootNode) {
  const nodes = [];
  function visit(node) {
    if (node.tag !== "text" && node.tag !== "fragment") {
      const attributes = {};
      Object.keys(node.attributes).sort().forEach(key => {
        if (key === "focusable" || key.endsWith("-state")) return;
        let value = node.attributes[key];
        if (key === "href" && node.tag === "use") value = value.slice(value.indexOf("#"));
        if (key === "value" && node.attributes.name === "_csrf") value = "[csrf]";
        if (["selected", "disabled", "required", "hidden", "checked"].includes(key)) value = "";
        attributes[key] = value;
      });
      nodes.push({ tag: node.tag, attributes, text: node._text + node.children.filter(child => child.tag === "text").map(child => child.textContent).join("") });
    }
    node.children.forEach(visit);
  }
  visit(rootNode); return nodes;
}
parityFixtures.forEach((fixture, index) => {
  const data = Object.assign({}, fixture.data);
  Object.entries(fixture.slots || {}).forEach(([key, text]) => { data[key] = document.createTextNode(text); });
  assert.deepEqual(semanticTree(T.components.render(fixture.component, data)), serverTrees[index], "PHP and browser component parity: " + fixture.component + " fixture " + index);
  checks++;
});
for (const attributes of [{ onclick: "alert(1)" }, { style: "color:red" }, { srcdoc: "<script>bad</script>" }, { src: "javascript:alert(1)" }, { formaction: "java\nscript:alert(1)" }, { src: "data:text/html,bad" }]) {
  assert.throws(() => T.components.render("ui/field", { label: "Unsafe", attributes })); checks++;
}
assert.throws(() => T.components.render("ui/modal", { root_attributes: {}, panel_attributes: {}, close_icon: "<img onerror=bad>", body: "<script>bad</script>" })); checks++;

  const schedulerLocation = window.location, schedulerQuery = document.querySelector, schedulerRequest = T.api.request;
  let restoredSchedulerUrl = "";
  const schedulerHydrate = T.lifecycle.hydrate;
  T.lifecycle.hydrate = () => {};
  try {
    window.location = new URL("https://tinycat.test/admin/cron/list?page=2&status=failed");
    document.querySelector = selector => selector === "#scheduler-list" ? schedulerRoot : schedulerQuery(selector);
    T.api.request = async url => { restoredSchedulerUrl = url; return { data: Object.assign({}, schedulerModel, { filters: { task: "", status: "failed" }, pagination: { page: 2, per_page: 10 } }), meta: { pagination: { page: 2, per_page: 50 } } }; };
    await T.navigation.restore();
    check(restoredSchedulerUrl === "/api/scheduler/tasks?page=2&status=failed", "Browser history restores the scheduler's query, page and filters through AJAX.");
    check(schedulerRoot.querySelectorAll("select").find(field => field.getAttribute("name") === "per_page").value === "50", "History restoration uses metadata instead of stale embedded pagination.");
    const schedulerFilters = document.getElementById("scheduler-filter-modal");
    check(schedulerFilters.querySelectorAll("select").find(field => field.getAttribute("name") === "status").value === "failed", "The filter modal stays synchronized after AJAX navigation.");
    window.location = new URL("https://tinycat.test/admin/bots/accounts?per_page=50&page=2");
    const botAccountsPage = new Node("div");
    document.querySelector = selector => selector === "#bot-accounts-list" ? botAccountsPage : schedulerQuery(selector);
    let restoredBotUrl = "";
    T.api.request = async url => { restoredBotUrl = url; return { data: { items: [], filters: {}, navigation: { visible: false } }, meta: { pagination: { page: 2, per_page: 50 } } }; };
    await T.navigation.restore();
    check(restoredBotUrl === "/api/bots/accounts?per_page=50&page=2" && botAccountsPage.querySelectorAll("select").find(field => field.getAttribute("name") === "per_page").value === "50", "Bot accounts restore their page and page size through browser history like other admin lists.");
  } finally {
    window.location = schedulerLocation; document.querySelector = schedulerQuery; T.api.request = schedulerRequest; T.lifecycle.hydrate = schedulerHydrate;
  }
  const handlers = {};
  document.readyState = "loading";
  document.addEventListener = (name, callback) => { (handlers[name] ||= []).push(callback); };
  document.querySelectorAll = () => [];
  document.querySelector = () => null;
  window.addEventListener = () => {};
  window.setTimeout = setTimeout;
  context.FormData = class extends FormData {
    constructor(form) { super(); (form.fields || []).filter(field => !field.disabled).forEach(field => this.append(field.name, field.value)); }
  };
  let sent;
  T.api = { request: async (url, options) => { sent = { url, options }; return { ok: true, data: { action: "react" }, meta: {} }; }, errors: canonicalFieldErrors };
  const bootAssets = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--bootstrap"], { encoding: "utf8" }));
  for (const asset of bootAssets) vm.runInContext(asset.source, context);
  T.status.controllers.actions.initStatusForms();
  const statusForm = new Node("form");
  statusForm.setAttribute("action", "/api/statuses/10/likes/me"); statusForm.setAttribute("method", "POST");
  statusForm.fields = [{ name: "action", value: "react", disabled: false }, { name: "body", value: "Retained draft", disabled: false }];
  statusForm.querySelectorAll = selector => selector === "button, input, select, textarea" ? statusForm.fields : [];
  statusForm.closest = selector => selector === "form[data-status-form]" ? statusForm : null;
  statusForm.elements = { namedItem: name => statusForm.fields.find(field => field.name === name) || null };
  statusForm.fields.forEach(field => { field.dataset = {}; });
  await handlers.submit[0]({ target: statusForm, preventDefault() {}, submitter: { name: "submit_action", value: "publish" } });
  check(sent.options.body.get("body") === "Retained draft" && sent.options.body.get("submit_action") === "publish", "Actual status submit captures fields and the submitter before controls become disabled.");
  check(statusForm.fields.every(field => !field.disabled) && !statusForm.dataset.statusBusy, "Actual status submit restores controls after the JSON result.");
  let notify = 0;
  T.toasts.show = () => { notify++; };
  T.api.request = async () => { throw new Error("Offline"); };
  await handlers.submit[0]({ target: statusForm, preventDefault() {} });
  check(notify === 1 && statusForm.fields[1].value === "Retained draft" && statusForm.fields.every(field => !field.disabled), "Transport failures retain the actual editor draft and restore its controls.");
  let finish, submitted = 0;
  T.api.request = () => { submitted++; return new Promise(resolve => { finish = resolve; }); };
  const pending = handlers.submit[0]({ target: statusForm, preventDefault() {} });
  await handlers.submit[0]({ target: statusForm, preventDefault() {} });
  check(submitted === 1, "Actual status forms block a second submit while pending.");
  finish({ ok: true, data: { action: "react" }, meta: {} }); await pending;
  const invalidBody = new Node("textarea"), fieldContainer = new Node("label");
  invalidBody.name = "body"; invalidBody.value = "Retained draft"; invalidBody.isConnected = true;
  invalidBody.closest = selector => selector === "[data-captcha], .field" ? fieldContainer : null;
  let focused = false; invalidBody.focus = () => { focused = true; };
  statusForm.fields[1] = invalidBody;
  T.api.request = async () => { const error = new Error("Validation failed."); error.data = { error: { details: { errors: { body: ["Too long."] } } } }; throw error; };
  await handlers.submit[0]({ target: statusForm, preventDefault() {} });
  check(invalidBody.getAttribute("aria-invalid") === "true" && fieldContainer.textContent === "Too long." && focused, "Canonical validation errors reach the actual editor field and move focus to it.");
  context.File = File;
  let emptyFileClock = 100;
  context.FormData = class extends FormData {
    constructor() { super(); this.append("body", "Original"); this.append("image", new File([], "", { lastModified: ++emptyFileClock })); }
  };
  const untouched = new Node("form"); untouched.dataset.confirmUnsaved = "true";
  T.lifecycle.start();
  const redirectForm = new Node("form");
  redirectForm.setAttribute("action", "/api/users/sessions"); redirectForm.setAttribute("method", "POST");
  redirectForm.closest = selector => selector === "form[data-ajax-form]" ? redirectForm : null;
  document.documentElement.dataset.interfacePrefix = "pl";
  document.documentElement.dataset.interfaceLocales = "cs,en,pl";
  let localizedRedirect;
  window.location.assign = url => { localizedRedirect = url; };
  T.api.request = async () => ({ ok: true, data: { redirect: "/status/10" } });
  for (const callback of handlers.submit) { await callback({ target: redirectForm, preventDefault() {} }); }
  check(localizedRedirect === "/pl/status/10", "Successful AJAX form redirects preserve the explicit interface language.");
  delete window.location.assign;
  delete document.documentElement.dataset.interfacePrefix;
  delete document.documentElement.dataset.interfaceLocales;
  T.forms.hydrate({ querySelectorAll: () => [untouched] });
  handlers.change.forEach(callback => callback({ target: { closest: () => untouched } }));
  check(untouched.dataset.dirty === "false", "An empty upload input with a fresh File timestamp does not mark an unchanged editor dirty.");
  const modalWithSibling = new Node('div'), dirtySibling = new Node('form'), partSave = new Node('form');
  dirtySibling.dataset.confirmUnsaved = 'true'; dirtySibling.dataset.dirty = 'true';
  modalWithSibling.querySelectorAll = selector => selector.includes('data-dirty') ? (dirtySibling.dataset.dirty === 'true' ? [dirtySibling] : []) : [];
  partSave.setAttribute('action', '/api/test-save'); partSave.setAttribute('method', 'POST');
  partSave.dataset.modalCloseOnSuccess = 'true'; partSave.dataset.confirmUnsaved = 'true'; partSave.dataset.dirty = 'true';
  partSave.closest = selector => selector === 'form[data-ajax-form]' ? partSave : selector === '.modal' ? modalWithSibling : null;
  const savedPartClose = T.dialog.close; let partCloseCount = 0;
  T.dialog.close = () => { partCloseCount++; };
  T.api.request = async () => ({ ok: true, data: {} });
  for (const callback of handlers.submit) { await callback({ target: partSave, preventDefault() {} }); }
  check(partCloseCount === 0 && partSave.dataset.dirty === 'false' && dirtySibling.dataset.dirty === 'true', 'A successful AJAX save cleans only its form and preserves a dirty sibling before auto-close.');
  dirtySibling.dataset.dirty = 'false';
  for (const callback of handlers.submit) { await callback({ target: partSave, preventDefault() {} }); }
  check(partCloseCount === 1, 'Close-on-success still works after all sibling forms are clean.');
  T.dialog.close = savedPartClose;
  const queueRoot = new Node("div"); queueRoot.id = "bot-queue-list";
  T.api.csrfToken = () => "queue-test-csrf";
  const queueModel = { items: [{ id: 41, bot_id: 7, username: "QueueBot", status: "active", bot_url: "/admin/bots/7", excerpt: "Waiting post", published_at: "2099-01-01T12:00:00+00:00", can_publish: true, publish_url: "/api/bots/queue/41/publication?bot=7" }],
    filters: { bot: "7", q: "Waiting", status: "active", source: "12" }, bots: { "": "All", "7": "QueueBot" }, sources: { "": "All feeds", "12": "RSS fixture" }, statuses: ["active", "waiting", "ban"], sorting: {}, navigation: { visible: false } };
  queueModel.items[0].edit_url = "/api/bots/accounts/7/posts/41/management?panel=edit";
  queueModel.items[0].source = { id: 12, name: "RSS fixture", url: "/admin/bots/7?source=12" };
  T.bots.queue(queueRoot, { data: queueModel, meta: { pagination: { page: 1, per_page: 10 } } });
  T.bots.hydrate(queueRoot);
  check(queueRoot.querySelector('[data-modal-url="/api/bots/accounts/7/posts/41/management?panel=edit"]'), "Queue rows expose the same editor as bot detail posts.");
  const queuePublish = queueRoot.querySelectorAll("form").find(form => (form.getAttribute("action") || "").includes("/publication"));
  check(queuePublish && queuePublish.dataset.ajaxTarget === "#bot-queue-list" && queuePublish.querySelectorAll("input").some(field => field.getAttribute("name") === "_csrf"), "Queued posts publish through the shared CSRF-protected AJAX form.");
  const queueFilter = document.getElementById("bot-queue-filter-modal");
  check(queueFilter && queueFilter.querySelectorAll("select").find(field => field.getAttribute("name") === "bot").value === "7", "Queue filter hydration preserves the selected bot.");
  check(queueFilter.querySelectorAll("select").find(field => field.getAttribute("name") === "source").value === "12" && queueRoot.textContent.includes("RSS fixture"), "The queue shows its RSS source and preserves source selection.");
  T.bots.result(queuePublish, { data: Object.assign({}, queueModel, { items: [] }), meta: { pagination: { page: 1, per_page: 10, total: 0 } } }, queueRoot);
  check(queueRoot.textContent.includes(T.i18n.t("common.no_records")) && !queueRoot.textContent.includes("Waiting post"), "Publication replaces the queue and removes the published row.");
  const unknownSource = T.components.render("bots/post-source", { source: null });
  check(unknownSource.textContent.includes(T.i18n.t("bots.unknown_source")), "Legacy posts show an explicit unknown source.");
  const sourceCounts = T.components.render("bots/sources", { items: [{ id: 12, bot_user_id: 7, name: "RSS fixture", bot_username: "QueueBot", interval_minutes: 60, enabled: true, published_count: 120, queued_count: 70, published_url: "/admin/bots/7?source=12&publication=published", queue_url: "/admin/bots/queue?source=12" }], bots: [], sorting: {}, state: {} });
  check(sourceCounts.textContent.includes("120 / 70") && sourceCounts.querySelectorAll("a").some(link => link.getAttribute("href") === "/admin/bots/queue?source=12"), "Source counts link directly to the corresponding feed queue.");
  const savedRequest = T.api.request, savedQuery = document.querySelector, savedHydrate = T.lifecycle.hydrate;
  const detailEvents = Node.prototype.addEventListener;
  Node.prototype.addEventListener = function (name, callback) { (this.listeners ||= {})[name] = callback; };
  const workspace = T.bots.modal(new URL("https://tinycat.test/api/bots/accounts/3?panel=detail"), { data: { item: botAccount } }, "bot-detail-modal");
  Node.prototype.addEventListener = detailEvents;
  workspace.isConnected = true;
  const workspaceTabs = workspace.querySelector('[data-tabs]');
  const detailSources = workspace.querySelector('[id="bot-detail-sources-list"]');
  const detailPosts = workspace.querySelector('[id="bot-detail-posts-list"]');
  const detailRuns = workspace.querySelector('[id="bot-detail-runs-list"]');
  document.querySelector = selector => selector === '[data-bot-workspace-id]' ? workspace : selector.startsWith('#bot-detail-') ? workspace.querySelector('[id="' + selector.slice(1) + '"]') : savedQuery(selector);
  T.lifecycle.hydrate = () => {};
  const parentFilter = new Node('div'); parentFilter.id = 'bots-filter-modal';
  const parentQuery = new Node('input'); parentQuery.name = 'q'; parentQuery.value = 'parent search'; parentFilter.appendChild(parentQuery); document.body.appendChild(parentFilter);
  check(workspaceTabs.querySelectorAll('[data-tab]').length === 4 && !workspace.querySelector('.modal-footer').hidden, "The bot workspace starts on its editable account tab with one footer save.");
  const detailDraft = workspace.querySelector('textarea'); detailDraft.value = 'Unsaved account draft';
  const sourceNavigation = { visible: true, from: 1, to: 10, total: 11, previous: null, next: { url: '/api/bots/sources?bot=3&page=2', history_url: false }, pages: [{ page: 1, current: true }, { page: 2, current: false, url: '/api/bots/sources?bot=3&page=2', history_url: false }] };
  const workspaceCalls = [];
  T.api.request = async url => {
    workspaceCalls.push(url);
    if (url.startsWith('/api/bots/accounts/3/runs')) {
      return { data: { runs: [], filters: { q: '', status: '', source: '' }, sources: {}, sorting: {}, navigation: { visible: false }, api_url: '/api/bots/accounts/3/runs', history_url: false, list_target: '#bot-detail-runs-list', filter_modal: 'bot-detail-runs-filter-modal', clear_url: '/api/bots/accounts/3/runs', url }, meta: { pagination: { page: 1, per_page: 25 } } };
    }
    return url.startsWith('/api/bots/sources')
      ? { data: { items: [], bots: [botAccount], filters: { q: '', bot: '3' }, sorting: {}, navigation: sourceNavigation }, meta: { pagination: { page: 1, per_page: 25 } } }
      : { data: { items: [], total_count: 4, filters: { q: '', scope: 'modal' }, sources: {}, sorting: {}, navigation: { visible: false }, api_url: '/api/bots/accounts/3/posts', history_url: false, list_target: '#bot-detail-posts-list', filter_modal: 'bot-detail-posts-filter-modal', clear_url: '/api/bots/accounts/3/posts?scope=modal', url: '/api/bots/accounts/3/posts?scope=modal' }, meta: { pagination: { page: 1, per_page: 25 } } };
  };
  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'sources' } });
  await new Promise(resolve => setImmediate(resolve));
  check(workspaceCalls[0] === '/api/bots/sources?bot=3' && detailSources.querySelector('.admin-search-form').dataset.ajaxTarget === '#bot-detail-sources-list', "Source tabs load and submit to a dedicated list target.");
  check(parentQuery.value === 'parent search' && !detailSources.querySelector('.admin-search-form').dataset.history, "Workspace filters do not modify the parent list or browser history.");
  const workspaceFooter = workspace.querySelector('.modal-footer'), sourcePager = workspaceFooter.querySelector('.pagination');
  check(sourcePager && !detailSources.querySelector('.pagination') && !workspaceFooter.querySelector('[type="submit"]'), 'Source pagination appears once in the fixed footer and replaces account save actions.');
  const sourcePageLink = sourcePager.querySelector('a');
  check(sourcePageLink.dataset.ajaxTarget === '#bot-detail-sources-list' && !sourcePageLink.dataset.history, 'Moving pagination preserves its AJAX list target and disables page history.');
  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'posts' } });
  await new Promise(resolve => setImmediate(resolve));
  check(workspaceCalls[1] === '/api/bots/accounts/3/posts?scope=modal' && detailPosts.querySelector('.admin-search-form').dataset.ajaxTarget === '#bot-detail-posts-list', "Posts are loaded lazily with independent modal scope.");
  check(detailDraft.value === 'Unsaved account draft' && workspace.querySelector('.modal-footer').hidden, "Changing tabs preserves account drafts and hides irrelevant save actions.");
  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'history' } });
  await new Promise(resolve => setImmediate(resolve));
  check(workspaceCalls[2] === '/api/bots/accounts/3/runs' && detailRuns.querySelector('.admin-search-form').dataset.ajaxTarget === '#bot-detail-runs-list' && document.getElementById('bot-detail-runs-filter-modal'), 'History loads lazily with shared search and a dedicated filter dialog.');
  const runSearch = new Node('form'); runSearch.dataset.apiMethod = 'GET';
  const changedHistoryRoots = T.botDetail.result(runSearch, { data: { runs: [], filters: { q: 'failure', status: 'error' }, sources: {}, navigation: { visible: true, from: 11, to: 20, total: 20, previous: { url: '/api/bots/accounts/3/runs?q=failure&status=error&page=1', history_url: false }, next: null, pages: [{ page: 1, current: false, url: '/api/bots/accounts/3/runs?q=failure&status=error&page=1', history_url: false }, { page: 2, current: true }] }, api_url: '/api/bots/accounts/3/runs', history_url: false, list_target: '#bot-detail-runs-list', filter_modal: 'bot-detail-runs-filter-modal', url: '/api/bots/accounts/3/runs?q=failure&status=error&page=2' }, meta: { pagination: { page: 2, per_page: 10 } } }, detailRuns);
  check(changedHistoryRoots.length === 1 && changedHistoryRoots[0] === detailRuns, "Direct workspace GET results report their real changed root for one hydration pass.");
  const historyPager = workspaceFooter.querySelector('.pagination');
  check(historyPager && historyPager !== sourcePager && !detailRuns.querySelector('.pagination') && historyPager.querySelector('a').dataset.ajaxTarget === '#bot-detail-runs-list', 'History filters replace footer pagination without leaving a duplicate in the table body.');
  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'posts' } });
  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'history' } });
  check(workspaceCalls.length === 3 && detailRuns.dataset.listUrl.includes('page=2') && detailRuns.querySelector('.admin-search-form').querySelector('[name="q"]').value === 'failure', 'Returning to history preserves its search, filters and page without reloading another tab.');
  check(workspaceFooter.querySelector('.pagination') === historyPager, 'Returning to a tab restores its own current pagination node.');
  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'sources' } });
  check(workspaceFooter.querySelector('.pagination') === sourcePager, 'Source pagination survives history filtering without acquiring its state.');
  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'posts' } });
  const postResponse = { data: { items: [], total_count: 11, filters: { q: 'post draft', scope: 'modal' }, sources: {}, sorting: {}, navigation: { ...sourceNavigation, next: { url: '/api/bots/accounts/3/posts?q=post+draft&scope=modal&page=2', history_url: false }, pages: [] }, api_url: '/api/bots/accounts/3/posts', history_url: false, list_target: '#bot-detail-posts-list', filter_modal: 'bot-detail-posts-filter-modal', clear_url: '/api/bots/accounts/3/posts', url: '/api/bots/accounts/3/posts?q=post+draft&scope=modal&page=1' }, meta: { pagination: { page: 1, per_page: 10 } } };
  T.bots.posts(detailPosts, postResponse);
  const postPager = workspaceFooter.querySelector('.pagination'), nextPostPage = postPager.querySelector('a');
  nextPostPage.closest = selector => selector === '.pagination' ? postPager : null;
  workspace.querySelector('.modal-body').scrollTop = 400;
  T.bots.result(nextPostPage, { ...postResponse, data: { ...postResponse.data, navigation: { visible: false }, url: '/api/bots/accounts/3/posts?q=post+draft&scope=modal&page=2' } }, detailPosts);
  check(workspaceFooter.hidden && !detailPosts.querySelector('.pagination') && workspace.querySelector('.modal-body').scrollTop === 0, 'A page response refreshes the active footer, hides a single-page result and reveals the start of the new rows.');

  workspaceTabs.listeners['tinycat:tab']({ detail: { name: 'account' } });
  check(!workspace.querySelector('.modal-footer').hidden && workspaceCalls.length === 3, "Returning to the account restores its footer without reloading fields.");
  const savedFooterReplace = workspaceFooter.replaceChildren;
  let inactiveFooterReplacements = 0;
  workspaceFooter.replaceChildren = function (...children) { inactiveFooterReplacements++; return savedFooterReplace.apply(this, children); };
  T.bots.posts(detailPosts, postResponse);
  check(inactiveFooterReplacements === 0 && workspaceFooter.querySelector('[type="submit"]') && detailDraft.value === 'Unsaved account draft', 'Refreshing an inactive list keeps the active account actions mounted and preserves its draft.');
  workspaceFooter.replaceChildren = savedFooterReplace;

  const modalSignals = [];
  T.api.request = (_url, options) => { modalSignals.push(options.signal); return new Promise(() => {}); };
  const sourceChange = new Node('form'); sourceChange.dataset.apiMethod = 'PATCH';
  T.botDetail.result(sourceChange, { data: {} }, detailSources);
  T.botDetail.dispose(workspace);
  check(modalSignals.length === 4 && modalSignals.every(signal => signal.aborted) && !document.getElementById('bot-detail-runs-filter-modal'), "Disposing the workspace cancels pending list and metadata requests and removes the history filter dialog.");
  workspace.remove(); parentFilter.remove();
  const backgroundReads = [], backgroundList = {isConnected:true, dataset:{}};
  document.querySelector = selector => selector === '#bot-accounts-list' ? backgroundList : null;
  T.api.request = url => { backgroundReads.push(url); return new Promise(() => {}); };
  Node.prototype.addEventListener = function (name, callback) { (this.listeners ||= {})[name] = callback; };
  const readOnlyWorkspace = T.bots.modal(new URL('https://tinycat.test/api/bots/accounts/3?panel=detail'), {data:{item:botAccount}}, 'read-only-workspace');
  readOnlyWorkspace.listeners['tinycat:modal-close'](); readOnlyWorkspace.remove();
  check(backgroundReads.length === 0, 'Closing a read-only workspace preserves the background list DOM and its focused opener.');
  const editedWorkspace = T.bots.modal(new URL('https://tinycat.test/api/bots/accounts/3?panel=detail'), {data:{item:botAccount}}, 'edited-workspace');
  editedWorkspace.listeners['tinycat:success']({target:sourceChange});
  editedWorkspace.listeners['tinycat:modal-close'](); editedWorkspace.remove();
  check(backgroundReads.length === 1 && backgroundReads[0] === '/api/bots/accounts' + window.location.search, 'Closing a changed workspace still refreshes the filtered background list.');
  Node.prototype.addEventListener = detailEvents;
  T.api.request = savedRequest; document.querySelector = savedQuery; T.lifecycle.hydrate = savedHydrate;
  check(!T.settings.hydrate && !document.getElementById('modal-settings-editor'), 'Settings do not create a modal during initial hydration.');
  const savedResourceOpen = T.dialog.openResource;
  let settingsOpenOptions;
  T.dialog.openResource = (id,url,options) => { settingsOpenOptions = {id,url,options}; return Promise.resolve(null); };
  await T.settings.open('email');
  check(settingsOpenOptions.url === '/api/settings/editor' && settingsOpenOptions.options.tab === 'email', 'Settings delegate resource loading and section selection to the shared dialog service.');
  T.dialog.openResource = savedResourceOpen;
  const savedSettingsListener = Node.prototype.addEventListener;
  Node.prototype.addEventListener = function (name, callback) { (this.listeners ||= {})[name] = callback; };
  const settingsDialog = T.settings.modal(new URL('/api/settings/editor', window.location.href), {data:{sections:[
    {key:'site',label:'Site',icon:'home',fields:[{key:'site.name',label:'Name',type:'text',value:'Current name'}, {key:'site.meta_description',label:'Description',type:'textarea',value:'Draft'}]},
    {key:'email',label:'Email',icon:'mail',fields:[{key:'email.smtp',label:'SMTP',type:'email_smtp',value:{host:'smtp.example.test',password:''}}]}
  ]}}, 'modal-settings-editor');
  const settingsForm = settingsDialog.querySelector('form');
  check(settingsDialog.dataset.modalDisposeOnClose === 'true' && settingsDialog.querySelectorAll('[data-tab]').length === 2, 'Settings are disposable resource editors using shared sections.');
  check(settingsDialog.querySelector('.modal-footer').querySelector('[type="submit"]').getAttribute('form') === settingsForm.id && settingsForm.getAttribute('enctype') === 'multipart/form-data', 'Deferred settings keep a shared footer and multipart uploads.');
  check(settingsForm.querySelector('textarea').textContent === 'Draft' && settingsForm.querySelectorAll('input').some(input => input.getAttribute('name') === 'settings[email.smtp][host]' && input.getAttribute('value') === 'smtp.example.test'), 'Deferred settings retain textareas and nested SMTP field names.');
  check(settingsForm.dataset.confirmUnsaved === 'true', 'Deferred settings protect unsaved changes.');
  const savedLogo = new Node('input'); savedLogo.setAttribute('name', 'settings[site.logo_url]'); savedLogo.value = '/old.webp';
  const pendingLogo = new Node('input'); pendingLogo.type = 'file'; pendingLogo.value = 'selected.webp';
  const savedPassword = new Node('input'); savedPassword.type = 'password'; savedPassword.value = 'submitted secret';
  const removeLogo = new Node('input'); removeLogo.setAttribute('name', 'settings_remove[site.logo_url]'); removeLogo.checked = true;
  [savedLogo, pendingLogo, savedPassword, removeLogo].forEach(input => settingsForm.appendChild(input));
  check(T.settings.result(settingsForm, {data:{saved:true,images:{'site.logo_url':'/new.webp'}}}).length === 0 && savedLogo.value === '/new.webp' && !pendingLogo.value && !savedPassword.value && !removeLogo.checked, 'Repeated settings saves retain image paths and clear submitted uploads and secrets.');
  Node.prototype.addEventListener = savedSettingsListener;
  settingsDialog.remove();
  const savedCaptchaRequest = T.api.request, savedCaptcha = T.ui.captcha, savedCaptchaListener = Node.prototype.addEventListener;
  Node.prototype.addEventListener = function (name, callback) { (this.listeners ||= {})[name] = callback; };
  const searchRoot = new Node('form'), searchInput = new Node('input'), searchResults = new Node('div');
  searchResults.setAttribute('data-global-search-results', ''); searchResults.parentElement = searchRoot; searchRoot.appendChild(searchResults);
  searchInput.dataset.globalSearchInput = ''; searchInput.value = 'captcha'; searchRoot.appendChild(searchInput);
  T.ui.captcha = () => {};
  T.api.request = async () => { const error = new Error('Verify search'); error.data = { code: 'captcha_required', message: unsafe, error: { details: { verify_url: 'javascript:alert(1)', captcha: { enabled: true } } } }; throw error; };
  T.search.hydrate({querySelectorAll: () => [searchRoot]});
  searchInput.listeners.focus();
  await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
  const searchChallenge = document.getElementById('global-search-captcha-modal');
  check(searchChallenge && searchChallenge.dataset.modalKind === 'editor' && searchChallenge.dataset.modalSize === 'compact', 'A real search challenge opens through the shared compact editor.');
  check(searchChallenge.querySelector('.text-muted').textContent === unsafe && !searchChallenge.querySelector('img'), 'Untrusted search error messages remain literal text in the challenge dialog.');
  check(searchChallenge.querySelector('form').action === '/api/security/challenges/search', 'Search verification always targets its canonical endpoint and ignores untrusted error URLs.');
  check(searchChallenge.querySelector('.modal-footer').querySelector('[type="submit"]').getAttribute('form') === searchChallenge.querySelector('form').id && !searchChallenge.querySelector('.modal-body').querySelector('[type="submit"]'), 'Search verification uses the same associated footer action as other forms.');
  T.dialog.close(searchChallenge);
  T.api.request = savedCaptchaRequest; T.ui.captcha = savedCaptcha; Node.prototype.addEventListener = savedCaptchaListener;
  console.log(`PASS domain JSON renderers and request state (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
