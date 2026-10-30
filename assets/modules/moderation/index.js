import "../../core/modules.js";
import "./views.js";
import "../../core/navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("moderation", TinyCat.moderation);
  TinyCat.navigation.register("/admin/moderation/reports", {
    target: "#moderation-reports", url: function () { return "/api/moderation/reports" + window.location.search; },
    apply: function (target, response) { TinyCat.moderation.reports(target, response.data.items, response.data.sorting, Object.assign({}, response.data, { pagination: ((response.meta || {}).pagination || {}) })); }
  });
}());
