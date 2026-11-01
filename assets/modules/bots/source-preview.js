import "../../core/lifecycle.js";
import "../../core/component-renderer.js";
import "../../ui/component-builders.js";
import "../../ui/form-requests.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, bindings = new WeakMap(), serial = 0;
  function values(form) {
    var data = {};
    new FormData(form).forEach(function (value, name) { if (typeof value === "string") { data[name] = value; } });
    return data;
  }
  function signature(form) {
    return JSON.stringify(values(form));
  }
  async function preview(form, override) {
    var binding = bindings.get(form);
    if (!binding || binding.busy) { return; }
    binding.busy = true;
    var initial = signature(form), fields = values(form);
    var data = { feed_url: override || fields.feed_url, name: fields.name, post_template: fields.post_template };
    var content = T.dom.element("div", { "class": "stack", "aria-live": "polite" }, U.text("bots.preview_loading"));
    var actions = U.editorActions();
    var dialog = U.dialog("bot-source-preview-" + (++serial), "bots.preview_title", content, false, actions);
    dialog.addEventListener("tinycat:modal-close", function () { T.forms.cancel(form); });
    T.dialog.open(dialog);
    try {
      if (!dialog.isConnected) { return; }
      var result = await T.forms.request(form, "/api/bots/sources/previews", { method: "POST", body: data });
      if (!dialog.isConnected || !form.isConnected) { return; }
      if (signature(form) !== initial) { content.textContent = U.text("bots.preview_changed"); return; }
      var resource = result.data, name = form.querySelector('[name="name"]');
      if (name && !name.value && resource.title) { name.value = resource.title.slice(0, 120); }
      var approvedSignature = signature(form);
      content.replaceChildren(T.components.render("bots/source-preview", resource));
      content.querySelectorAll("[data-bot-preview-source]").forEach(function (button) {
        button.addEventListener("click", function () {
          var candidate = resource.sources[Number(button.dataset.botPreviewSource)];
          if (resource.format === "discovery") {
            T.dialog.close(dialog); form.querySelector('[name="feed_url"]').value = candidate.feed_url;
            if (name && (!name.value || name.value === resource.title)) { name.value = candidate.name; }
            preview(form, candidate.feed_url);
          }
        });
      });
      if (resource.items.length) {
        var confirm = U.button("bots.preview_confirm", { "class": "btn btn-primary" });
        confirm.addEventListener("click", function () {
          T.dialog.close(dialog);
          if (signature(form) !== approvedSignature || !form.reportValidity()) { return; }
          binding.approved = approvedSignature;
          form.requestSubmit();
        });
        actions.appendChild(confirm);
      }
    } catch (error) {
      if (error.name !== "AbortError" && dialog.isConnected) { content.textContent = error.message; }
    } finally { binding.busy = false; }
  }
  function bind(form, button) {
    if (!form || bindings.has(form)) { return; }
    var binding = { busy: false, approved: null };
    bindings.set(form, binding);
    if (button) { button.addEventListener("click", function () { preview(form); }); }
    form.addEventListener("submit", function (event) {
      if (binding.approved === signature(form)) { binding.approved = null; return; }
      event.preventDefault(); event.stopImmediatePropagation(); preview(form);
    }, true);
  }
  T.lifecycle.register("bot-source-preview", {
    hydrate: function (root) {
      root.querySelectorAll("[data-bot-source-preview]").forEach(function (button) {
        var form = button.closest("form");
        bind(form, button);
      });

    },
    dispose: function (root) {
      root.querySelectorAll("form").forEach(function (form) { if (bindings.has(form)) { T.forms.cancel(form); } });
    }
  });
}());
