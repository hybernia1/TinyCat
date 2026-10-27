import "../../core/dom.js";
import "../../core/http.js";
import "../../core/lifecycle.js";
import "./views.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var hydrateDynamic = TinyCat.lifecycle.hydrate;
  var qsa = TinyCat.dom.all;
  var unwrapResult = TinyCat.api.unwrap;
  var cached = null, updated = 0, loading = null, refreshTimer = null;
  function loadSidebar() {
    if (cached && Date.now() - updated < 300000) { return Promise.resolve(cached); }
    if (!loading) {
      loading = TinyCat.api.request("/api/site/sidebar", { method: "GET", cache: "no-store" }).then(function (response) {
        cached = response; updated = Date.now(); return response;
      }).finally(function () { loading = null; });
    }
    return loading;
  }
  TinyCat.site.activateSidebar = function (sidebar, tag) {
    sidebar.querySelectorAll(".topic-link").forEach(function (link) {
      var active = decodeURIComponent(TinyCat.urls.split(new URL(link.href, window.location.href).pathname).path) === "/tag/" + tag;
      link.classList.toggle("is-active", active);
      if (active) { link.setAttribute("aria-current", "page"); } else { link.removeAttribute("aria-current"); }
    });
  };
  TinyCat.site.refresh = function (scope) {
    var desktopMedia = window.matchMedia ? window.matchMedia("(min-width: 900px)") : null;
    var desktop = desktopMedia && desktopMedia.matches;

    if (desktop && refreshTimer === null) {
      refreshTimer = window.setInterval(function () { if (!document.hidden) { TinyCat.site.refresh(); } }, 60000);
    } else if (!desktop && refreshTimer !== null) {
      window.clearInterval(refreshTimer);
      refreshTimer = null;
    }

    function publicSidebarSlot(url) {
      var slot = document.createElement("template");

      slot.dataset.publicSidebarSlot = "";
      slot.dataset.sidebarUrl = url;

      return slot;
    }

    function unloadPublicSidebars() {
      qsa("[data-public-sidebar-url]", document).forEach(function (sidebar) {
        if (TinyCat.__stickySidebarObserver) {
          TinyCat.__stickySidebarObserver.unobserve(sidebar);
        }

        sidebar.replaceWith(publicSidebarSlot(sidebar.dataset.publicSidebarUrl || ""));
      });
    }

    if (TinyCat.__publicSidebarViewportBound !== true && desktopMedia) {
      TinyCat.__publicSidebarViewportBound = true;

      if (typeof desktopMedia.addEventListener === "function") {
        desktopMedia.addEventListener("change", function (event) {
          if (event.matches) {
            TinyCat.site.refresh();
          } else {
            unloadPublicSidebars();
            TinyCat.site.refresh();
          }
        });
      } else if (typeof desktopMedia.addListener === "function") {
        desktopMedia.addListener(function (event) {
          if (event.matches) {
            TinyCat.site.refresh();
          } else {
            unloadPublicSidebars();
            TinyCat.site.refresh();
          }
        });
      }
    }

    if (!desktop) {
      return;
    }

    var candidates = qsa("[data-public-sidebar-slot][data-sidebar-url]", scope || document);
    if (Date.now() - updated >= 300000) { candidates = candidates.concat(qsa("[data-public-sidebar-url]", scope || document)); }
    candidates.forEach(function (sidebar) {
      var url = sidebar.dataset.sidebarUrl || sidebar.dataset.publicSidebarUrl || "";

      if (!url || sidebar.dataset.sidebarLoading === "true") {
        return;
      }

      sidebar.dataset.sidebarLoading = "true";
      sidebar.classList.add("is-loading");

      loadSidebar()
        .then(function (data) {
          if (!desktopMedia.matches || !sidebar.isConnected) {
            return;
          }

          var payload = unwrapResult(data) || {};
          var next = TinyCat.site.sidebar(payload, url);
          if (sidebar.hasAttribute("data-public-sidebar-url")) {
            sidebar.replaceChildren(...Array.from(next.childNodes)); hydrateDynamic(sidebar);
          } else { sidebar.replaceWith(next); hydrateDynamic(next); }
        })
        .catch(function () {
          // Keep the current sidebar when a background refresh fails.
        })
        .finally(function () {
          sidebar.classList.remove("is-loading");
          delete sidebar.dataset.sidebarLoading;
        });
    });
  };
    TinyCat.site.hydrate = TinyCat.site.refresh;
    TinyCat.lifecycle.register("sidebar-freshness", { init: function () {
      document.addEventListener("tinycat:success", function (event) {
        var source = event.target, action = source && (source.getAttribute("action") || source.getAttribute("href") || "");
        if (/^\/api\/(?:statuses|users\/[0-9]+\/followers)/.test(action)) { updated = 0; TinyCat.site.refresh(); }
      });
    } });
}());
