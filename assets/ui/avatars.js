import "../core/components.js";
import "../core/dom.js";
import "../core/lifecycle.js";
import "./fields.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.avatars = {};
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  function showUserAvatarFallback(image) {
    var root = image && image.parentElement;
    var fallback = root ? qs("[data-user-avatar-fallback]", root) : null;

    if (!image) {
      return;
    }

    image.hidden = true;
    image.removeAttribute("src");

    if (fallback) {
      fallback.hidden = false;
    }
  }
  function initUserAvatarImages(scope) {
    qsa("[data-user-avatar-image]", scope || document).forEach(function (image) {
      if (image.complete && image.naturalWidth < 1) {
        showUserAvatarFallback(image);
      }
    });
  }
  TinyCat.ui = TinyCat.ui || {};
  Object.assign(TinyCat.ui, {
    appendAvatar: function (container, url, alt, className) {
      var content = TinyCat.components.render("ui/avatar-content", { user: { avatar_url: url || "" }, alt: alt || "" });
      content.querySelectorAll("img, .avatar-fallback").forEach(function (node) { if (className) { node.className = (node.className + " " + className).trim(); } });
      container.appendChild(content);
    },    avatar: function (user) {
      return TinyCat.components.render("ui/avatar", { user: user });
    },
  });
  TinyCat.avatars.hydrate = initUserAvatarImages;
  TinyCat.lifecycle.register("avatars", { hydrate: initUserAvatarImages });
}());
