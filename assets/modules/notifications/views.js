import "../../core/dates.js";
import "../../core/components.js";
import "../../core/dom.js";
import "../../core/http.js";
import "../../ui/fields.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  var mutationRevision = 0;
  function markItemRead(item) {
    item.classList.remove("is-unread");
    var read = item.querySelector('[data-api-method="PATCH"], [name="_method"][value="PATCH"]');
    if (read) { (read.closest("form") || read).remove(); }
  }
  T.notifications = {
    items: function (items, preview) {
      return T.components.render("notifications/items", { items: items, preview: Boolean(preview) });
    },
    update: function (state) {
      var count = Math.max(0, parseInt(state.unread_count !== undefined ? state.unread_count : (state.unread || 0), 10) || 0);
      document.querySelectorAll("[data-notification-button]").forEach(function (button) {
        button.dataset.notificationUnread = String(count);
        if (state.latest_id !== undefined) { button.dataset.notificationLatestId = String(Math.max(0, parseInt(state.latest_id, 10) || 0)); }
        button.classList.toggle("has-unread", count > 0);
      });
      document.querySelectorAll("[data-notification-count], [data-notification-menu-count]").forEach(function (node) { node.textContent = notificationBadgeText(count); node.hidden = count < 1; });
      document.querySelectorAll('form[action="/api/notifications"]').forEach(function (form) { form.hidden = count < 1; });
      if (state.items) { document.querySelectorAll("[data-notification-list]").forEach(function (node) { node.replaceChildren(T.notifications.items(state.items.slice(0, 6), true)); }); }
    },
    page: function (response, append) {
      var list = document.getElementById("notifications-list");
      if (!list) { return; }
      if (!append) { list.replaceChildren(); }
      var known = new Set(Array.from(list.querySelectorAll("[data-notification-id]")).map(function (node) { return Number(node.dataset.notificationId); }));
      var items = response.data.items.filter(function (item) { return !known.has(item.id); });
      list.appendChild(T.notifications.items(items, false));
      var control = document.querySelector('[data-status-feed-target="#notifications-list"]');
      if (control) { var next = U.pagination(response).next_url; if (next) { control.dataset.statusFeedUrl = next; } else { control.remove(); } }
      T.notifications.update(response.data);
    },
    result: function (source, response) {
      var url = source.dataset.ajaxAction || source.getAttribute("action") || "";
      if (!/^\/api\/notifications(?:\/|$)/.test(url)) { return false; }
      mutationRevision++;
      var data = response.data;
      if (data.id) {
        document.querySelectorAll('[data-notification-id="' + Number(data.id) + '"]').forEach(function (item) {
          if (data.deleted) { item.remove(); } else { markItemRead(item); }
        });
      } else {
        document.querySelectorAll("[data-notification-id].is-unread").forEach(markItemRead);
      }
      T.notifications.update(data); return true;
    }
  };
  function qs(selector, root) { return (root || document).querySelector(selector); }
  function qsa(selector, root) { return Array.from((root || document).querySelectorAll(selector)); }
  function notificationBadgeText(count) {
    count = Math.max(0, parseInt(count || "0", 10) || 0);
    return count > 99 ? "99+" : String(count);
  }

  function setNotificationMenu(menu, open) {
    if (!menu) {
      return;
    }

    var button = qs("[data-notification-button]", menu);
    var popover = qs("[data-notification-popover]", menu);

    if (!button || !popover) {
      return;
    }

    menu.classList.toggle("is-open", open);
    popover.hidden = !open;
    button.setAttribute("aria-expanded", open ? "true" : "false");
  }

  function closeNotificationMenus(except) {
    qsa("[data-notification-menu]").forEach(function (menu) {
      if (except && menu === except) {
        return;
      }

      setNotificationMenu(menu, false);
    });
  }

  T.notifications.init = function () {
    if (T.__notificationMenuEventsBound !== true) {
      T.__notificationMenuEventsBound = true;

      document.addEventListener("click", function (event) {
        var button = event.target.closest && event.target.closest("[data-notification-button]");
        var menu = event.target.closest && event.target.closest("[data-notification-menu]");

        if (button) {
          if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || button.target === "_blank") {
            return;
          }

          event.preventDefault();
          setNotificationMenu(button.closest("[data-notification-menu]"), button.getAttribute("aria-expanded") !== "true");
          return;
        }

        if (!menu) {
          closeNotificationMenus();
        }
      });

      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeNotificationMenus();
        }
      });
    }

    qsa("[data-notification-button]").forEach(function (button) {
      var api;
      var interval;
      var latestId;
      var unread;
      var first = true;
      var polling = false;

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

      async function poll() {
        var state;
        var nextLatestId;
        var nextUnread;
        var message;

        if (document.hidden || polling) {
          return;
        }

        polling = true;
        var requestRevision = mutationRevision;
        latestId = Math.max(0, parseInt(button.dataset.notificationLatestId || "0", 10) || 0);
        unread = Math.max(0, parseInt(button.dataset.notificationUnread || "0", 10) || 0);

        try {
          state = await T.api.request(api, {
            method: "GET",
            cache: "no-store"
          });
          if (requestRevision !== mutationRevision || button.isConnected === false) { return; }
          state = state.data || {};
          nextLatestId = Math.max(0, parseInt((state && state.latest_id) || "0", 10) || 0);
          nextUnread = Math.max(0, parseInt((state && (state.unread_count !== undefined ? state.unread_count : state.unread)) || "0", 10) || 0);
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
        }
      }

      window.setInterval(poll, interval);
      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
          poll();
        }
      });
    });
  };

}());
