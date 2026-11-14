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
  var C = TinyCat.status.controllers, qsa = TinyCat.dom.all;
  TinyCat.status.navigationUrl = C.media.mobileStatusUrl;
  TinyCat.status.hydrate = function (root) {
    TinyCat.publishing.hydrate(root); C.feed.initStatusFeedLazy(root);
  };
  TinyCat.status.dispose = function (root) { C.feed.dispose(root); TinyCat.publishing.dispose(root); };

  TinyCat.status.init = function () {
    C.comments.initCommentReplies(); C.actions.initStatusForms();
  };
  TinyCat.modules.register("status", TinyCat.status);
  TinyCat.navigation.register("/", {
    target: ".home-feed-section", url: function () { return "/api/statuses" + window.location.search; },
    apply: function (target, response) { TinyCat.status.feed(target, response); }
  });
}());
