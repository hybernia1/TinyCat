import "./runtime/lifecycle.js";
import "./runtime/i18n.js";
(function () {
  "use strict";
  var T = window.TinyCat, pending = new Map(), complete = new WeakSet();
  function inspect(row) {
    if (complete.has(row) || pending.has(row)) return;
    var url = new URL(row.dataset.cacheProbeUrl, window.location.href);
    if (url.origin !== window.location.origin) return;
    var controller = new AbortController(); pending.set(row, controller);
    fetch(url.href, { signal: controller.signal, credentials: "omit", redirect: "error", cache: "no-cache" }).then(async function (response) {
      if (!response.ok) throw new Error("HTTP " + response.status);
      await response.arrayBuffer();
      if (!row.isConnected || controller.signal.aborted) return;
      row.querySelectorAll("[data-cache-header]").forEach(function (node) {
        var encoding = node.dataset.cacheHeader === "content-encoding", value = response.headers.get(node.dataset.cacheHeader);
        if (encoding) {
          var compressed = Boolean(value && value.toLowerCase() !== "identity");
          node.classList.remove("badge-info", "badge-success", "badge-danger"); node.classList.add(compressed ? "badge-success" : "badge-info");
          node.textContent = compressed ? value : T.i18n.t("admin.cache.no_encoding");
        } else { node.textContent = value || "\u2014"; }
      });
      complete.add(row);
    }).catch(function (error) {
      if (error.name === "AbortError" || !row.isConnected) return;
      row.querySelectorAll("[data-cache-header]").forEach(function (node) {
        node.textContent = T.i18n.t("admin.cache.observe_error");
        if (node.dataset.cacheHeader === "content-encoding") { node.classList.remove("badge-info", "badge-success"); node.classList.add("badge-danger"); }
      });
    }).finally(function () { pending.delete(row); });
  }
  T.lifecycle.register("cache-inspection", {
    hydrate: function (root) {
      if (root.matches && root.matches("[data-cache-probe-url]")) inspect(root);
      root.querySelectorAll("[data-cache-probe-url]").forEach(inspect);
    },
    dispose: function (root) { pending.forEach(function (controller, row) { if (root === row || root.contains(row)) { controller.abort(); pending.delete(row); } }); }
  });
}());
