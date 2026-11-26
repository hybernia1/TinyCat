// @component-owned: structure is declared in owner catalogs.
import "../../../../Core/Ui/assets/runtime/dom.js";
import "../../../../Core/Ui/assets/runtime/component-renderer.js";
import "../../../../Core/Ui/assets/component-builders.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function following(data, pagination) {
    return T.components.render("users/following-dialog", { items: data.items, next_url: pagination.next_url || "" });
  }
  T.socialUsers = {};
  T.socialUsers.follow = function (form, data) {
      form.elements.namedItem("action").value = data.following ? "unfollow" : "follow";
      var button = form.querySelector("button");
      button.replaceChildren(T.components.render("users/follow-label", { following: data.following }));
      button.classList.toggle("btn-primary", !data.following); button.classList.toggle("btn-secondary", data.following);
    };
  T.socialUsers.modal = function (url, response, id) {
      if (/^\/api\/profiles\/[0-9]+\/following$/.test(url.pathname)) { return U.dialog(id, "public.following", following(response.data, U.pagination(response))); }
    };
}());
