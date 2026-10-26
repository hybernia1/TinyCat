(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};

  // Domain modules supply DOM nodes. The existing shared focus/stack manager
  // remains the accessibility owner until its extraction with the UI modules.
  TinyCat.dialog = {
    create: function (options) {
      if (!(options.content instanceof Node) || !/^[a-z][a-z0-9_-]*$/i.test(options.id)
        || typeof options.title !== "string" || !options.title.trim()
        || typeof options.closeLabel !== "string" || !options.closeLabel.trim()) {
        throw new TypeError("A dialog requires a stable ID, accessible labels and DOM content.");
      }
      var titleId = options.id + "-title";
      var root = TinyCat.components.render("ui/modal", {
        root_attributes: { id: options.id, "class": "modal", role: "dialog", "aria-modal": "true", "aria-hidden": "true", "aria-labelledby": titleId, "data-open": "false", "data-modal-dispose-on-close": options.disposeOnClose === false ? "false" : "true" },
        panel_attributes: { "class": "modal-panel" + (options.panelClass ? " " + options.panelClass : ""), tabindex: "-1" },
        title_id: titleId, title: options.title, close_label: options.closeLabel,
        close_icon: options.closeIcon instanceof Node ? options.closeIcon : document.createTextNode("\u00d7"),
        body: options.content, body_class: "modal-body", has_footer: options.footer instanceof Node, footer: options.footer
      });
      document.body.appendChild(root);
      return root;
    },
    open: function (dialog) { TinyCat.openModal(dialog); },
    close: function (dialog) { return TinyCat.requestCloseModal(dialog); }
  };
}());
