import "./runtime/dom.js";
import "./runtime/lifecycle.js";
import "./form-requests.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat, qsa = TinyCat.dom.all, encoder, selector = "[data-min-bytes], [data-max-bytes], [data-max-length], [data-min-length]";
  function syncConstraints(scope) {
    (scope && scope.matches && scope.matches(selector) ? [scope] : qsa(selector, scope)).forEach(function (field) {
      if (typeof field.setCustomValidity !== "function") { return; }
      var value = String(field.value || ""), length = field.hasAttribute("data-max-length") || field.hasAttribute("data-min-length") ? Array.from(value).length : 0;
      var invalid = (field.hasAttribute("data-max-length") && length > Number(field.dataset.maxLength))
        || (field.hasAttribute("data-min-length") && length < Number(field.dataset.minLength))
        || (field.hasAttribute("data-min-bytes") && value !== "" && (encoder || (encoder = new TextEncoder())).encode(value).length < Number(field.dataset.minBytes))
        || (field.hasAttribute("data-max-bytes") && (encoder || (encoder = new TextEncoder())).encode(value).length > Number(field.dataset.maxBytes));
      if (invalid || field.dataset.tinycatConstraintInvalid === "true") {
        field.setCustomValidity(invalid ? TinyCat.i18n.t("common.validation_invalid", { field: field.name }) : "");
        if (invalid) { field.dataset.tinycatConstraintInvalid = "true"; } else { delete field.dataset.tinycatConstraintInvalid; }
      }
    });
  }
  function init() {
    ["input", "change", "tinycat:editor-sync"].forEach(function (name) {
      document.addEventListener(name, function (event) {
        var field = event.target, form = field.form || (field.closest && field.closest("form"));
        if (form) { syncConstraints((field.dataset.validationFor && form.elements.namedItem(field.dataset.validationFor)) || field); }
      });
    });
    document.addEventListener("click", function (event) {
      var submit = event.target.closest && event.target.closest('button[type="submit"], input[type="submit"], button:not([type])');
      if (submit && submit.form) { syncConstraints(submit.form); }
    }, true);
  }
  TinyCat.forms.syncConstraints = syncConstraints;
  TinyCat.lifecycle.register("form-constraints", { init: init, hydrate: syncConstraints, hydrateOrder: 11 });
}());
