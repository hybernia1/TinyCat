import "../core/dom.js";
import "../core/lifecycle.js";
import "../core/navigation.js";
import "./forms.js";
import "./tabs.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat, qsa = TinyCat.dom.all, emit = TinyCat.dom.emit;
  var fieldErrorCounterId = 0, discarding = false, nativeErrors = new WeakMap();
  var confirmDiscard = function () { throw new Error("Discard confirmation is not configured."); };
  function clearErrors(form, name) {
    qsa('[aria-invalid="true"], [data-tinycat-field-error-id]', form).forEach(function (field) {
      if (name && (field.name || field.dataset.validationFor) !== name) { return; }
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
    var firstInvalid = null, fallback = null;

    if (!errors || typeof errors !== "object") {
      return firstInvalid;
    }

    Object.keys(errors).forEach(function (name) {
      clearErrors(form, name);
      var field = form.elements.namedItem(name) || form.elements.namedItem(name.replace(/\.([^.[\]]+)/g, "[$1]"));
      var fields;
      var input;
      var message;
      var error;
      var container;

      fields = !field ? [] : field instanceof RadioNodeList ? Array.prototype.slice.call(field) : [field];
      input = fields.find(function (control) { return control && control.type !== "hidden" && !control.disabled; });
      if (input) {
        if (input.name !== name) clearErrors(form, input.name);
        var proxy = qsa("[data-validation-for]", form).find(function (control) { return control.dataset.validationFor === input.name; });
        if (proxy) { fields.push(proxy); input = proxy; }
      }
      message = Array.isArray(errors[name]) ? errors[name][0] : errors[name];

      if (!input) {
        var summary = document.createElement("div");
        summary.className = "alert alert-danger";
        summary.dataset.fieldError = name;
        summary.setAttribute("role", "alert"); summary.setAttribute("tabindex", "-1");
        summary.textContent = String(message || ""); form.appendChild(summary);
        if (!fallback) fallback = summary;
        return;
      }

      fieldErrorCounterId += 1;
      error = document.createElement("span");
      error.id = "field-error-" + fieldErrorCounterId;
      error.className = "field-error";
      error.dataset.fieldError = input.name || input.dataset.validationFor || name;
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

      if (!firstInvalid) firstInvalid = input;
    });

    firstInvalid = firstInvalid || fallback;
    if (firstInvalid) TinyCat.tabs.reveal(firstInvalid);
    return firstInvalid;
  }
  function focusInvalid(field) {
    if (!field || field.isConnected === false || field.disabled) return;
    TinyCat.tabs.reveal(field);
    if (!TinyCat.dom.visible(field)) return;
    field.focus({ preventScroll: true });
    var modal = field.closest(".modal"), scroller = modal && TinyCat.dialog.scrollElement(modal);
    if (scroller) {
      var bounds = field.getBoundingClientRect(), area = scroller.getBoundingClientRect();
      if (bounds.top < area.top + 12 || bounds.bottom > area.bottom - 12) {
        scroller.scrollTop += bounds.top - area.top - Math.max(12, (area.height - bounds.height) / 2);
      }
    } else if (field.scrollIntoView) field.scrollIntoView({ block: "nearest", inline: "nearest" });
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
      title: form.dataset.confirmUnsavedTitle || TinyCat.i18n.t("common.unsaved_title"),
      message: form.dataset.confirmUnsavedMessage || TinyCat.i18n.t("common.unsaved_message"),
      confirmLabel: form.dataset.confirmUnsavedOk || TinyCat.i18n.t("common.leave"),
      cancelLabel: form.dataset.confirmUnsavedCancel || TinyCat.i18n.t("common.stay"),
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
    document.addEventListener("invalid", function (event) {
      var field = event.target, form = field.form;
      if (!form || !field.name || field.disabled) return;
      event.preventDefault();
      var errors = nativeErrors.get(form);
      if (!errors) {
        errors = {}; nativeErrors.set(form, errors);
        Promise.resolve().then(function () {
          nativeErrors.delete(form);
          if (form.isConnected === false) return;
          clearErrors(form); focusInvalid(applyErrors(form, errors));
        });
      }
      errors[field.name] = [field.validationMessage];
    }, true);
    ["input", "change", "tinycat:editor-sync"].forEach(function (name) {
      document.addEventListener(name, function (event) {
        var field = event.target, form = field.form || (field.closest && field.closest("form"));
        var fieldName = field.name || (field.dataset && field.dataset.validationFor);
        if (form && fieldName) clearErrors(form, fieldName);
        updateDirtyForm(field.closest && field.closest('form[data-confirm-unsaved="true"]'));
      });
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
  Object.assign(TinyCat.forms, { confirmWith: function (confirm) { confirmDiscard = confirm; }, clearErrors: clearErrors, applyErrors: applyErrors, focusInvalid: focusInvalid, reset: resetForm, markClean: markFormClean, dirty: dirtyForms, confirmDiscard: confirmDirtyForms, discard: resetDiscardedForms, hydrate: hydrate });
  TinyCat.lifecycle.register("form-state", { init: init, hydrate: hydrate, hydrateOrder: 10, dispose: dispose });
}());
