import "../../core/modules.js";
import "./controller.js";
import "../../ui/list-navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("bots", TinyCat.bots);
  TinyCat.lists.register("/admin/bots/queue", {
    target: "#bot-queue-list", keepQuery: ["sort", "direction"], url: function () { return "/api/bots/queue" + window.location.search; },
    apply: function (target, response) { TinyCat.bots.queue(target, response); }
  });
  TinyCat.lists.register("/admin/bots/list", {
    target: "#bots-list", keepQuery: ["sort", "direction"], url: function () { return "/api/bots/sources" + window.location.search; },
    apply: function (target, response) { TinyCat.bots.sources(target, Object.assign({}, response.data, { pagination: (response.meta || {}).pagination || {} })); }
  });
  TinyCat.lists.register("/admin/bots/accounts", {
    target: "#bot-accounts-list", keepQuery: ["sort", "direction"],
    url: function () { return "/api/bots/accounts" + window.location.search; },
    apply: function (target, response) { TinyCat.bots.accounts(target, response); }
  });
}());
