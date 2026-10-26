import "../../core/modules.js";
import "./analytics.js";
import "./controller.js";
import "./layout.js";
import "./views.js";
import "./navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("site", TinyCat.site);
}());
