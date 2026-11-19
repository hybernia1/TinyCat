// @component-owned: structure is declared in owner catalogs.
import "../../../../assets/core/modules.js";
import "../../../../assets/core/dates.js";
import "../../../../assets/ui/content-updates.js";
import "../../../../assets/ui/list-navigation.js";
import "../../../../assets/ui/component-builders.js";
import "../../../../assets/ui/form-requests.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  T.modules.register("blog-sources", {
    modal: function (url, response, id) {
      if (!/^\/api\/article-sources\/(?:creation|[1-9][0-9]*\/management)$/.test(url.pathname)) { return null; }
      var item = response.data.item, form = U.form("/api/article-sources" + (item.id ? "/" + item.id : ""), item.id ? "PUT" : "POST", T.components.render("blog/source-form", { resource: response.data }));
      var preview = form.querySelector("[data-article-source-preview]"), output = form.querySelector("[data-article-source-output]");
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
      var dialog = U.adminDialog(id, item.id ? "common.edit" : "blog.feeds.create", form, false);
      dialog.addEventListener("tinycat:modal-close", function () { T.forms.cancel(form); });
      return dialog;
    }
  });
}());
