(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, e = T.dom.element;
  T.notifications = {
    items: function (items, preview) {
      return T.components.render("notifications/items", { items: items, preview: Boolean(preview) });
    },
    update: function (state) {
      var count = Number(state.unread_count !== undefined ? state.unread_count : (state.unread || 0));
      document.querySelectorAll("[data-notification-count], [data-notification-menu-count]").forEach(function (node) { node.textContent = count > 99 ? "99+" : String(count); node.hidden = count < 1; });
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
      var data = response.data, item = document.querySelector('[data-notification-id="' + Number(data.id) + '"]');
      if (item) {
        if (data.deleted) { item.remove(); } else { item.classList.remove("is-unread"); var read = item.querySelector('[data-api-method="PATCH"], [name="_method"][value="PATCH"]'); if (read) { (read.closest("form") || read).remove(); } }
      } else if (!data.id) { document.querySelectorAll(".notification-item.is-unread").forEach(function (node) { node.classList.remove("is-unread"); }); }
      if (!data.unread_count) { document.querySelectorAll('form[action="/api/notifications"], .notification-item form[data-api-method="PATCH"]').forEach(function (form) { form.remove(); }); }
      T.notifications.update(data); return true;
    }
  };
}());
