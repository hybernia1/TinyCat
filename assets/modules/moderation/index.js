import "../../core/modules.js";
import "./views.js";
import "../../ui/lists.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("moderation", TinyCat.moderation);
  TinyCat.lists.register("/admin/moderation/reports", {
    target: "#moderation-reports", keepQuery: ["sort", "direction"], url: function () { return "/api/moderation/reports" + window.location.search; },
    apply: function (target, response) { TinyCat.moderation.reports(target, response.data.items, response.data.sorting, Object.assign({}, response.data, { pagination: ((response.meta || {}).pagination || {}) })); }
  });
}());
