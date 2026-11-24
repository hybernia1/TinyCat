import "./controller.js";
import "../../Ui/assets/popovers.js";
(function () {
  "use strict";
  var T = window.TinyCat, pollingBindings = new WeakMap(), dispose = T.notifications.dispose;
  function qsa(selector, root) { return Array.from((root || document).querySelectorAll(selector)); }
  T.notifications.dispose = function (root) {
    dispose(root);
    var buttons = qsa("[data-notification-button]", root);
    if (root.matches && root.matches("[data-notification-button]")) { buttons.push(root); }
    buttons.forEach(function (button) { var stop = pollingBindings.get(button); if (stop) { stop(); } });
  };
  T.notifications.init = function () {
    qsa("[data-notification-button]").forEach(function (button) {
      var api;
      var interval;
      var latestId;
      var unread;
      var first = true;
      var polling = false;
      var previewRequested = false, timer = null, previewObserver = null, disposed = false;
      var popover = button.closest ? button.closest("[data-popover]") : null;

      if (button.dataset.notificationReady === "true") {
        return;
      }

      api = button.dataset.notificationApi || "/api/notifications";
      interval = Math.max(3000, parseInt(button.dataset.notificationInterval || "5000", 10) || 5000);
      latestId = Math.max(0, parseInt(button.dataset.notificationLatestId || "0", 10) || 0);
      unread = Math.max(0, parseInt(button.dataset.notificationUnread || "0", 10) || 0);
      button.dataset.notificationReady = "true";
      T.notifications.update({
        unread: unread,
        latest_id: latestId
      });

      function isOpen() { return popover && (popover.open || popover.dataset.popoverOpen === "true"); }
      function stop() {
        if (disposed) { return; }
        disposed = true;
        window.clearInterval(timer);
        document.removeEventListener("visibilitychange", onVisibility);
        if (previewObserver) { previewObserver.disconnect(); }
        pollingBindings.delete(button);
        delete button.dataset.notificationReady;
      }
      function onVisibility() {
        if (button.isConnected === false) { stop(); }
        else if (!document.hidden) { poll(); }
      }
      async function poll(forcePreview) {
        var state;
        var nextLatestId;
        var nextUnread;
        var message;

        if (disposed) { return; }
        if (button.isConnected === false) { stop(); return; }
        if (forcePreview === true) { previewRequested = true; }
        if (document.hidden || polling) {
          return;
        }

        polling = true;
        var requestRevision = T.notifications.revision();
        latestId = Math.max(0, parseInt(button.dataset.notificationLatestId || "0", 10) || 0);
        unread = Math.max(0, parseInt(button.dataset.notificationUnread || "0", 10) || 0);
        var preview = Boolean(previewRequested || isOpen());
        previewRequested = false;

        try {
          state = await T.api.request(api + (preview ? "/preview" : "/state"), {
            method: "GET",
            cache: "no-store"
          });
          if (requestRevision !== T.notifications.revision() || button.isConnected === false) { return; }
          state = state.data || {};
          nextLatestId = Math.max(0, parseInt((state && state.latest_id) || "0", 10) || 0);
          nextUnread = Math.max(0, parseInt((state && (state.unread_count !== undefined ? state.unread_count : state.unread)) || "0", 10) || 0);
          if (!preview && (previewRequested || isOpen() || nextLatestId !== latestId || nextUnread !== unread)) {
            previewRequested = false;
            var refreshed = await T.api.request(api + "/preview", { method: "GET", cache: "no-store" });
            if (requestRevision !== T.notifications.revision() || button.isConnected === false) { return; }
            state = refreshed.data || {};
            nextLatestId = Math.max(0, parseInt(state.latest_id || "0", 10) || 0);
            nextUnread = Math.max(0, parseInt((state.unread_count !== undefined ? state.unread_count : state.unread) || "0", 10) || 0);
          }
          message = T.i18n.plural("notifications.new_count", nextUnread);

          if (!first && message && nextUnread > 0 && (nextLatestId > latestId || nextUnread > unread)) {
            T.toasts.show(message, "info", 4200);
          }

          T.notifications.update(state || {});
          latestId = nextLatestId;
          unread = nextUnread;
          first = false;
        } catch (_error) {
          first = false;
        } finally {
          polling = false;
          if (previewRequested && isOpen() && !document.hidden && button.isConnected !== false) { poll(true); }
        }
      }

      timer = window.setInterval(poll, interval);
      document.addEventListener("visibilitychange", onVisibility);
      if (popover && window.MutationObserver) {
        previewObserver = new window.MutationObserver(function () { if (isOpen()) { poll(true); } });
        previewObserver.observe(popover, { attributes: true, attributeFilter: ["open", "data-popover-open"] });
      }
      pollingBindings.set(button, stop);
    });
  };

}());
