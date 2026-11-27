// Owner-only upgrade of pre-namespace bookmarks and persisted modal history.
// Remove with the legacy admin redirects after the 9.x compatibility window.
(function () {
  "use strict";
  if (!window.history || !window.history.replaceState) { return; }
  var old = /^(?:bots|moderation|statuses|profiles)\//;
  function upgrade(event) {
    var params = new URLSearchParams(String(window.location.hash || "").slice(1)), token = params.get("modal");
    var state = event && event.state || window.history.state, changed = false;
    if (token && old.test(token)) { params.set("modal", "social/" + token); changed = true; }
    if (state && state.tinycatModal && Array.isArray(state.tinycatModal.stack)) {
      state.tinycatModal.stack.forEach(function (frame) {
        var url = new URL(frame.url || "/", window.location.href);
        if (url.origin !== window.location.origin || !old.test(url.pathname.slice(5)) || !url.pathname.startsWith("/api/")) { return; }
        var derivedId = "modal-" + url.pathname.slice(5).replace(/\//g, "-");
        url.pathname = "/api/social/" + url.pathname.slice(5);
        frame.url = url.pathname + url.search;
        if (frame.id === derivedId) frame.id = "modal-" + url.pathname.slice(5).replace(/\//g, "-");
        changed = true;
      });
      var base = new URL(state.tinycatModal.base, window.location.href);
      if (window.location.pathname.startsWith("/admin/social/") && base.pathname.startsWith("/admin/") && !base.pathname.startsWith("/admin/social/")) {
        base.pathname = window.location.pathname; state.tinycatModal.base = base.pathname + base.search; changed = true;
      }
    }
    if (changed) window.history.replaceState(state, "", window.location.pathname + window.location.search + (params.size ? "#" + params.toString().replace(/%2F/gi, "/") : ""));
  }
  upgrade();
  window.addEventListener("popstate", upgrade, true);
  window.addEventListener("hashchange", upgrade, true);
}());
