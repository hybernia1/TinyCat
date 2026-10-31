import "../core/navigation.js";
import "../core/lifecycle.js";
import "./tabs.js";
(function () {
  "use strict";
  var T = window.TinyCat, frames = [], owner = null, position = 0, revision = 0;
  var ready = false, started = false, settling = false, queued = false, applying = false;
  function state() { return window.history.state || {}; }
  function selected(modal) {
    var tab = modal.querySelector('[data-tab][aria-selected="true"]');
    return tab ? tab.dataset.tab : "";
  }
  function descriptor(modal) {
    if (modal.dataset.modalKind === "confirm") return null;
    if (!modal.dataset.modalUrl && modal.dataset.modalDisposeOnClose !== "false") return null;
    return { id: modal.id, url: modal.dataset.modalUrl || "", tab: selected(modal) };
  }
  function address(frame) {
    var params = new URLSearchParams(), resource = frame.url && new URL(frame.url, window.location.href);
    params.set("modal", resource ? resource.pathname.slice(5) : frame.id);
    if (resource) resource.searchParams.forEach(function (value, key) { if (key !== "tab") params.append(key, value); });
    if (frame.tab) params.set("tab", frame.tab);
    var url = new URL(owner.base, window.location.href);
    url.hash = params.toString().replace(/%2F/gi, "/");
    return url.pathname + url.search + url.hash;
  }
  function parse() {
    var params = new URLSearchParams(window.location.hash.slice(1)), token = params.get("modal");
    if (!token || token.length > 512 || !/^[a-z][a-z0-9_-]*(?:\/[a-z0-9_-]+)*$/i.test(token)) return null;
    var tab = params.get("tab") || ""; params.delete("modal"); params.delete("tab");
    return { id: token.includes("/") ? "modal-" + token.replace(/\//g, "-") : token,
      url: token.includes("/") ? "/api/" + token + (params.size ? "?" + params : "") : "", tab: tab };
  }
  function write(method) {
    var stack = frames.map(function (frame) { return frame.route; });
    var value = Object.assign({}, owner.state, { tinycatModal: { session: owner.session, base: owner.base, stack: stack } });
    window.history[method](value, "", stack.length ? address(stack[stack.length - 1]) : owner.base);
    position = stack.length;
  }
  function begin(baseUrl) {
    var base = new URL(baseUrl || window.location.href, window.location.href);
    if (base.origin !== window.location.origin) throw new TypeError("A modal must belong to the current origin.");
    if (new URLSearchParams(base.hash.slice(1)).has("modal")) base.hash = "";
    if (baseUrl) T.navigation.pageUrl(base);
    var previous = Object.assign({}, state()); delete previous.tinycatModal;
    previous.scroll = [window.scrollX || 0, window.scrollY || 0];
    owner = { session: String(Date.now()) + ":" + ++revision, base: base.pathname + base.search + base.hash, state: previous };
    write("replaceState");
  }
  function error(reason) { if (reason.name !== "AbortError") T.dom.emit(document, "tinycat:dialog-error", { error: reason }); }
  function same(a, b) { return a.id === b.id && a.url === b.url; }
  function applyTab(modal, name) {
    var tab = Array.from(modal.querySelectorAll("[data-tab]")).find(function (item) { return item.dataset.tab === name; });
    applying = true;
    try { if (tab) T.tabs.activate(tab, false); } finally { applying = false; }
  }
  async function restore(value, origin) {
    origin = origin === undefined ? Number(owner.state.publicIndex || 0) + position : origin;
    var distance = origin - (Number(state().publicIndex || 0) + value.stack.length);
    var serial = ++revision, destination = value.stack.length, common = 0;
    T.dialog.cancelPending();
    var basePage = new URL(owner.base, window.location.href);
    var samePage = T.navigation.pageUrl() === basePage.pathname + basePage.search;
    while (samePage && common < frames.length && common < value.stack.length && same(frames[common].route, value.stack[common])) common++;
    var transient = T.dialog.current();
    if (transient && !frames.some(function (frame) { return frame.modal === transient; })) {
      T.dialog.close(transient, { history: false });
      settling = true; window.history.go(distance); return true;
    }
    while (frames.length > common) {
      var frame = frames[frames.length - 1];
      var accepted = await T.dialog.requestClose(frame.modal, { history: false, current: function () { return serial === revision; } });
      if (serial !== revision) return true;
      if (!accepted) { settling = true; window.history.go(distance); return true; }
      frames.pop();
    }
    if (!samePage) {
      var loaded = await T.navigation.restorePage({ state: owner.state }, basePage);
      if (serial !== revision) return true;
      if (!loaded) { window.location.reload(); return true; }
    }
    position = destination;
    for (var i = 0; i < common; i++) { frames[i].route = value.stack[i]; applyTab(frames[i].modal, frames[i].route.tab); }
    try {
      for (var index = common; index < value.stack.length; index++) {
        var route = value.stack[index], modal;
        if (route.url) {
          modal = await T.dialog.openResource(route.id, route.url, { history: false, tab: route.tab, current: function () { return serial === revision; } });
        } else {
          modal = document.getElementById(route.id);
          if (!modal || !modal.matches(".modal")) throw new Error(T.i18n.t("common.request_failed"));
          applyTab(modal, route.tab); T.dialog.open(modal, { history: false });
        }
        if (serial !== revision || !modal) return true;
        frames.push({ route: route, modal: modal });
      }
      frames.forEach(function (frame) { frame.route.tab = selected(frame.modal); });
      write("replaceState");
    } catch (reason) {
      if (serial === revision && reason.name !== "AbortError") { write("replaceState"); error(reason); }
    }
    return true;
  }
  T.dialogHistory = {
    opened: function (modal, options) {
      if (!ready) return;
      var route = descriptor(modal);
      if (!route) return;
      if (route.url) {
        var resource = new URL(route.url, window.location.href);
        if (resource.origin !== window.location.origin || !resource.pathname.startsWith("/api/")) return;
      }
      if (!frames.length && !settling) begin(options && options.baseUrl);
      var index = frames.findIndex(function (frame) { return frame.route.id === route.id; });
      if (index >= 0) frames.splice(index);
      frames.push({ route: route, modal: modal });
      if (settling) { queued = true; return; }
      write(index >= 0 ? "replaceState" : "pushState");
    },
    closed: function (modal) {
      if (!ready) return;
      var index = frames.findIndex(function (frame) { return frame.modal === modal; });
      if (index < 0) return;
      frames.splice(index, 1);
      if (settling) { queued = true; return; }
      settling = true;
      window.history.go(frames.length - position);
    },
    restore: function (event) {
      if (!ready) return false;
      var transient = T.dialog.current();
      if (!frames.length && position === 0 && transient && !descriptor(transient)) {
        T.dialog.close(transient, { history: false });
      }
      var value = (event && event.state || state()).tinycatModal;
      if (!value || !Array.isArray(value.stack)) {
        if (!frames.length && position === 0) { owner = null; return false; }
        return (async function () {
          if (!(await T.navigation.canLeave())) {
            settling = true;
            window.history.go(Number(owner.state.publicIndex || 0) + position - Number((event && event.state || {}).publicIndex || 0));
            return true;
          }
          ++revision; T.dialog.cancelPending();
          var modal = T.dialog.current();
          while (modal) { T.dialog.close(modal, { history: false }); modal = T.dialog.current(); }
          frames = []; owner = null; position = 0; return false;
        }());
      }
      if (!frames.length && position === 0 && !settling && !value.stack.length) { owner = null; return false; }
      var origin = Number(owner && owner.state.publicIndex || 0) + position;
      if (!owner || value.session !== owner.session) {
        var previous = Object.assign({}, event && event.state || state()); delete previous.tinycatModal;
        owner = { session: value.session, base: value.base, state: previous };
      }
      if (settling) {
        settling = false; position = value.stack.length;
        if (queued) { queued = false; write("pushState"); return true; }
        if (frames.length === value.stack.length) return true;
      }
      return restore(value, origin);
    },
    leave: function () {
      if (!frames.length) { owner = null; return false; }
      ++revision; T.dialog.cancelPending();
      var modal = T.dialog.current();
      while (modal) { T.dialog.close(modal, { history: false }); modal = T.dialog.current(); }
      frames = []; settling = false; queued = false;
      window.history.replaceState(owner.state, "", owner.base); owner = null; position = 0;
      return true;
    }
  };
  T.lifecycle.register("dialog-history", {
    init: function () {
      if (!window.history || !window.history.pushState) return;
      ready = true; T.navigation.registerOverlay(T.dialogHistory);
      document.addEventListener("tinycat:tab", function (event) {
        if (applying || settling || !frames.length) return;
        var frame = frames[frames.length - 1];
        if (event.target.closest(".modal") !== frame.modal || selected(frame.modal) === frame.route.tab) return;
        frame.route.tab = selected(frame.modal); write("replaceState");
      });
    },
    hydrateOrder: 30,
    hydrate: function (root) {
      if (!ready || started || root !== document) return;
      started = true;
      Promise.resolve().then(function () {
        var route = parse(), saved = state().tinycatModal;
        if (!route) return;
        if (saved && saved.session && saved.base && Array.isArray(saved.stack) && saved.stack.length) {
          var previous = Object.assign({}, state()); delete previous.tinycatModal;
          owner = { session: saved.session, base: saved.base, state: previous };
          position = saved.stack.length;
          restore(saved).catch(error); return;
        }
        begin();
        var stack = [route];
        var value = { session: owner.session, base: owner.base, stack: stack };
        window.history.pushState(Object.assign({}, owner.state, { tinycatModal: value }), "", address(stack[stack.length - 1]));
        restore(value).catch(error);
      });
    }
  });
}());
