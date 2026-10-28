import "./user-menu.js";
import "../../core/modules.js";
import "../../core/navigation.js";
import "./avatar.js";
import "./views.js";
import "./email-availability.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var hydrate = TinyCat.users.hydrate;
  TinyCat.users.hydrate = function (root) { hydrate(root); TinyCat.users.avatarUpload.hydrate(root); };
  TinyCat.modules.register("users", TinyCat.users);
  TinyCat.navigation.register("/admin/users", {
    target: "#users-list", url: function () { return "/api/users" + window.location.search; },
    apply: function (target, response) { TinyCat.users.list(target, response); }
  });
}());
