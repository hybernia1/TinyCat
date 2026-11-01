import "../../core/dom.js";
import "../../core/api-client.js";
import "../../core/i18n.js";
import "./controller.js";
import "../../ui/component-builders.js";
import "../../ui/form-requests.js";
import "../../ui/toasts.js";
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

    button.dataset.followingBusy = "true";
    button.disabled = true;
    if (control) { control.classList.add("is-loading"); control.setAttribute("aria-busy", "true"); }
    if (state) state.hidden = false;

    try {
      data = await TinyCat.api.request(url, { method: "GET" });
      if (!grid.isConnected || !button.isConnected) return;
      payload = data && data.data ? data.data : data;
      payload = Object.assign({}, payload, TinyCat.ui.pagination(data));
      if (payload.items.length) grid.appendChild(TinyCat.users.profiles(payload.items));

      if (payload && payload.next_url && payload.done !== true) {
        button.dataset.followingUrl = String(payload.next_url);
        button.disabled = false;
      } else {
        (control || button).remove();
      }
    } catch (error) {
      if (button.isConnected && error.name !== "AbortError") TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
      button.disabled = false;
    } finally {
      delete button.dataset.followingBusy;
      if (control) { control.classList.remove("is-loading"); control.removeAttribute("aria-busy"); }
      if (state) state.hidden = true;
    }
  }
  function updateFollowResult(form, data) {
    var payload = unwrapResult(data) || {};
    var authorId = payload.author_id ? String(payload.author_id) : (form ? form.dataset.authorId : "");
    TinyCat.users.follow(form, payload);

    if (data && data.message) {
      TinyCat.toasts.show(data.message, data.type || "success");
    } else if (payload.message) {
      TinyCat.toasts.show(payload.message, payload.type || "success");
    }

    if (authorId) {
      qsa("[data-author-stat='followers']" + dataSelector("data-author-id", authorId)).forEach(function (node) {
        node.textContent = String(payload.followers_count || 0);
      });
      qsa("[data-author-stat='following']" + dataSelector("data-author-id", authorId)).forEach(function (node) {
        node.textContent = String(payload.following_count || 0);
      });
    }
  }
  function initFollowForms () {
    if (TinyCat.__followFormEventsBound === true) {
      return;
    }

    TinyCat.__followFormEventsBound = true;

    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-follow-form]");
      var method;
      var url;
      var body;
      var data;

      if (!form) {
        return;
      }

      event.preventDefault();

      if (form.dataset.followBusy === "true") {
        return;
      }

      method = (form.getAttribute("method") || "POST").toUpperCase();
      url = form.getAttribute("action") || window.location.href;
      body = new FormData(form);
      method = body.get("action") === "unfollow" ? "DELETE" : "PUT";

      try {
        form.dataset.followBusy = "true";
        data = await TinyCat.forms.request(form, url, {
          method: method,
          body: method === "GET" ? null : body
        });
        updateFollowResult(form, data);
      } catch (error) {
        if (error.name === "AbortError") { return; }
        TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
      } finally {
        delete form.dataset.followBusy;
      }
    });
  };
  TinyCat.users.following = { init: function () {
    initFollowForms();
    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-following-more], [data-following-load]");
      if (trigger) { event.preventDefault(); loadFollowingMore(trigger); }
    });
  }, loadMore: loadFollowingMore };
}());
