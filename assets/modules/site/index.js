import "../../core/modules.js";
import "./analytics.js";
import "./sidebar-data.js";
import "./sticky-sidebars.js";
import "./views.js";
import "./page-navigation.js";
import "./language-suggestion.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("site", TinyCat.site);
}());
