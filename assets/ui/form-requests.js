import "../core/dom.js";
import "../core/api-client.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  var qsa = TinyCat.dom.all;
  var bindings = new WeakMap();
  var requests = new WeakMap();

  function controls(element) {
    return element.elements
      ? Array.from(element.elements).filter(function (field) { return "disabled" in field; })
      : Array.from(qsa("button, input, select, textarea", element));
  }

  function setLoading(element, loading) {
    if (!element) {
      return;
    }

    element.classList.toggle("is-loading", loading);
    element.setAttribute("aria-busy", loading ? "true" : "false");

    controls(element).forEach(function (field) {
      if (loading) {
        field.dataset.tinycatWasDisabled = field.disabled ? "true" : "false";
        field.disabled = true;
      } else if (field.dataset.tinycatWasDisabled !== "true") {
        field.disabled = false;
        delete field.dataset.tinycatWasDisabled;
      }
    });
  }

  TinyCat.forms = {
    loading: setLoading,
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
    dispose: function (form) { if (bindings.has(form)) { bindings.get(form)(); } else { TinyCat.forms.cancel(form); } },
    bind: function (form, options) {
      if (bindings.has(form)) { bindings.get(form)(); }
      var pending = false;
      var disposed = false;
      var settings = options || {};
      var listener = async function (event) {
        event.preventDefault();
        if (pending || !form.reportValidity()) {
          return;
        }
        pending = true;
        try {
          var method = String(settings.method || form.method || "POST").toUpperCase();
          var body = new FormData(form);
          var url = new URL(settings.url || form.action, window.location.href);
          if (method === "GET") {
            var names = new Set();
            body.forEach(function (value, name) {
              if (typeof value === "string") {
                if (!names.has(name)) { url.searchParams.delete(name); names.add(name); }
                url.searchParams.append(name, value);
              }
            });
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
