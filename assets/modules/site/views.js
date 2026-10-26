import "../../core/components.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.site = {
    sidebar: function (data, url) {
      return T.components.render("site/sidebar", Object.assign({}, data, { url: url, active_tag: new URL(url, window.location.href).searchParams.get("tag") || "" }));
    },
    rich: function (parent, nodes) { T.components.rich(parent, nodes); }
  };
}());
