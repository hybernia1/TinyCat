import "./user-menu.js";
import "../../../../assets/core/modules.js";
import "../../../../assets/ui/list-navigation.js";
import "./avatar-editor.js";
import "./controller.js";
import "./email-availability.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var hydrate = TinyCat.accounts.hydrate;
  TinyCat.accounts.hydrate = function (root) { hydrate(root); TinyCat.accounts.avatarUpload.hydrate(root); };
  TinyCat.modules.register("accounts", TinyCat.accounts);
  TinyCat.lists.register("/admin/users", {
    target: "#users-list", keepQuery: ["sort", "direction"], url: function () { return "/api/users" + window.location.search; },
    apply: function (target, response) { TinyCat.accounts.list(target, response); }
  });
}());
