import "../../Ui/assets/runtime/modules.js";
import "./controller.js";
import "../../Ui/assets/ajax-bindings.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("notifications", TinyCat.notifications);
}());
