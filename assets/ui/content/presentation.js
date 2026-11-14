import "../../core/modules.js";
import "./media.js";
import "./translation.js";
(function () {
  "use strict";
  var T = window.TinyCat, C = T.publishing.controllers, all = T.dom.all;
  T.publishing.presentation = {
    hydrate: function (root) { all("[data-status-video]", root).forEach(C.media.initStatusVideoRoot); all("[data-status-link-image]", root).forEach(C.media.initStatusLinkImageRoot); C.translation.hydrate(root); },
    dispose: function (root) { C.media.dispose(root); C.translation.dispose(root); }
  };
  T.modules.register("publishing-presentation", T.publishing.presentation);
}());
