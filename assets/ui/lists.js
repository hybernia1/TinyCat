import "../core/navigation.js";
import "../core/dom.js";
import "../core/requests.js";
import "../core/lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat, definitions = new Map();
  function definition(target) {
    return target && Array.from(definitions.values()).find(function (item) { return document.querySelector(item.target) === target; });
  }
  T.lists = {
    register: function (path, item) {
      if (definitions.has(item.target)) { throw new TypeError("Duplicate list: " + item.target); }
      T.navigation.register(path, item);
      definitions.set(item.target, item);
    },
    isRead: function (source) {
      return String(source.dataset.apiMethod || source.dataset.method || source.getAttribute("method") || (source.getAttribute("href") ? "GET" : "POST")).toUpperCase() === "GET";
    },
    url: function (url, target) {
      if (!url || !target) { return url; }
      var item = definition(target);
      if (!item) { return url; }
      var next = new URL(url, window.location.href), current = new URL(window.location.href);
      (item.keepQuery || []).forEach(function (key) {
        if (!next.searchParams.has(key) && current.searchParams.has(key)) { next.searchParams.set(key, current.searchParams.get(key)); }
      });
      return T.navigation.compact(next);
    },
    refresh: async function (target) {
      var item = definition(target);
      if (!item || target.isConnected === false) { return false; }
      var url = item.url(), page = window.location.pathname + window.location.search;
      var request = T.requests.begin(target);
      function current() { return request.current() && item.url() === url && page === window.location.pathname + window.location.search; }
      try {
        var response = await T.api.request(url, { method: "GET", cache: "no-store", signal: request.signal });
        if (!current()) { return false; }
        var source = target.contains(document.activeElement) ? document.activeElement : target;
        var filter = T.filters && T.filters.capture(source), menu = T.menus && T.menus.capture(source);
        item.apply(target, response);
        T.lifecycle.hydrate(target);
        if (filter) { T.filters.restore(filter); }
        if (menu) { T.menus.restore(menu); }
        return true;
      } catch (error) {
        if (error.name !== "AbortError" && current()) { T.dom.emit(document, "tinycat:navigation-error", { error: error }); }
        return false;
      } finally { request.finish(); }
    }
  };
}());
