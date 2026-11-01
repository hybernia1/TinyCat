import "./request-lifecycle.js";
import "./dom.js";
import "./api-client.js";
import "./lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat, routes = new Map(), helpUrl = null, pageRoute = null, overlay = null, beforeLeave = function () { return true; };
  var renderedPage = new URL(window.location.href).pathname + new URL(window.location.href).search;
  function urlWithFormData(action, formData, omit) {
    var url = new URL(action || window.location.href, window.location.href);
    var ignored = omit || [];
    var keys = [];

    formData.forEach(function (_value, key) {
      if (keys.indexOf(key) === -1) {
        keys.push(key);
      }
    });

    keys.forEach(function (key) {
      url.searchParams.delete(key);
    });

    formData.forEach(function (value, key) {
      var stringValue = String(value || "");

      if (ignored.indexOf(key) !== -1 || stringValue === "") {
        return;
      }

      url.searchParams.append(key, stringValue);
    });

    return T.navigation.compact(url);
  }
  function historyUrl(source, fallback, formData) {
    var setting = source && source.dataset ? source.dataset.history : "";

    if (!setting || setting === "false") {
      return "";
    }

    if (formData) {
      return urlWithFormData(setting === "true" ? fallback : setting, formData, ["api", "view", "_csrf"]);
    }

    return setting === "true" ? fallback : setting;
  }
  function currentAjaxUrl(trigger) {
    var current = new URL(window.location.href);
    var source = new URL(trigger.dataset.url || trigger.getAttribute("href") || window.location.href, window.location.href);

    source.searchParams.forEach(function (value, key) {
      current.searchParams.set(key, value);
    });

    return T.navigation.compact(current);
  }
  T.navigation = {
    formUrl: urlWithFormData, historyUrl: historyUrl, currentAjaxUrl: currentAjaxUrl,
    compact: function (url) { return url.origin === window.location.origin ? url.pathname + url.search + url.hash : url.toString(); },
    push: function (url) { if (url && window.history && window.history.pushState) { window.history.pushState({}, "", url); var page = new URL(url, window.location.href); renderedPage = page.pathname + page.search; } },
    guard: function (check) { beforeLeave = check; },
    help: function (url) { helpUrl = url; },
    go: async function (url) {
      if (!(await beforeLeave())) return;
      var replace = overlay && overlay.leave();
      if (pageRoute && pageRoute.accepts(new URL(url, window.location.href))) {
        if (await pageRoute.visit(new URL(url, window.location.href), null, replace) === false) return;
        var page = new URL(window.location.href); renderedPage = page.pathname + page.search;
        return;
      }
      window.location[replace ? "replace" : "assign"](url);
    },
    registerOverlay: function (provider) { if (overlay) throw new TypeError("Duplicate navigation overlay provider."); overlay = provider; },
    canLeave: function () { return beforeLeave(); },
    registerPage: function (route) { if (pageRoute) { throw new TypeError("Duplicate page navigation provider."); } pageRoute = route; },
    register: function (path, route) { if (routes.has(path)) { throw new TypeError("Duplicate history route: " + path); } routes.set(path, route); },
    pageUrl: function (url) { if (url) renderedPage = url.pathname + url.search; return renderedPage; },
    restorePage: async function (event, url) {
      var page = new URL(url || window.location.href, window.location.href);
      if (pageRoute && pageRoute.accepts(page)) {
        if (await pageRoute.restore(event, page) === false) return false;
        renderedPage = page.pathname + page.search;
        return true;
      }
      var route = routes.get(page.pathname), target = route && document.querySelector(route.target);
      if (!target) { return false; }
      var request = T.requests.begin(target);
      try {
        var data = await T.api.request(route.url(), { method: "GET", signal: request.signal });
        if (!request.current()) { return; }
        route.apply(target, data); T.lifecycle.hydrate(target); renderedPage = page.pathname + page.search; return true;
      } catch (error) {
        if (error.name !== "AbortError" && request.current()) { T.dom.emit(document, "tinycat:navigation-error", { error: error }); }
      } finally { request.finish(); }
      return false;
    },
    restore: async function (event) {
      var handled = overlay && overlay.restore(event);
      if (handled && await handled) return;
      return T.navigation.restorePage(event);
    }
  };
  T.lifecycle.register("navigation", { init: function () {
    window.addEventListener("popstate", T.navigation.restore);
    document.addEventListener("keydown", function (event) {
      if (!helpUrl || event.key !== "F1" || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) { return; }
      event.preventDefault(); if (window.location.pathname !== helpUrl) { T.navigation.go(helpUrl); }
    }, true);
  } });
}());
