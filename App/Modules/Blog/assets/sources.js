import "../../../../assets/core/modules.js";
import "../../../../assets/core/dates.js";
import "../../../../assets/ui/content-updates.js";
import "../../../../assets/ui/list-navigation.js";
import "../../../../assets/ui/component-builders.js";
import "../../../../assets/ui/form-requests.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function render(target, response) {
    T.content.replaceChildren(target, T.components.render("blog/sources", Object.assign({}, response.data, { pagination: U.pagination(response) })));
  }
  T.modules.register("blog-sources", {
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", location.href);
      if (!url.pathname.startsWith("/api/article-sources")) { return false; }
      if (target && target.id === "blog-sources") {
        if (T.lists.isRead(source)) { render(target, response); return [target]; }
        T.lists.refresh(target);
      }
      return [];
    },
    modal: function (url, response, id) {
      if (!/^\/api\/article-sources\/(?:creation|[1-9][0-9]*\/management)$/.test(url.pathname)) { return null; }
      var item = response.data.item, form = U.form("/api/article-sources" + (item.id ? "/" + item.id : ""), item.id ? "PUT" : "POST");
      form.dataset.ajaxTarget = "#blog-sources";
      form.dataset.modalCloseOnSuccess = "true";
      form.appendChild(T.components.render("blog/source-fields", response.data));
      var preview = U.button("blog.feeds.preview"), output = T.dom.element("div", { "aria-live": "polite", "class": "stack" });
      preview.dataset.articleSourcePreview = "";
      preview.addEventListener("click", async function () {
        if (preview.disabled) { return; }
        var feed = form.elements.feed_url.value;
        preview.disabled = true;
        try {
          var result = await T.forms.request(form, "/api/article-sources/preview", { method: "POST", body: { feed_url: feed } });
          if (form.isConnected && form.elements.feed_url.value === feed) { output.replaceChildren(T.components.render("blog/source-preview", result.data)); }
        } catch (error) { if (form.isConnected && error.name !== "AbortError") { output.textContent = error.message; } }
        finally { preview.disabled = false; }
      });
      form.appendChild(preview); form.appendChild(output);
      form.appendChild(U.button("common.save", { type: "submit" }));
      var dialog = U.adminDialog(id, item.id ? "common.edit" : "blog.feeds.create", form, false);
      dialog.addEventListener("tinycat:modal-close", function () { T.forms.cancel(form); });
      return dialog;
    },
    hydrate: function (root) {
      (root || document).querySelectorAll("[data-source-list-state]").forEach(function (view) {
        if (view.dataset.sourceReady) { return; }
        view.dataset.sourceReady = "true";
        var state = JSON.parse(view.dataset.sourceListState);
        U.filterPanel("blog-source-filter", T.components.render("blog/source-filter", state));
      });
    }
  });
  T.lists.register("/admin/article-sources", { target: "#blog-sources", keepQuery: ["sort", "direction"], url: function () { return "/api/article-sources/management" + location.search; }, apply: render });
}());
