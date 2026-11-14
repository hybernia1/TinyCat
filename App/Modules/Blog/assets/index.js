import "../../../../assets/core/modules.js";
import "../../../../assets/core/dates.js";
import "../../../../assets/ui/content-updates.js";
import "../../../../assets/ui/list-navigation.js";
import "../../../../assets/ui/component-builders.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function render(target, response) {
    T.content.replaceChildren(target, T.components.render("blog/articles", Object.assign({}, response.data, { pagination: U.pagination(response) })));
  }
  var blog = {
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", location.href);
      if (!url.pathname.startsWith("/api/articles")) { return false; }
      if (target && target.id === "blog-articles") {
        if (T.lists.isRead(source)) { render(target, response); return [target]; }
        T.lists.refresh(target);
      }
      return [];
    },
    modal: function (url, response, id) {
      if (!/^\/api\/articles\/(?:creation|[1-9][0-9]*\/management)$/.test(url.pathname)) { return null; }
      var item = response.data.item, form = U.form("/api/articles" + (item.id ? "/" + item.id : ""), item.id ? "PUT" : "POST");
      form.dataset.ajaxTarget = "#blog-articles";
      form.dataset.modalCloseOnSuccess = "true";
      form.appendChild(T.components.render("blog/article-fields", response.data));
      form.appendChild(U.button("common.save", { type: "submit" }));
      return U.adminDialog(id, item.id ? "common.edit" : "blog.editor", form, false);
    },
    hydrate: function (root) {
      (root || document).querySelectorAll("[data-article-list-state]").forEach(function (view) {
        if (view.dataset.articleReady) { return; }
        view.dataset.articleReady = "true";
        var state = JSON.parse(view.dataset.articleListState);
        var form = T.components.render("blog/article-filter", state);
        form.querySelector('[name="author"]').value = state.filters.author;
        form.querySelectorAll("input, select").forEach(function (field) {
          field.defaultValue = field.value;
          if (field.options) { Array.from(field.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
        });
        U.filterPanel("blog-article-filter", form);
      });
      (root || document).querySelectorAll("[data-article-open]").forEach(function (trigger) {
        if (trigger.dataset.articleOpened) { return; }
        trigger.dataset.articleOpened = "true";
        var article = Number(trigger.dataset.articleOpen), url = article ? "/api/articles/" + article + "/management" : "/api/articles/creation";
        T.dialog.openResource("modal-articles-" + (article || "creation"), url);
      });
    }
  };
  T.modules.register("blog", blog);
  T.lists.register("/admin/articles", { target: "#blog-articles", keepQuery: ["sort", "direction"], url: function () { return "/api/articles/management" + location.search; }, apply: render });
}());
