import "./runtime/dom.js";
import "./runtime/api-client.js";
import "./runtime/navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  var qsa = TinyCat.dom.all;
  var bindings = new WeakMap();
  var submitGuards = new WeakMap();
  var requests = new WeakMap();

  function controls(element) {
    return element.elements
      ? Array.from(element.elements).filter(function (field) { return "disabled" in field; })
      : Array.from(qsa("button, input, select, textarea", element));
  }

  function setLoading(element, loading) {
    if (!element) { return; }
    element.classList.toggle("is-loading", loading);
    element.setAttribute("aria-busy", String(loading));
    controls(element).forEach(function (field) {
      if (loading && field.dataset.tinycatWasDisabled === undefined) { field.dataset.tinycatWasDisabled = String(field.disabled); }
      field.disabled = loading || field.dataset.tinycatWasDisabled === "true";
      if (!loading) { delete field.dataset.tinycatWasDisabled; }
    });
  }

  TinyCat.forms = {
    loading: setLoading,
    data: function (form, submitter) { return new FormData(form, submitter || undefined); },
    validate: function (form) {
      if (TinyCat.forms.syncConstraints) { TinyCat.forms.syncConstraints(form); }
      return form.reportValidity();
    },
    setSubmitGuard: function (form, guard) { submitGuards.set(form, guard); },
    canSubmit: function (form) { var guard = submitGuards.get(form); return !guard || guard(); },
    request: function (form, url, options) {
      if (requests.has(form)) { return requests.get(form).promise; }
      var controller = new AbortController(), previousBusy = form.getAttribute("aria-busy");
      var signal = options && options.signal;
      function abort() { controller.abort(); }
      if (signal) {
        if (signal.aborted) { abort(); }
        else { signal.addEventListener("abort", abort, { once: true }); }
      }
      var fields = controls(form);
      var disabled = fields.map(function (control) { return control.disabled; });
      var wasLoading = form.classList && form.classList.contains ? form.classList.contains("is-loading") : false;
      var entry = { controller: controller };
      fields.forEach(function (control) { control.disabled = true; });
      if (form.classList) { form.classList.add("is-loading"); }
      form.setAttribute("aria-busy", "true");
      entry.finish = function () {
        if (requests.get(form) !== entry) { return; }
        if (signal) { signal.removeEventListener("abort", abort); }
        fields.forEach(function (control, index) { control.disabled = disabled[index]; });
        if (form.classList) { form.classList.toggle("is-loading", wasLoading); }
        if (previousBusy === null) { form.removeAttribute("aria-busy"); } else { form.setAttribute("aria-busy", previousBusy); }
        requests.delete(form);
      };
      entry.promise = TinyCat.api.request(url, Object.assign({}, options, { signal: controller.signal })).finally(entry.finish);
      requests.set(form, entry);
      return entry.promise;
    },
    cancel: function (form) { if (requests.has(form)) { var entry = requests.get(form); entry.controller.abort(); entry.finish(); } },
    dispose: function (form) { submitGuards.delete(form); if (bindings.has(form)) { bindings.get(form)(); } else { TinyCat.forms.cancel(form); } },
    bind: function (form, options) {
      if (bindings.has(form)) { bindings.get(form)(); }
      var pending = false;
      var disposed = false;
      var settings = options || {};
      var listener = async function (event) {
        event.preventDefault();
        if (pending || !TinyCat.forms.validate(form)) {
          return;
        }
        pending = true;
        try {
          if (!await TinyCat.forms.canSubmit(form) || disposed) { return; }
          var method = String(settings.method || form.method || "POST").toUpperCase();
          var body = TinyCat.forms.data(form, event.submitter);
          var url = new URL(settings.url || form.action, window.location.href);
          if (method === "GET") {
            url = new URL(TinyCat.navigation.formUrl(url.href, body, [], true), window.location.href);
          }
          var result = await TinyCat.forms.request(form, url.href, {
            method: method,
            body: method === "GET" ? undefined : body
          });
          if (!disposed && settings.apply) {
            await settings.apply(result.data, result);
          }
        } catch (error) {
          if (!disposed && error.name !== "AbortError") {
            if (settings.error) {
              settings.error(error, TinyCat.api.errors(error));
            } else {
              form.dispatchEvent(new CustomEvent("tinycat:api-error", { bubbles: true, detail: { error: error, fields: TinyCat.api.errors(error) } }));
            }
          }
        } finally {
          pending = false;
        }
      };
      form.addEventListener("submit", listener);
      var dispose = function () {
        disposed = true;
        TinyCat.forms.cancel(form);
        form.removeEventListener("submit", listener);
        bindings.delete(form);
      };
      bindings.set(form, dispose);
      return dispose;
    }
  };
}());
