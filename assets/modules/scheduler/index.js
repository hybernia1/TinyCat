import "../../core/modules.js";
import "./views.js";
import "../../core/navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("scheduler", TinyCat.scheduler);
  TinyCat.navigation.register("/admin/cron/list", {
    target: "#scheduler-list",
    url: function () { return "/api/scheduler/tasks" + window.location.search; },
    apply: function (target, response) { TinyCat.scheduler.list(target, response); }
  });
}());
