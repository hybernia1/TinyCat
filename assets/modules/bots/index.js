import "../../core/modules.js";
import "./views.js";
import "../../core/navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("bots", TinyCat.bots);
  TinyCat.navigation.register("/admin/bots/accounts", {
    target: "#bot-accounts-list",
    url: function () { return "/api/bots/accounts" + window.location.search; },
    apply: function (target, response) { TinyCat.bots.accounts(target, response); }
  });
}());
