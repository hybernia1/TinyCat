import "../../../../../assets/ui/content/index.js";
import "./media-navigation.js";
import "../../../../../assets/core/dom.js";
import "../../../../../assets/core/modules.js";
import "../../../../../assets/core/navigation.js";
import "./form-actions.js";
import "./comments.js";
import "../../../../../assets/ui/content/editor.js";
import "./feed-loader.js";
import "./form-context.js";
import "../../../../../assets/ui/content/media.js";
import "./controller.js";
import "../../../../../assets/ui/content/translation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var C = TinyCat.status.controllers;
  TinyCat.status.navigationUrl = TinyCat.status.navigation.mobileStatusUrl;
  TinyCat.status.hydrate = function (root) {
    C.feed.initStatusFeedLazy(root); C.actions.prepareForms(root);
  };
  TinyCat.status.dispose = function (root) { C.feed.dispose(root); };

  TinyCat.status.init = function () {
    C.comments.initCommentReplies(); C.actions.initStatusForms();
  };
  var feedResult = TinyCat.status.result;
  TinyCat.status.result = function (source, response, target) {
    if (!source.matches("form[data-status-form]")) { return feedResult(source, response, target); }
    return C.actions.handleStatusJsonResponse(source, response).then(function () { C.actions.prepareForms(document); return []; });
  };
  TinyCat.modules.register("status", TinyCat.status);
  TinyCat.navigation.register("/", {
    target: ".home-feed-section", url: function () { return "/api/statuses" + window.location.search; },
    apply: function (target, response) { TinyCat.status.feed(target, response); }
  });
}());
