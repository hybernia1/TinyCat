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
  TinyCat.site.refresh = function (scope) {
    var desktopMedia = window.matchMedia ? window.matchMedia("(min-width: 900px)") : null;

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
          }
        });
      } else if (typeof desktopMedia.addListener === "function") {
        desktopMedia.addListener(function (event) {
          if (event.matches) {
            TinyCat.site.refresh();
          } else {
            unloadPublicSidebars();
          }
        });
      }
    }

    if (desktopMedia && !desktopMedia.matches) {
      return;
    }

    qsa("[data-public-sidebar-slot][data-sidebar-url]", scope || document).forEach(function (sidebar) {
      var url = sidebar.dataset.sidebarUrl || "";

      if (!url || sidebar.dataset.sidebarLoading === "true") {
        return;
      }

      sidebar.dataset.sidebarLoading = "true";
      sidebar.classList.add("is-loading");

      TinyCat.api.request(url, { method: "GET", cache: "no-store" })
        .then(function (data) {
          var payload = unwrapResult(data) || {};
          var next = TinyCat.site.sidebar(payload, url);

          if (desktopMedia && !desktopMedia.matches) {
            return;
          }

          sidebar.replaceWith(next);
          hydrateDynamic(next);
        })
        .catch(function () {
          sidebar.classList.remove("is-loading");
        })
        .finally(function () {
          delete sidebar.dataset.sidebarLoading;
        });
    });
  };
    TinyCat.site.hydrate = TinyCat.site.refresh;
}());
