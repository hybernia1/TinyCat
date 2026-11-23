// @component-owned: structure is declared in owner catalogs.
import "../content/comments.js";
import "../content/runtime.js";
import "../../../../Core/Ui/assets/content-updates.js";
import "../../../../Core/Ui/assets/runtime/dates.js";
import "../../../../Core/Ui/assets/runtime/component-renderer.js";
import "../../../../Core/Ui/assets/runtime/dom.js";
import "../../../../Core/Ui/assets/component-builders.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  T.status = {
    segments: function (segments) {
      return T.components.render("publishing/segments", { segments: segments || [] });
    },
    card: function (item, anchor, openModal) {
      return T.components.render("status/card", { item: item, anchor: anchor !== false, open_modal: openModal !== false, action: window.location.pathname });
    },
    fragment: function (items) { var fragment = document.createDocumentFragment(); (items || []).forEach(function (item) { fragment.appendChild(T.status.card(item)); }); return { content: fragment }; },
    comment: function (item, context, viewer) {
      return T.components.render("status/comment", { item: item, context: context || "", viewer: viewer, show_replies: true, show_reply_form: true });
    },
    method: function (form, action) {
      if (action === "react" || action === "like" || action === "comment_like") {
        var button = form.querySelector("[aria-pressed]"); return button && button.getAttribute("aria-pressed") === "true" ? "DELETE" : "PUT";
      }
      return { update: "PATCH", comment_update: "PATCH", delete: "DELETE", comment_delete: "DELETE" }[action] || "POST";
    },
    feed: function (target, response) {
      var section = target.matches(".home-feed-section") ? target : target.closest(".home-feed-section");
      var feed = section.querySelector("[data-status-feed]");
      T.status.controllers.feed.dispose(feed);
      T.content.replaceChildren(feed, T.status.fragment(response.data.items).content);
      feed.id = "status-feed-" + (response.data.feed || "all");
      section.querySelectorAll("[data-status-empty], [data-status-feed-more], [data-feed-login-required]").forEach(T.content.remove);
      section.querySelectorAll(".feed-switch-link").forEach(function (link) { var following = link.getAttribute("href").indexOf("following") !== -1; if (following === (response.data.feed === "following")) { link.setAttribute("aria-current", "page"); } else { link.removeAttribute("aria-current"); } });
      if (!response.data.items.length) { section.appendChild(T.components.render("status/feed-empty", response.data)); }
      var pagination = U.pagination(response);
      if (pagination.next_url) {
        section.appendChild(T.components.render("status/feed-more", { target: "#" + feed.id, url: pagination.next_url }));
      }
    }
  };
  T.status.result = function (source, response, target) {
    var url = new URL(source.dataset.url || source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
    if (url.pathname !== "/api/statuses" || !target || !target.matches(".home-feed-section")) { return false; }
    T.status.feed(target, response); return [target];
  };
  T.status.controllers = {};
}());
