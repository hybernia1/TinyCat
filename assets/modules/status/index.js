import "../../core/dom.js";
import "../../core/modules.js";
import "../../core/navigation.js";
import "./actions.js";
import "./comments.js";
import "./editor.js";
import "./feed.js";
import "./forms.js";
import "./media.js";
import "./views.js";
import "./translation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var C = TinyCat.status.controllers, qsa = TinyCat.dom.all;
  TinyCat.status.navigationUrl = C.media.mobileStatusUrl;
  TinyCat.status.hydrate = function (root) {
    qsa("[data-status-image]", root).forEach(C.media.initStatusImageRoot);
    qsa("[data-status-video]", root).forEach(C.media.initStatusVideoRoot);
    qsa("[data-status-link-image]", root).forEach(C.media.initStatusLinkImageRoot);
    C.editor.initStatusEditors(root); C.feed.initStatusFeedLazy(root);
    C.translation.hydrate(root);
  };
  TinyCat.status.dispose = function (root) {
    C.feed.dispose(root);
    var editors = qsa("[data-status-editor]", root);
    if (root.matches && root.matches("[data-status-editor]")) { editors.push(root); }
    editors.forEach(C.editor.hideStatusEditorSuggestions);
    if (C.translation.dispose) { C.translation.dispose(root); }
  };
  TinyCat.status.init = function () {
    document.addEventListener("tinycat:form-reset", function (event) { qsa("[data-status-editor]", event.target).forEach(C.editor.resetStatusEditor); });
    C.comments.initCommentReplies(); C.actions.initStatusForms();
  };
  TinyCat.modules.register("status", TinyCat.status);
  TinyCat.navigation.register("/", {
    target: ".home-feed-section", url: function () { return "/api/statuses" + window.location.search; },
    apply: function (target, response) { TinyCat.status.feed(target, response); }
  });
}());
