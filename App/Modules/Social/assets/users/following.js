import "../../../../Core/Ui/assets/runtime/dom.js";
import "../../../../Core/Ui/assets/runtime/api-client.js";
import "../../../../Core/Ui/assets/runtime/request-lifecycle.js";
import "../../../../Core/Ui/assets/runtime/i18n.js";
import "./controller.js";
import "../../../../Core/Ui/assets/component-builders.js";
import "../../../../Core/Ui/assets/form-requests.js";
import "../../../../Core/Ui/assets/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var dataSelector = TinyCat.dom.dataSelector;
  var uiText = TinyCat.i18n.label;
  var unwrapResult = TinyCat.api.unwrap;

  async function loadFollowingMore(button) {
    var modal = button ? button.closest(".modal") : null;
    var grid = modal ? qs(".following-modal-grid", modal) : null;
    var url = button ? button.dataset.followingUrl : "";
    var control = button && button.closest(".load-more"), state = control && qs(".load-more-state", control);
    var data;
    var payload;

    if (!button || !grid || !url || button.dataset.followingBusy === "true") {
      return;
    }

    var request = TinyCat.requests.begin(button);
    button.dataset.followingBusy = "true";
    button.disabled = true;
    if (control) { control.classList.add("is-loading"); control.setAttribute("aria-busy", "true"); }
    if (state) state.hidden = false;

    try {
      data = await TinyCat.api.request(url, { method: "GET", signal: request.signal });
      if (!request.current() || !grid.isConnected || !button.isConnected) return;
      payload = data.data;
      payload = Object.assign({}, payload, TinyCat.ui.pagination(data));
      if (payload.items.length) grid.appendChild(TinyCat.components.render("users/following-profiles", { items: payload.items }));

      if (payload && payload.next_url && payload.done !== true) {
        button.dataset.followingUrl = String(payload.next_url);
        button.disabled = false;
      } else {
        (control || button).remove();
      }
    } catch (error) {
      if (request.current() && button.isConnected && error.name !== "AbortError") TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
      button.disabled = false;
    } finally {
      request.finish();
      delete button.dataset.followingBusy;
      if (control) { control.classList.remove("is-loading"); control.removeAttribute("aria-busy"); }
      if (state) state.hidden = true;
    }
  }
  function updateFollowResult(form, data) {
    var payload = unwrapResult(data) || {};
    var authorId = payload.author_id ? String(payload.author_id) : (form ? form.dataset.authorId : "");
    TinyCat.socialUsers.follow(form, payload);

    if (authorId) {
      qsa("[data-author-stat='followers']" + dataSelector("data-author-id", authorId)).forEach(function (node) {
        node.textContent = String(payload.followers_count || 0);
      });
    }
  }
  function prepareForms(root) {
    qsa("form[data-follow-form]", root).forEach(function (form) {
      form.dataset.ajaxForm = "";
      form.dataset.apiMethod = form.elements.namedItem("action").value === "unfollow" ? "DELETE" : "PUT";
    });
  }
  TinyCat.socialUsers.hydrate = prepareForms;
  TinyCat.socialUsers.result = function (source, response) {
    if (!source.matches("form[data-follow-form]")) { return false; }
    updateFollowResult(source, response); prepareForms(document); return [];
  };
  TinyCat.socialUsers.following = { init: function () {
    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-following-more], [data-following-load]");
      if (trigger) { event.preventDefault(); loadFollowingMore(trigger); }
    });
  }, loadMore: loadFollowingMore };
}());
