(function () {
  "use strict";

  var TinyCat = window.TinyCat || {};
  var EditorModal = TinyCat.EditorModal || {};
  var active = null;

  function element(tag, className, text) {
    var node = document.createElement(tag);

    if (className) {
      node.className = className;
    }

    if (text !== undefined) {
      node.textContent = text;
    }

    return node;
  }

  function focusInput(input) {
    window.setTimeout(function () {
      input.focus();
      input.select();
    }, 0);
  }

  function finish(value) {
    var modal = active;

    if (!modal) {
      return;
    }

    active = null;
    document.removeEventListener("keydown", modal.onKeydown);

    if (TinyCat.closeModal) {
      TinyCat.closeModal(modal.root);
    } else {
      document.body.classList.remove("has-modal");
    }

    modal.root.remove();

    if (modal.previousFocus && modal.previousFocus.focus) {
      modal.previousFocus.focus();
    }

    modal.resolve(value);
  }

  EditorModal.prompt = function (options) {
    options = options || {};

    if (active) {
      finish(null);
    }

    return new Promise(function (resolve) {
      var root = element("div", "modal tc-editor-modal");
      var backdrop = element("div", "modal-backdrop");
      var panel = element("form", "modal-panel tc-editor-modal-panel");
      var header = element("div", "modal-header");
      var body = element("div", "modal-body");
      var footer = element("div", "modal-footer");
      var title = element("h2", "tc-editor-modal-title", options.title || "");
      var close = element("button", "btn btn-icon btn-ghost", "x");
      var field = element("label", "field");
      var label = element("span", "label", options.label || "");
      var input = element("input", "input");
      var cancel = element("button", "btn btn-secondary", options.cancelLabel || "Cancel");
      var confirm = element("button", "btn btn-primary", options.confirmLabel || "OK");

      root.setAttribute("role", "dialog");
      root.setAttribute("aria-modal", "true");
      root.setAttribute("aria-hidden", "true");
      root.dataset.open = "false";

      panel.setAttribute("novalidate", "novalidate");
      panel.setAttribute("autocomplete", "off");

      close.type = "button";
      close.setAttribute("aria-label", options.closeLabel || options.cancelLabel || "Close");

      input.type = options.inputType || "text";
      input.value = options.value || "";
      input.placeholder = options.placeholder || "";

      cancel.type = "button";
      confirm.type = "submit";

      header.appendChild(title);
      header.appendChild(close);
      field.appendChild(label);
      field.appendChild(input);
      body.appendChild(field);
      footer.appendChild(cancel);
      footer.appendChild(confirm);
      panel.appendChild(header);
      panel.appendChild(body);
      panel.appendChild(footer);
      root.appendChild(backdrop);
      root.appendChild(panel);
      document.body.appendChild(root);

      active = {
        root: root,
        previousFocus: document.activeElement,
        resolve: resolve,
        onKeydown: function (event) {
          if (event.key === "Escape") {
            event.preventDefault();
            finish(null);
          }
        }
      };

      panel.addEventListener("submit", function (event) {
        event.preventDefault();
        finish(input.value);
      });

      cancel.addEventListener("click", function () {
        finish(null);
      });

      close.addEventListener("click", function () {
        finish(null);
      });

      backdrop.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        finish(null);
      });

      root.addEventListener("tinycat:modal-close", function () {
        finish(null);
      }, { once: true });

      document.addEventListener("keydown", active.onKeydown);

      if (TinyCat.openModal) {
        TinyCat.openModal(root);
      } else {
        root.dataset.open = "true";
        root.setAttribute("aria-hidden", "false");
        document.body.classList.add("has-modal");
      }

      focusInput(input);
    });
  };

  EditorModal.close = function (value) {
    finish(value || null);
  };

  TinyCat.EditorModal = EditorModal;
  window.TinyCat = TinyCat;
}());
