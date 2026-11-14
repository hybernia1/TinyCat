import "../../core/modules.js";
import "./editor.js";
import "./media.js";
(function () {
  "use strict";
  var T = window.TinyCat, C = T.publishing.controllers, all = T.dom.all;
  T.publishing.editing = {
    hydrate: function (root) { all("[data-status-image]", root).forEach(C.media.initStatusImageRoot); C.editor.hydrate(root); },
    dispose: function (root) { var editors = all("[data-status-editor]", root); if (root.matches && root.matches("[data-status-editor]")) editors.push(root); editors.forEach(C.editor.hideSuggestions); },
    init: function () { document.addEventListener("tinycat:form-reset", function (event) { all("[data-status-editor]", event.target).forEach(C.editor.reset); }); }
  };
  T.modules.register("publishing-editor", T.publishing.editing);
}());
