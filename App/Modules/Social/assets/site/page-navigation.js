import "../../../../../assets/ui/document-navigation.js";
import "../../../../../assets/core/navigation.js";
import "../../../../../assets/core/api-client.js";
import "../../../../../assets/core/component-renderer.js";
import "../../../../../assets/core/lifecycle.js";
import "../../../../../assets/ui/dialogs.js";
(function () {
  var T = window.TinyCat, pending = null, currentIndex = 0, rollback = false, sidebar = null, scrollSaveTimer = null;
  function supported(url) {
    if (url.origin !== window.location.origin || url.hash || !document.querySelector("[data-public-page]")) { return false; }
    var parts = T.urls.split(url.pathname), path = parts.path, home = path === "/" || path === "/social";
    if (parts.locale !== T.urls.prefix() || !(home || path === "/search" || /^\/(?:tag\/[^/]+|author\/[1-9][0-9]*|status\/[1-9][0-9]*)$/.test(path))) { return false; }
    return Array.from(url.searchParams.keys()).every(function (key) { return path === "/search" ? key === "q" || key === "type" || key === "media" : key === "feed" || key === "compact" || ((home || path.startsWith("/tag/") || path.startsWith("/author/")) && key === "media"); });
  }
  function saveScroll() {
    window.clearTimeout(scrollSaveTimer);
    scrollSaveTimer = null;
    if (!pending && supported(new URL(window.location.href))) {
      window.history.replaceState(Object.assign({}, window.history.state, { publicIndex: currentIndex, scroll: [window.scrollX, window.scrollY] }), "");
    }
  }
  function queueScrollSave() {
    window.clearTimeout(scrollSaveTimer);
    // Keep history writes out of the continuous scrolling path.
    scrollSaveTimer = window.setTimeout(saveScroll, 150);
  }
  function apply(target, data) {
    if (String(data.viewer && data.viewer.id || 0) !== String(document.body.dataset.currentUserId || 0) || data.version !== document.body.dataset.applicationVersion || data.locale !== document.documentElement.lang) { throw new Error("Page shell changed."); }
    var active = T.dialog.current(); while (active) { T.dialog.close(active); active = T.dialog.current(); }
    var existing = target.querySelector("[data-public-sidebar-url]");
    if (existing) { sidebar = existing; sidebar.remove(); }
    T.lifecycle.dispose(target);
    var view = T.components.render("site/public-page", Object.assign({}, data, { csrf: T.api.csrfToken() }));
    var slot = view.querySelector("[data-public-sidebar-slot]");
    if (slot && sidebar && (!window.matchMedia || window.matchMedia("(min-width: 900px)").matches)) { slot.replaceWith(sidebar); sidebar.dataset.publicSidebarUrl = data.sidebar_url; T.site.activateSidebar(sidebar, data.tag); }
    target.replaceChildren(view); T.document.update(data.document); T.lifecycle.hydrate(target);
    var searchInput = document.querySelector("[data-global-search-input]");
    if (searchInput) { searchInput.value = data.kind === "search" ? data.search.query : ""; }
    if (T.search && T.search.close) { document.querySelectorAll("[data-global-search]").forEach(T.search.close); }
    else { document.querySelectorAll("[data-global-search-results]").forEach(function (node) { node.hidden = true; }); }
    document.querySelectorAll("[data-language-link]").forEach(function (node) { node.href = T.urls.local(data.url, node.dataset.languageLink); });
    document.querySelectorAll(".navbar a[aria-current]").forEach(function (node) { node.removeAttribute("aria-current"); });
    document.querySelectorAll(".navbar a[href]").forEach(function (node) { if (new URL(node.href).pathname === new URL(data.url, window.location.href).pathname) { node.setAttribute("aria-current", "page"); } });
    var main = document.getElementById("main-content"); main.focus({ preventScroll: true });
  }
  async function visit(url, restored, replace) {
    window.clearTimeout(scrollSaveTimer);
    scrollSaveTimer = null;
    if (pending) { pending.abort(); }
    if (!restored) { saveScroll(); }
    var target = document.querySelector("#main-content > .container"), controller = new AbortController(); pending = controller;
    target.setAttribute("aria-busy", "true"); target.dataset.navigationBusy = "true";
    var request = new URL("/api/site/pages", window.location.href); request.searchParams.set("path", url.pathname);
    url.searchParams.forEach(function (value, key) { request.searchParams.set(key, value); });
    try {
      var response = await T.api.request(request.href, { signal: controller.signal, cache: "no-store" });
      if (pending !== controller) { return false; }
      var next = new URL(response.data.url, window.location.href);
      if (!restored) { currentIndex++; window.history[replace ? "replaceState" : "pushState"]({ publicIndex: currentIndex, scroll: [0, 0] }, "", next.pathname + next.search); }
      apply(target, response.data);
      var scroll = restored && restored.scroll || [0, 0];
      window.scrollTo(scroll[0], scroll[1]);
      return true;
    } catch (error) {
      if (error.name !== "AbortError" && pending === controller) { window.location[replace ? "replace" : "assign"](url.href); }
      return false;
    } finally {
      if (pending === controller) { pending = null; target.removeAttribute("aria-busy"); delete target.dataset.navigationBusy; }
    }
  }
  T.navigation.registerPage({ accepts: supported, visit: visit, restore: async function (event, url) {
    if (rollback) { rollback = false; return false; }
    var state = event && event.state || window.history.state || {};
    if (!(await T.navigation.canLeave())) {
      rollback = true; window.history.go(currentIndex - (state.publicIndex || 0)); return false;
    }
    currentIndex = state.publicIndex || 0;
    return visit(url || new URL(window.location.href), state);
  } });
  T.lifecycle.register("public-pages", { init: function () {
    if (!document.querySelector("[data-public-page]")) { return; }
    window.history.scrollRestoration = "manual"; saveScroll();
    window.addEventListener("scroll", queueScrollSave, { passive: true });
    window.addEventListener("pagehide", saveScroll);
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "hidden") { saveScroll(); }
    });
    document.addEventListener("submit", function (event) {
      var form = event.target.closest && event.target.closest("form[data-global-search]");
      if (!form || event.defaultPrevented) { return; }
      var input = form.querySelector("[data-global-search-input]");
      if (!input || input.value.trim().length < 2) { return; }
      var url = new URL(form.action, window.location.href);
      url.searchParams.set("q", input.value.trim());
      if (!supported(url)) { return; }
      event.preventDefault(); T.navigation.go(url.href);
    });
    document.addEventListener("click", function (event) {
      var link = event.target.closest && event.target.closest("a[href]");
      if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !link || link.target || link.hasAttribute("download") || link.hasAttribute("data-ajax") || link.hasAttribute("data-modal-open")) { return; }
      var url = new URL(link.href, window.location.href);
      if (!supported(url)) { return; }
      event.preventDefault(); T.navigation.go(url.href);
    }, true);
  } });
}());
