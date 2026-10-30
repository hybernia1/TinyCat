import "../../core/dates.js";
import "../../core/components.js";
import "../../ui/fields.js";
import "../../ui/dialogs.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  function reports(root, items, sorting, data) {
    var model = Object.assign({}, data, { items: items, sorting: sorting });
    root.replaceChildren(T.components.render("moderation/reports", Object.assign({}, model, { state: model })));
  }
  T.moderation = {
    hydrate: function (root) {
      Array.from((root || document).querySelectorAll("[data-moderation-reports-view]")).forEach(function (view) {
        if (view.dataset.moderationReportsReady === "true") { return; }
        view.dataset.moderationReportsReady = "true";
        var data = JSON.parse(view.dataset.moderationReportsState || "{}");
        if (!data.filters) { return; }
        var form = T.components.render("moderation/filter-form", data);
        form.querySelectorAll("input, select").forEach(function (field) {
          field.defaultValue = field.value;
          if (field.options) { Array.from(field.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
        });
        T.ui.filterPanel("moderation-filter-modal", form);
      });
    },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
      if (!url.pathname.startsWith("/api/moderation/")) { return false; }
      if (url.pathname.startsWith("/api/moderation/reports") && target) { reports(target, response.data.items, response.data.sorting, Object.assign({}, response.data, { pagination: T.ui.pagination(response) })); }
      return true;
    }, reports: reports
  };
}());
