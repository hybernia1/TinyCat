(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  var bindings = new WeakMap();

  TinyCat.forms = {
    bind: function (form, options) {
      if (bindings.has(form)) { bindings.get(form)(); }
      var pending = false;
      var disposed = false;
      var controller = null;
      var settings = options || {};
      var listener = async function (event) {
        event.preventDefault();
        if (pending || !form.reportValidity()) {
          return;
        }
        pending = true;
        controller = new AbortController();
        var previousBusy = form.getAttribute("aria-busy");
        var buttons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
        var disabled = buttons.map(function (button) { return button.disabled; });
        buttons.forEach(function (button) { button.disabled = true; });
        form.setAttribute("aria-busy", "true");
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
          var result = await TinyCat.api.request(url.href, {
            method: method,
            body: method === "GET" ? undefined : body,
            signal: controller.signal
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
          buttons.forEach(function (button, index) { button.disabled = disabled[index]; });
          if (previousBusy === null) {
            form.removeAttribute("aria-busy");
          } else {
            form.setAttribute("aria-busy", previousBusy);
          }
          pending = false;
          controller = null;
        }
      };
      form.addEventListener("submit", listener);
      var dispose = function () {
        disposed = true;
        if (controller) { controller.abort(); }
        form.removeEventListener("submit", listener);
        bindings.delete(form);
      };
      bindings.set(form, dispose);
      return dispose;
    }
  };
}());
