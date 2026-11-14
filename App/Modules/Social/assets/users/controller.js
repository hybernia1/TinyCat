import "../../../../Core/Identity/assets/controller.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, e = T.dom.element;
  function following(data, pagination) {
    var root = e("div", { "class": "stack" }), grid = e("div", { "class": "following-modal-grid" });
    grid.appendChild(T.users.profiles(data.items)); root.appendChild(grid);
    if (pagination.next_url) { root.appendChild(T.components.render("ui/load-more", { attributes: { "class": "load-more" }, button_attributes: { type: "button", "class": "btn btn-secondary", "data-following-more": "", "data-following-url": pagination.next_url }, label: U.text("notifications.load_more"), loading_label: U.text("notifications.loading") })); }
    return root;
  }
  T.users.profiles = function (items) {
      var fragment = document.createDocumentFragment();
      (items || []).forEach(function (user) { fragment.appendChild(T.components.render("users/following-profile", { user: user })); });
      return fragment;
    };
  T.users.follow = function (form, data) {
      form.elements.namedItem("action").value = data.following ? "unfollow" : "follow";
      var button = form.querySelector("button");
      button.replaceChildren(U.icon(data.following ? "check" : "plus"), e("span", {}, U.text(data.following ? "public.unfollow" : "public.follow")));
      button.classList.toggle("btn-primary", !data.following); button.classList.toggle("btn-secondary", data.following);
    };
  T.socialUsers = {
    modal: function (url, response, id) {
      if (/^\/api\/users\/[0-9]+\/following$/.test(url.pathname)) { return U.dialog(id, "public.following", following(response.data, U.pagination(response))); }
    }
  };
}());
