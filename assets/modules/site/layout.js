import "../../core/dom.js";
import "../../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.siteLayout = {};
  var qsa = TinyCat.dom.all;
  var desktopMedia = window.matchMedia ? window.matchMedia("(min-width: 900px)") : null;
  var viewportBound = false;
  function onSidebarScroll() {
    var nextScrollY = window.scrollY || 0;
    var direction = nextScrollY < TinyCat.__stickySidebarLastScrollY ? "up" : "down";
    if (nextScrollY === TinyCat.__stickySidebarLastScrollY) { return; }
    TinyCat.__stickySidebarLastScrollY = nextScrollY;
    if (direction !== TinyCat.__stickySidebarDirection || TinyCat.__stickySidebarRefreshQueued === true) {
      scheduleStickySidebarRefresh(direction);
    }
  }
  function onSidebarResize() { scheduleStickySidebarRefresh(); }
  function updateSidebarViewport() {
    if (desktopMedia && desktopMedia.matches) {
      TinyCat.siteLayout.hydrate(document);
      return;
    }
    window.removeEventListener("scroll", onSidebarScroll);
    window.removeEventListener("resize", onSidebarResize);
    TinyCat.__stickySidebarEventsBound = false;
    if (TinyCat.__stickySidebarObserver) { TinyCat.__stickySidebarObserver.disconnect(); }
    stickySidebarNodes(document).forEach(function (sidebar) {
      delete sidebar.dataset.stickySidebarReady;
      sidebar.style.removeProperty("--sidebar-sticky-top");
    });
  }
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
    var topOffset = 76;
    var bottomOffset = 16;
    if (!desktopMedia || !desktopMedia.matches) { return; }

    if (direction === "up" || direction === "down") {
      TinyCat.__stickySidebarDirection = direction;
    }

    qsa(".public-sidebar, .profile-sidebar-stack").forEach(function (sidebar) {
      var sidebarHeight;
      var stickyOffset;

      sidebarHeight = Math.ceil(sidebar.getBoundingClientRect().height);
      stickyOffset = TinyCat.__stickySidebarDirection === "up"
        ? topOffset
        : Math.min(topOffset, window.innerHeight - sidebarHeight - bottomOffset);
      sidebar.style.setProperty("--sidebar-sticky-top", stickyOffset + "px");
    });
  }
  function scheduleStickySidebarRefresh(direction) {
    if (!desktopMedia || !desktopMedia.matches) { return; }
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
    if (!viewportBound && desktopMedia) {
      viewportBound = true;
      if (desktopMedia.addEventListener) { desktopMedia.addEventListener("change", updateSidebarViewport); }
      else if (desktopMedia.addListener) { desktopMedia.addListener(updateSidebarViewport); }
    }
    if (!desktopMedia || !desktopMedia.matches) { return; }
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
      window.addEventListener("resize", onSidebarResize, { passive: true });
      window.addEventListener("scroll", onSidebarScroll, { passive: true });
    }

    refreshStickySidebars();
  };
  TinyCat.lifecycle.register("site-layout", { hydrate: TinyCat.siteLayout.hydrate });
}());
