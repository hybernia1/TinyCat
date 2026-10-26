(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};

  TinyCat.dom = {
    element: function (tag, attributes, text) {
      if (!/^[a-z][a-z0-9-]*$/.test(tag) || /^(script|style|iframe|object|embed|base|link|meta)$/.test(tag)) {
        throw new TypeError("Unsupported UI element.");
      }
      var element = document.createElement(tag);
      Object.keys(attributes || {}).forEach(function (name) {
        var value = attributes[name];
        if (!/^[a-z][a-z0-9:_-]*$/i.test(name) || /^on/i.test(name) || /^(srcdoc|style|innerHTML)$/i.test(name)) {
          throw new TypeError("Unsupported UI attribute.");
        }
        if (/^(href|src|action|formaction)$/i.test(name) && value !== null && value !== false) {
          var url = new URL(String(value), window.location.href);
          if (url.protocol !== "http:" && url.protocol !== "https:" && !(name.toLowerCase() === "href" && /^(mailto:|tel:)$/.test(url.protocol))) {
            throw new TypeError("Unsupported UI URL.");
          }
        }
        if (value !== false && value !== null && value !== undefined) {
          element.setAttribute(name, value === true ? "" : String(value));
        }
      });
      if (text !== undefined && text !== null) {
        element.textContent = String(text);
      }
      return element;
    }
  };
}());
