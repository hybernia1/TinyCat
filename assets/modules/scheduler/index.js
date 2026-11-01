import "../../core/modules.js";
import "./controller.js";
import "../../ui/list-navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("scheduler", TinyCat.scheduler);
  TinyCat.lists.register("/admin/cron/list", {
    target: "#scheduler-list", keepQuery: ["sort", "direction"],
    url: function () { return "/api/scheduler/tasks" + window.location.search; },
    apply: function (target, response) { TinyCat.scheduler.list(target, response); }
  });
}());
