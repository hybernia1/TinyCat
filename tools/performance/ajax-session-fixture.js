(function () {
  "use strict";
  try {
    const T = window.TinyCat, fixture = window.__fixture, requests = [], failures = [], times = [], hydration = [], visibility = [];
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    const original = T.api.request, hydrate = T.lifecycle.hydrate;
    T.api.request = async function (url, options) {
      const entry = { url: String(url), at: performance.now(), epoch: Date.now() }, start = performance.now(); requests.push(entry);
      try { const result = await original(url, { ...options, headers: { ...(options && options.headers), "X-Bench-Session": fixture.variant + ":" + fixture.round } }); entry.ok = true; return result; }
      catch (error) { entry.aborted = error.name === "AbortError"; if (!entry.aborted) failures.push(error.message); throw error; }
      finally { entry.ms = performance.now() - start; }
    };
    T.lifecycle.hydrate = function (root) { const start = performance.now(); hydrate(root); hydration.push({ ms: performance.now() - start, document: root === document }); };
    const container = document.querySelector("#main-content > .container");
    container.replaceChildren(T.components.render("site/public-page", fixture.initial));
    T.modules.register("benchmark-editor", { modal(url) {
      if (!url.pathname.startsWith("/api/benchmark/editor")) return null;
      const form = T.ui.form("/api/benchmark/save", "PATCH"); form.append(T.dom.element("textarea", { name: "bio" }, "Saved"));
      form.append(T.dom.element("button", { type: "submit" }, "Save"));
      return T.ui.formDialog("benchmark-modal", "account.profile_settings", form, false);
    } });
    T.lifecycle.register("modules", { init: T.modules.init, hydrate: T.modules.hydrate, hydrateOrder: -10, dispose: T.modules.dispose });
    T.lifecycle.start();
    function check(value, message) { if (!value) failures.push(message); }
    window.runSession = async function () {
      await pause(200);
      for (let i = 0; i < 50; i++) {
        const url = i % 3 === 0 ? "/author/2" : i % 3 === 1 ? "/status/10" : "/";
        const start = performance.now(); await T.navigation.go(url); await new Promise(requestAnimationFrame); times.push(performance.now() - start); await pause(35);
        check(location.pathname === url, "Navigation " + i + " failed");
      }
      for (let i = 0; i < 50; i++) {
        await T.dialog.openResource("benchmark-modal", "/api/benchmark/editor/1");
        check(!!T.dialog.current(), "Modal did not open"); T.dialog.close(T.dialog.current());
        for (let n = 0; n < 100 && location.hash; n++) await pause(5);
        await pause(5); check(!T.dialog.current(), "Modal did not close");
      }
      const visits = [];
      for (let i = 0; i < 20; i++) { visits.push(T.navigation.go("/?media=" + (i % 2 ? "video" : "image"))); await pause(2); }
      await Promise.all(visits); await pause(100);
      check(location.search === "?media=video", "Latest filter did not win");
      const input = document.querySelector("[data-global-search-input]");
      for (let i = 0; i < 40; i++) { input.value = "query" + i; input.dispatchEvent(new Event("focus")); await pause(25); }
      input.value = ""; input.dispatchEvent(new Event("input")); await pause(250);
      const feed = document.querySelector("[data-status-feed]"); let prune = null;
      if (feed) {
        const items = Array.from({ length: 150 }, (_, i) => ({ ...fixture.post, id: 1000 + i, url: "/status/" + (1000 + i) }));
        T.lifecycle.dispose(feed); feed.replaceChildren(T.status.fragment(items).content); T.lifecycle.hydrate(feed);
        window.scrollTo(0, document.body.scrollHeight); await new Promise(requestAnimationFrame);
        const anchor = feed.lastElementChild, before = anchor.getBoundingClientRect().top;
        T.status.controllers.feed.pruneStatusFeed(feed, "/api/statuses");
        prune = { remaining: T.status.controllers.feed.statusFeedCards(feed).length, anchor_delta: anchor.getBoundingClientRect().top - before };
        check(prune.remaining <= 120, "Feed did not prune"); check(Math.abs(prune.anchor_delta) <= 2, "Feed anchor jumped");
      }
      await T.navigation.go("/"); await pause(100);
      return { failures: [...failures], navigation_ms: times, requests: [...requests], hydration: [...hydration], search_cache_size: document.querySelector("[data-global-search]").__tinycatSearchCache instanceof Map ? document.querySelector("[data-global-search]").__tinycatSearchCache.size : Object.keys(document.querySelector("[data-global-search]").__tinycatSearchCache || {}).length, prune };
    };
    window.resetTraces = function () { requests.length = 0; hydration.length = 0; times.length = 0; };
    window.beginPollingPhase = function () {
      window.setFixtureHidden(false);
      window.resetTraces();
      const root = document.createElement("div"); root.id = "scheduler-list"; document.body.append(root);
      T.scheduler.list(root, { data: fixture.scheduler, meta: { pagination: {} } }); T.lifecycle.hydrate(root);
    };
    window.setFixtureHidden = function (hidden) { const previous = document.hidden; Object.defineProperty(document, "hidden", { configurable: true, value: hidden }); if (previous !== hidden) { visibility.push({ at: Date.now(), hidden }); document.dispatchEvent(new Event("visibilitychange")); } };
    window.sampleSession = function () { return { requests: requests.splice(0), failures: [...failures], visibility: [...visibility], live_nodes: document.querySelectorAll("*").length, modals: document.querySelectorAll(".modal").length }; };
    window.__ready = true;
  } catch (error) { window.__fixtureError = error.stack || String(error); }
}());
