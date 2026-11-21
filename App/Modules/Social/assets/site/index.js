import "../../../../Core/Ui/assets/runtime/modules.js";
import "../../../../Core/Ui/assets/analytics.js";
import "./sidebar-data.js";
import "./sticky-sidebars.js";
import "./views.js";
import "./page-navigation.js";
import "../../../../Core/Ui/assets/language-suggestion.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.modules.register("site", TinyCat.site);
}());
