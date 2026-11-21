import "./ajax-bindings.js";
import "./content-updates.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.modules.register("dashboard-stats", {
    result: function (source, response, target) {
      if (!source.matches || !source.matches("[data-dashboard-stats-form]") || !target) return false;
      var data = response.data, view = T.components.render("ui/dashboard-metrics", data);
      T.content.replaceChildren(target, view);
      target.dataset.statsCurrent = data.id;
      source.querySelector("select").value = data.id;
      return [target];
    },
    init: function () {
      document.addEventListener("tinycat:error", function (event) {
        var form = event.target;
        if (!form.matches || !form.matches("[data-dashboard-stats-form]")) return;
        var target = document.querySelector(form.dataset.ajaxTarget);
        if (target) form.querySelector("select").value = target.dataset.statsCurrent;
      });
    }
  });
}());
