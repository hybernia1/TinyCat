import "./navigation.js";
import "./lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat, provider = null, pendingPage = null, pageIndex = Number((window.history && window.history.state || {}).pageIndex || 0), rollback = false, scrollTimer = null;
  function restoreScroll(state) { var scroll = state && state.scroll || [0, 0]; window.scrollTo(scroll[0], scroll[1]); }
  function saveScroll() {
    window.clearTimeout(scrollTimer); scrollTimer = null;
    if (!pendingPage && provider && provider.accepts(new URL(window.location.href))) {
      window.history.replaceState(Object.assign({}, window.history.state, { pageIndex: pageIndex, scroll: [window.scrollX || 0, window.scrollY || 0] }), "");
    }
  }
  async function visitPage(url, restored, replace) {
    window.clearTimeout(scrollTimer); scrollTimer = null;
    if (pendingPage) { pendingPage.controller.abort(); }
    if (!restored) { saveScroll(); }
    var target = document.querySelector(provider.target);
    if (!target) { window.location[replace ? "replace" : "assign"](url.href); return false; }
    var request = { controller: new AbortController(), target: target, applying: false }; pendingPage = request;
    target.setAttribute("aria-busy", "true"); target.dataset.navigationBusy = "true";
    try {
      var data = await provider.load(url, request.controller.signal);
      if (pendingPage !== request || request.controller.signal.aborted || target.isConnected === false) { return false; }
      var next = new URL(data.url, window.location.href);
      if (next.origin !== window.location.origin || !provider.accepts(next)) { throw new TypeError("Invalid page destination."); }
      request.applying = true;
      try { provider.apply(target, data); } finally { request.applying = false; }
      if (!restored) {
        pageIndex++;
        window.history[replace ? "replaceState" : "pushState"]({ pageIndex: pageIndex, scroll: [0, 0] }, "", next.pathname + next.search);
      } else { pageIndex = Number(restored.pageIndex || 0); }
      T.navigation.pageUrl(next);
      restoreScroll(restored);
      return true;
    } catch (error) {
      if (error.name !== "AbortError" && pendingPage === request && !request.controller.signal.aborted) { window.location[replace ? "replace" : "assign"](url.href); }
      return false;
    } finally {
      if (pendingPage === request) { pendingPage = null; target.removeAttribute("aria-busy"); target.removeAttribute("data-navigation-busy"); }
    }
  }
  T.navigation.createPageRoute = function (route) {
    provider = route;
    return { accepts: route.accepts, visit: visitPage, restore: async function (event, page) {
      if (rollback) { rollback = false; return false; }
      var state = event && event.state || window.history.state || {};
      if (!(await T.navigation.canLeave())) {
        var distance = pageIndex - Number(state.pageIndex || 0);
        if (distance) { rollback = true; window.history.go(distance); }
        return false;
      }
      return visitPage(page, state);
    } };
  };
  var restore = T.navigation.restore;
  T.navigation.restore = async function (event) {
    var result = await restore(event), state = window.history && window.history.state || {}, modal = state.tinycatModal;
    if (provider && !pendingPage && modal && Array.isArray(modal.stack) && !modal.stack.length && T.navigation.pageUrl() === window.location.pathname + window.location.search) { restoreScroll(state); }
    return result;
  };
  T.lifecycle.register("page-navigation", { init: function () {
    if (!provider || !document.querySelector(provider.target)) { return; }
    window.history.scrollRestoration = "manual"; saveScroll();
    window.addEventListener("scroll", function () { window.clearTimeout(scrollTimer); scrollTimer = window.setTimeout(saveScroll, 150); }, { passive: true });
    window.addEventListener("pagehide", saveScroll);
    document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") { saveScroll(); } });
    document.addEventListener("click", function (event) {
      var link = event.target.closest && event.target.closest("a[href]");
      if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !link || link.target || link.hasAttribute("download") || link.hasAttribute("data-ajax") || link.hasAttribute("data-modal-open")) { return; }
      var url = new URL(link.href, window.location.href);
      if (!provider.accepts(url)) { return; }
      event.preventDefault(); T.navigation.go(url.href);
    }, true);
  }, dispose: function (root) {
    if (pendingPage && !pendingPage.applying && (root === pendingPage.target || (root.contains && root.contains(pendingPage.target)))) { pendingPage.controller.abort(); }
  } });
}());
