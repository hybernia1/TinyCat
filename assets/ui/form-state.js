import "../core/dom.js";
import "../core/lifecycle.js";
import "../core/navigation.js";
import "./forms.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat, qsa = TinyCat.dom.all, emit = TinyCat.dom.emit;
  var fieldErrorCounterId = 0, discarding = false;
  var confirmDiscard = function () { throw new Error("Discard confirmation is not configured."); };
  function clearErrors(form, name) {
    qsa('[aria-invalid="true"]', form).forEach(function (field) {
      if (name && field.name !== name) { return; }
      var errorId = field.dataset.tinycatFieldErrorId || "";
      var describedBy;

      field.removeAttribute("aria-invalid");

      if (errorId) {
        describedBy = String(field.getAttribute("aria-describedby") || "")
          .split(/\s+/)
          .filter(function (id) {
            return id && id !== errorId;
          });

        if (describedBy.length > 0) {
          field.setAttribute("aria-describedby", describedBy.join(" "));
        } else {
          field.removeAttribute("aria-describedby");
        }

        delete field.dataset.tinycatFieldErrorId;
      }
    });

    qsa("[data-field-error]", form).forEach(function (error) {
      if (!name || error.dataset.fieldError === name) { error.remove(); }
    });
  }
  function applyErrors(form, errors) {
    var firstInvalid = null;

    if (!errors || typeof errors !== "object") {
      return firstInvalid;
    }

    Object.keys(errors).forEach(function (name) {
      var field = form.elements.namedItem(name);
      var fields;
      var input;
      var message;
      var error;
      var container;

      if (!field) {
        return;
      }

      fields = field instanceof RadioNodeList ? Array.prototype.slice.call(field) : [field];
      input = fields[0];
      message = Array.isArray(errors[name]) ? errors[name][0] : errors[name];

      if (!input) {
        return;
      }

      fieldErrorCounterId += 1;
      error = document.createElement("span");
      error.id = "field-error-" + fieldErrorCounterId;
      error.className = "field-error";
      error.dataset.fieldError = name;
      error.textContent = String(message || "");

      fields.filter(Boolean).forEach(function (control) {
        var describedBy = String(control.getAttribute("aria-describedby") || "")
          .split(/\s+/)
          .filter(Boolean);

        control.setAttribute("aria-invalid", "true");
        control.dataset.tinycatFieldErrorId = error.id;

        if (describedBy.indexOf(error.id) === -1) {
          describedBy.push(error.id);
          control.setAttribute("aria-describedby", describedBy.join(" "));
        }
      });

      container = input.closest("[data-captcha], .field");

      if (container) {
        container.appendChild(error);
      } else if (input.closest(".check-line")) {
        input.closest(".check-line").insertAdjacentElement("afterend", error);
      } else {
        input.insertAdjacentElement("afterend", error);
      }

      if (!firstInvalid && input.type !== "hidden" && !input.hidden && !input.closest('[hidden], [aria-hidden="true"]')) {
        firstInvalid = input;
      }
    });

    return firstInvalid;
  }
  function resetForm(form) {
    if (!form || form.dataset.reset !== "true") {
      return;
    }

    form.reset();

    emit(form, "tinycat:form-reset");

  }
  function formSnapshot(form) {
    var data = new FormData(form);
    var values = [];

    data.forEach(function (value, key) {
      if (key === "_csrf") {
        return;
      }

      if (value instanceof File) {
        if (value.name === "" && value.size === 0) { return; }
        values.push(key + "=" + value.name + ":" + value.size + ":" + value.lastModified);
        return;
      }

      values.push(key + "=" + String(value || ""));
    });

    return JSON.stringify(values);
  }
  function markFormClean(form) {
    if (!form || form.dataset.confirmUnsaved !== "true") {
      return;
    }

    form.__tinycatInitialSnapshot = formSnapshot(form);
    form.dataset.dirty = "false";
  }
  function updateDirtyForm(form) {
    if (!form || form.dataset.confirmUnsaved !== "true") {
      return;
    }

    if (typeof form.__tinycatInitialSnapshot !== "string") {
      markFormClean(form);
    }

    form.dataset.dirty = formSnapshot(form) === form.__tinycatInitialSnapshot ? "false" : "true";
  }
  function dirtyForms(modal) {
    return qsa('form[data-confirm-unsaved="true"][data-dirty="true"]', modal);
  }
  function confirmDirtyForms(forms) {
    var form = forms[0];

    if (!form) {
      return Promise.resolve(true);
    }

    return confirmDiscard({
      title: form.dataset.confirmUnsavedTitle || "Unsaved changes",
      message: form.dataset.confirmUnsavedMessage || "Discard unsaved changes?",
      confirmLabel: form.dataset.confirmUnsavedOk || "Leave",
      cancelLabel: form.dataset.confirmUnsavedCancel || "Stay",
      variant: "danger"
    });
  }
  function resetDiscardedForms(scope) {
    qsa('form[data-confirm-unsaved="true"][data-dirty="true"]', scope).forEach(function (form) {
      form.reset();
      clearErrors(form);

      emit(form, "tinycat:form-reset");

      markFormClean(form);
      emit(form, "tinycat:discard");
    });
  }

  function hydrate(scope) {
    qsa('form[data-confirm-unsaved="true"]', scope || document).forEach(function (form) {
      if (form.dataset.dirtyReady === "true") { return; }
      form.dataset.dirtyReady = "true"; markFormClean(form);
    });
  }
  function dispose(root) {
    var forms = qsa("form", root);
    if (root.matches && root.matches("form")) { forms.push(root); }
    forms.forEach(TinyCat.forms.dispose);
  }
  async function beforeLeave() {
    var forms = dirtyForms(document);
    if (!await confirmDirtyForms(forms)) { return false; }
    if (forms.length) {
      resetDiscardedForms(document); discarding = true;
      window.setTimeout(function () { discarding = false; }, 1000);
    }
    return true;
  }
  function init() {
    ["input", "change", "tinycat:editor-sync"].forEach(function (name) {
      document.addEventListener(name, function (event) { updateDirtyForm(event.target.closest && event.target.closest('form[data-confirm-unsaved="true"]')); });
    });
    document.addEventListener("click", function (event) {
      var link = event.target.closest && event.target.closest("a[href]"), url;
      if (event.defaultPrevented || !link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute("download") || link.hasAttribute("data-ajax") || link.hasAttribute("data-modal-open")) { return; }
      try { url = new URL(link.href, window.location.href); } catch (_) { return; }
      if (url.origin === window.location.origin && url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== "") { return; }
      if (!dirtyForms(document).length) { return; }
      event.preventDefault(); TinyCat.navigation.go(url.href);
    });
    window.addEventListener("beforeunload", function (event) {
      if (!discarding && dirtyForms(document).length) { event.preventDefault(); event.returnValue = ""; }
    });
    TinyCat.navigation.guard(beforeLeave);
  }
  Object.assign(TinyCat.forms, { confirmWith: function (confirm) { confirmDiscard = confirm; }, clearErrors: clearErrors, applyErrors: applyErrors, reset: resetForm, markClean: markFormClean, dirty: dirtyForms, confirmDiscard: confirmDirtyForms, discard: resetDiscardedForms, hydrate: hydrate });
  TinyCat.lifecycle.register("form-state", { init: init, hydrate: hydrate, hydrateOrder: 10, dispose: dispose });
}());
