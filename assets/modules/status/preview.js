import "../../core/modules.js";
import "./media.js";
(function () {
  "use strict";
  var T = window.TinyCat, media = T.status.controllers.media;
  function hydrate(root) {
    T.dom.all("[data-status-image]", root).forEach(media.initStatusImageRoot);
    T.dom.all("[data-status-video]", root).forEach(media.initStatusVideoRoot);
    T.dom.all("[data-status-link-image]", root).forEach(media.initStatusLinkImageRoot);
  }
  T.modules.register("status-preview", { hydrate: hydrate, dispose: media.dispose });
}());
