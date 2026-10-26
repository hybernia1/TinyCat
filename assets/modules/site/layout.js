import "../../core/dom.js";
import "../../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.siteLayout = {};
  var qsa = TinyCat.dom.all;
  function stickySidebarNodes(scope) {
    var root = scope || document;
    var selector = ".public-sidebar, .profile-sidebar-stack";
    var nodes = qsa(selector, root);

    if (root.nodeType === 1 && root.matches(selector)) {
      nodes.unshift(root);
    }

    return nodes;
  }
  function refreshStickySidebars(direction) {
    var desktop = window.matchMedia && window.matchMedia("(min-width: 900px)").matches;
    var topOffset = 76;
    var bottomOffset = 16;

    if (direction === "up" || direction === "down") {
      TinyCat.__stickySidebarDirection = direction;
    }

    qsa(".public-sidebar, .profile-sidebar-stack").forEach(function (sidebar) {
      var sidebarHeight;
      var stickyOffset;

      if (!desktop) {
        sidebar.style.removeProperty("--sidebar-sticky-top");
        return;
      }

      sidebarHeight = Math.ceil(sidebar.getBoundingClientRect().height);
      stickyOffset = TinyCat.__stickySidebarDirection === "up"
        ? topOffset
        : Math.min(topOffset, window.innerHeight - sidebarHeight - bottomOffset);
      sidebar.style.setProperty("--sidebar-sticky-top", stickyOffset + "px");
    });
  }
  function scheduleStickySidebarRefresh(direction) {
    if (direction === "up" || direction === "down") {
      TinyCat.__stickySidebarPendingDirection = direction;
    }

    if (TinyCat.__stickySidebarRefreshQueued === true) {
      return;
    }

    TinyCat.__stickySidebarRefreshQueued = true;
    window.requestAnimationFrame(function () {
      var pendingDirection = TinyCat.__stickySidebarPendingDirection;

      TinyCat.__stickySidebarRefreshQueued = false;
      TinyCat.__stickySidebarPendingDirection = "";
      refreshStickySidebars(pendingDirection);
    });
  }
  TinyCat.siteLayout.hydrate = function (scope) {
    var nodes = stickySidebarNodes(scope);

    if (nodes.length === 0) {
      return;
    }

    if (TinyCat.__stickySidebarObserver == null && window.ResizeObserver) {
      TinyCat.__stickySidebarObserver = new ResizeObserver(function () {
        scheduleStickySidebarRefresh();
      });
    }

    nodes.forEach(function (sidebar) {
      if (sidebar.dataset.stickySidebarReady === "true") {
        return;
      }

      sidebar.dataset.stickySidebarReady = "true";

      if (TinyCat.__stickySidebarObserver) {
        TinyCat.__stickySidebarObserver.observe(sidebar);
      }
    });

    if (TinyCat.__stickySidebarEventsBound !== true) {
      TinyCat.__stickySidebarEventsBound = true;
      TinyCat.__stickySidebarDirection = "down";
      TinyCat.__stickySidebarLastScrollY = window.scrollY || 0;
      window.addEventListener("resize", function () {
        scheduleStickySidebarRefresh();
      }, { passive: true });
      window.addEventListener("scroll", function () {
        var nextScrollY = window.scrollY || 0;
        var direction;

        if (nextScrollY === TinyCat.__stickySidebarLastScrollY) {
          return;
        }

        direction = nextScrollY < TinyCat.__stickySidebarLastScrollY ? "up" : "down";
        TinyCat.__stickySidebarLastScrollY = nextScrollY;

        if (direction === TinyCat.__stickySidebarDirection && TinyCat.__stickySidebarRefreshQueued !== true) {
          return;
        }

        scheduleStickySidebarRefresh(direction);
      }, { passive: true });

      if (window.matchMedia) {
        window.matchMedia("(min-width: 900px)").addEventListener("change", function () {
          scheduleStickySidebarRefresh();
        });
      }
    }

    refreshStickySidebars();
  };
  TinyCat.lifecycle.register("site-layout", { hydrate: TinyCat.siteLayout.hydrate });
}());
