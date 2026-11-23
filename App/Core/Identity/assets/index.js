import "./user-menu.js";
import "../../Ui/assets/runtime/modules.js";
import "./controller.js";
import "./email-availability.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("accounts", TinyCat.accounts);
}());
