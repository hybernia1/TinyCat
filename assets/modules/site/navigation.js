import "../../core/navigation.js";
import "../../core/http.js";
import "../../core/components.js";
import "../../core/lifecycle.js";
import "../../ui/dialogs.js";
(function () {
  var T = window.TinyCat, pending = null, currentIndex = 0, rollback = false, sidebar = null;
  function supported(url) {
    if (url.origin !== window.location.origin || url.hash || !document.querySelector("[data-public-page]")) { return false; }
    var parts = T.urls.split(url.pathname);
    if (parts.locale !== T.urls.prefix() || !(parts.path === "/" || /^\/(?:tag\/[^/]+|author\/[1-9][0-9]*|status\/[1-9][0-9]*)$/.test(parts.path))) { return false; }
    return Array.from(url.searchParams.keys()).every(function (key) { return key === "feed" || key === "compact"; });
  }
  function saveScroll() {
    if (!pending && supported(new URL(window.location.href))) {
      window.history.replaceState(Object.assign({}, window.history.state, { publicIndex: currentIndex, scroll: [window.scrollX, window.scrollY] }), "");
    }
  }
  function meta(selector, attribute, value) {
    var node = document.head.querySelector(selector);
    if (node) { node.setAttribute(attribute, value || ""); }
  }
  function metadata(data) {
    var doc = data.document;
    document.title = doc.title;
    meta('link[rel="canonical"]', "href", doc.url);
    var values = { description: doc.description, robots: doc.robots || "index,follow", "twitter:title": doc.title, "twitter:description": doc.description, "twitter:card": doc.image ? "summary_large_image" : "summary", "twitter:image": doc.image, "twitter:image:alt": doc.image_alt };
    Object.keys(values).forEach(function (name) {
      var selector = 'meta[name="' + name + '"]', node = document.head.querySelector(selector);
      if (!node) { node = document.createElement("meta"); node.name = name; document.head.appendChild(node); }
      node.content = values[name] || "";
    });
    var properties = { "og:title": doc.title, "og:description": doc.description, "og:url": doc.url, "og:type": doc.type, "og:image": doc.image, "og:image:type": doc.image_type, "og:image:width": doc.image_width || "", "og:image:height": doc.image_height || "", "og:image:alt": doc.image_alt, "article:published_time": doc.published_time, "article:author": doc.author };
    Object.keys(properties).forEach(function (name) {
      var node = document.head.querySelector('meta[property="' + name + '"]');
      if (!properties[name]) { if (node) { node.remove(); } return; }
      if (!node) { node = document.createElement("meta"); node.setAttribute("property", name); document.head.appendChild(node); }
      node.content = properties[name];
    });
    document.head.querySelectorAll('link[rel="alternate"][type="application/rss+xml"], link[rel="prev"], link[rel="next"], script[type="application/ld+json"]').forEach(function (node) { node.remove(); });
    if (doc.rss) { var link = document.createElement("link"); link.rel = "alternate"; link.type = "application/rss+xml"; link.href = doc.rss; link.title = doc.title; document.head.appendChild(link); }
    if (doc.jsonld) { var schema = document.createElement("script"); schema.type = "application/ld+json"; schema.textContent = JSON.stringify(doc.jsonld); document.head.appendChild(schema); }
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
    target.replaceChildren(view); metadata(data); T.lifecycle.hydrate(target);
    document.querySelectorAll("[data-language-link]").forEach(function (node) { node.href = T.urls.local(data.url, node.dataset.languageLink); });
    document.querySelectorAll(".navbar a[aria-current]").forEach(function (node) { node.removeAttribute("aria-current"); });
    document.querySelectorAll(".navbar a[href]").forEach(function (node) { if (new URL(node.href).pathname === new URL(data.url, window.location.href).pathname) { node.setAttribute("aria-current", "page"); } });
    var main = document.getElementById("main-content"); main.focus({ preventScroll: true });
  }
  async function visit(url, restored) {
    if (pending) { pending.abort(); }
    if (!restored) { saveScroll(); }
    var target = document.querySelector("#main-content > .container"), controller = new AbortController(); pending = controller;
    target.setAttribute("aria-busy", "true"); target.dataset.navigationBusy = "true";
    var request = new URL("/api/site/pages", window.location.href); request.searchParams.set("path", url.pathname);
    url.searchParams.forEach(function (value, key) { request.searchParams.set(key, value); });
    try {
      var response = await T.api.request(request.href, { signal: controller.signal, cache: "no-store" });
      if (pending !== controller) { return; }
      var next = new URL(response.data.url, window.location.href);
      if (!restored) { currentIndex++; window.history.pushState({ publicIndex: currentIndex, scroll: [0, 0] }, "", next.pathname + next.search); }
      apply(target, response.data);
      var scroll = restored && restored.scroll || [0, 0];
      window.scrollTo(scroll[0], scroll[1]);
    } catch (error) {
      if (error.name !== "AbortError" && pending === controller) { window.location.assign(url.href); }
    } finally {
      if (pending === controller) { pending = null; target.removeAttribute("aria-busy"); delete target.dataset.navigationBusy; }
    }
  }
  T.navigation.registerPage({ accepts: supported, visit: visit, restore: async function (event) {
    if (rollback) { rollback = false; return; }
    var state = event && event.state || window.history.state || {};
    if (!(await T.navigation.canLeave())) {
      rollback = true; window.history.go(currentIndex - (state.publicIndex || 0)); return;
    }
    currentIndex = state.publicIndex || 0;
    return visit(new URL(window.location.href), state);
  } });
  T.lifecycle.register("public-pages", { init: function () {
    if (!document.querySelector("[data-public-page]")) { return; }
    window.history.scrollRestoration = "manual"; saveScroll();
    window.addEventListener("scroll", saveScroll, { passive: true });
    document.addEventListener("click", function (event) {
      var link = event.target.closest && event.target.closest("a[href]");
      if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !link || link.target || link.hasAttribute("download") || link.hasAttribute("data-ajax") || link.hasAttribute("data-modal-open")) { return; }
      var url = new URL(link.href, window.location.href);
      if (!supported(url)) { return; }
      event.preventDefault(); T.navigation.go(url.href);
    }, true);
  } });
}());
