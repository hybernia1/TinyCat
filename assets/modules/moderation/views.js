import "../../core/dates.js";
import "../../core/components.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  function reports(root, items) { root.replaceChildren(T.components.render("moderation/reports", { items: items })); }
  T.moderation = {
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
      if (!url.pathname.startsWith("/api/moderation/")) { return false; }
      if (url.pathname.startsWith("/api/moderation/reports") && target) { reports(target, response.data.items); }
      if (url.pathname === "/api/moderation/blocked-urls") { var field = source.querySelector('[name="blocked_urls"]'); if (field) { field.value = response.data.value; } }
      return true;
    }, reports: reports
  };
}());
