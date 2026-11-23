import "./lifecycle.js";
import "./modules.js";
(function () {
  "use strict";
  var T = window.TinyCat, descriptor = T.deferredAssets || {}, pending = null, loaded = false, files = new Map();
  function asset(url, style) {
    if (files.has(url)) return files.get(url);
    var address = new URL(url, window.location.href);
    if (address.origin !== window.location.origin || !/^\/(?:assets\/build|cache\/assets)\//.test(address.pathname)) throw new TypeError("Invalid deferred asset URL.");
    var request = new Promise(function (resolve, reject) {
      var node = document.createElement(style ? "link" : "script");
      if (style) { node.rel = "stylesheet"; node.href = address.href; }
      else { node.src = address.href; node.async = false; }
      node.onload = resolve;
      node.onerror = function () { files.delete(url); node.remove(); reject(new Error("Could not load editor assets.")); };
      document.head.appendChild(node);
    });
    files.set(url, request);
    return request;
  }
  function load() {
    if (loaded || !descriptor.script) return Promise.resolve();
    if (pending) return pending;
    pending = Promise.all([descriptor.style ? asset(descriptor.style, true) : Promise.resolve(), asset(descriptor.script, false)]).then(function () {
      loaded = true;
      T.modules.init();
      T.lifecycle.hydrate(document);
    }).finally(function () { pending = null; });
    return pending;
  }
  T.deferred = {
    register: function (value) { descriptor = value || {}; },
    resource: function (url) {
      return (descriptor.resources || []).some(function (prefix) { return url.pathname === prefix || url.pathname.startsWith(prefix.endsWith("/") ? prefix : prefix + "/"); }) ? load() : Promise.resolve();
    },
    hydrate: function (root) {
      if (loaded || !(descriptor.selectors || []).some(function (selector) { return (root.matches && root.matches(selector)) || root.querySelector(selector); })) return;
      load().catch(function (error) { if (window.reportError) window.reportError(error); else console.error(error); });
    }
  };
  T.lifecycle.register("deferred-assets", { hydrate: T.deferred.hydrate });
}());
