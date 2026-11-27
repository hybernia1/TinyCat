import "../../../../Core/Ui/assets/document-navigation.js";
import "../../../../Core/Ui/assets/runtime/page-navigation.js";
import "../../../../Core/Ui/assets/runtime/api-client.js";
import "../../../../Core/Ui/assets/runtime/component-renderer.js";
import "../../../../Core/Ui/assets/runtime/lifecycle.js";
import "../../../../Core/Ui/assets/dialogs.js";
(function () {
  var T = window.TinyCat, sidebar = null;
  function supported(url) {
    if (url.origin !== window.location.origin || url.hash || !document.querySelector("[data-public-page]")) { return false; }
    var parts = T.urls.split(url.pathname), root = document.querySelector("[data-public-page]");
    if (parts.locale !== T.urls.prefix()) { return false; }
    var routes = JSON.parse(root.dataset.publicRoutes || "[]");
    return routes.some(function (route) {
      return new RegExp(route.pattern, "u").test(parts.path) && Array.from(url.searchParams.keys()).every(function (key) { return route.query.includes(key); });
    });
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
    if (searchInput) { searchInput.value = ""; }
    if (T.search && T.search.close) { document.querySelectorAll("[data-global-search]").forEach(T.search.close); }
    else { document.querySelectorAll("[data-global-search-results]").forEach(function (node) { node.hidden = true; }); }
    document.querySelectorAll("[data-language-link]").forEach(function (node) { node.href = T.urls.local(data.url, node.dataset.languageLink); });
    document.querySelectorAll(".navbar a[aria-current]").forEach(function (node) { node.removeAttribute("aria-current"); });
    document.querySelectorAll(".navbar a[href]").forEach(function (node) { if (new URL(node.href).pathname === new URL(data.url, window.location.href).pathname) { node.setAttribute("aria-current", "page"); } });
    var main = document.getElementById("main-content"); main.focus({ preventScroll: true });
  }
  T.navigation.registerPage({ accepts: supported, target: "#main-content > .container", apply: apply, load: async function (url, signal) {
    var request = new URL("/api/social/pages", window.location.href); request.searchParams.set("path", url.pathname);
    url.searchParams.forEach(function (value, key) { request.searchParams.set(key, value); });
    var response = await T.api.request(request.href, { signal: signal, cache: "no-store" });
    return response.data;
  } });
}());
