import "../content/index.js";
import "./media-navigation.js";
import "../../../../Core/Ui/assets/runtime/dom.js";
import "../../../../Core/Ui/assets/runtime/modules.js";
import "../../../../Core/Ui/assets/runtime/navigation.js";
import "./form-actions.js";
import "./comments.js";
import "./feed-loader.js";
import "./form-context.js";
import "../content/media.js";
import "./controller.js";
import "../content/translation.js";
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
    C.comments.initCommentReplies(); if (C.comments.initCommentPagination) C.comments.initCommentPagination(); C.actions.initStatusForms();
  };
  var feedResult = TinyCat.status.result;
  TinyCat.status.result = function (source, response, target) {
    if (!source.matches("form[data-status-form]")) { return feedResult(source, response, target); }
    return C.actions.handleStatusJsonResponse(source, response).then(function (roots) { C.actions.prepareForms(source); return roots; });
  };
  TinyCat.modules.register("status", TinyCat.status);
  TinyCat.navigation.register("/", {
    target: ".home-feed-section", url: function () { return "/api/social/statuses" + window.location.search; },
    apply: function (target, response) { TinyCat.status.feed(target, response); }
  });
}());
