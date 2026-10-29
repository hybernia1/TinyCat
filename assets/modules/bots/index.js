import "../../core/modules.js";
import "./views.js";
import "../../core/navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("bots", TinyCat.bots);
  TinyCat.navigation.register("/admin/bots/queue", {
    target: "#bot-queue-list", url: function () { return "/api/bots/queue" + window.location.search; },
    apply: function (target, response) { TinyCat.bots.queue(target, response); }
  });
  var owner = document.querySelector("[data-bot-detail-id]");
  if (owner) {
    TinyCat.navigation.register("/admin/bots/" + owner.dataset.botDetailId, {
      target: "#bot-posts-list", url: function () { return "/api/bots/accounts/" + owner.dataset.botDetailId + "/posts" + window.location.search; },
      apply: function (target, response) { TinyCat.bots.posts(target, response); }
    });
  }
  TinyCat.navigation.register("/admin/bots/list", {
    target: "#bots-list", url: function () { return "/api/bots/sources" + window.location.search; },
    apply: function (target, response) { TinyCat.bots.sources(target, response.data); }
  });
  TinyCat.navigation.register("/admin/bots/accounts", {
    target: "#bot-accounts-list",
    url: function () { return "/api/bots/accounts" + window.location.search; },
    apply: function (target, response) { TinyCat.bots.accounts(target, response); }
  });
}());
