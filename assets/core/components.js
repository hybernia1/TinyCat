import "./dom.js";
import "./http.js";
import "./i18n.js";
import "./icons.js";
(function () {
  "use strict";
  var T = window.TinyCat, templates = Object.create(null);
  function truth(value) { return value !== null && value !== undefined && value !== false && value !== "" && value !== 0 && (!Array.isArray(value) || value.length > 0); }
  function own(object, key) { return Object.prototype.hasOwnProperty.call(object, key); }
  function value(expr, data) {
    if (expr === null || typeof expr !== "object") { return expr; }
    if (Array.isArray(expr)) { return expr.map(function (part) { return value(part, data); }); }
    if (own(expr, "get")) { return expr.get.split(".").reduce(function (result, key) { return result != null && Object.prototype.hasOwnProperty.call(result, key) ? result[key] : null; }, data); }
    if (own(expr, "string")) { var scalar = value(expr.string, data); return scalar == null ? "" : String(scalar); }
    if (own(expr, "json")) { return JSON.stringify(value(expr.json, data)); }
    if (own(expr, "count")) { var entries = Object.values(value(expr.count, data) || {}); return expr.where ? entries.filter(function (entry) { return truth(entry[expr.where]); }).length : entries.length; }
    if (own(expr, "t")) { return T.i18n.t(expr.t, bindings(expr.values || {}, data)); }
    if (own(expr, "concat")) { return expr.concat.map(function (part) { var result = value(part, data); return result == null ? "" : String(result); }).join(""); }
    if (own(expr, "if")) { return value(expr.if[truth(value(expr.if[0], data)) ? 1 : 2], data); }
    if (own(expr, "eq")) { return value(expr.eq[0], data) === value(expr.eq[1], data); }
    if (own(expr, "not")) { return !truth(value(expr.not, data)); }
    if (own(expr, "and")) { return expr.and.every(function (part) { return truth(value(part, data)); }); }
    if (own(expr, "or")) { for (var i = 0; i < expr.or.length; i++) { var result = value(expr.or[i], data); if (truth(result)) { return result; } } return null; }
    var output = Array.isArray(expr) ? [] : {};
    Object.keys(expr).forEach(function (key) { if (/^(?:__proto__|constructor|prototype)$/.test(key)) { throw new TypeError("Unsupported component key."); } output[key] = value(expr[key], data); }); return output;
  }
  function bindings(expressions, data) {
    var output = {};
    Object.keys(expressions).forEach(function (key) {
      if (/^(?:__proto__|constructor|prototype)$/.test(key)) { throw new TypeError("Unsupported component key."); }
      output[key] = value(expressions[key], data);
    });
    return output;
  }
  function rich(parent, nodes) {
    (nodes || []).forEach(function (node) {
      if (node.type === "text") { parent.appendChild(document.createTextNode(node.text)); return; }
      if (node.type !== "element" || !/^(p|br|strong|em|a|ul|ol|li|span|div|small|b|i)$/.test(node.name)) { return; }
      var attributes = {};
      if (node.name === "a" && node.attributes && node.attributes.href) {
        attributes.href = node.attributes.href; attributes.rel = "noopener noreferrer";
      }
      var child = T.dom.element(node.name, attributes); rich(child, node.children); parent.appendChild(child);
    });
  }
  function node(spec, data) {
    if (spec === null || typeof spec !== "object") { return document.createTextNode(spec == null ? "" : String(spec)); }
    var fragment;
    if (Array.isArray(spec)) { fragment = document.createDocumentFragment(); spec.forEach(function (child) { fragment.appendChild(node(child, data)); }); return fragment; }
    if (Object.prototype.hasOwnProperty.call(spec, "when") && !truth(value(spec.when, data))) { return document.createDocumentFragment(); }
    if (spec.each) { fragment = document.createDocumentFragment(); var items = value(spec.each, data) || {}; Object.keys(items).forEach(function (key) { var scope = Object.assign({}, data, { _key: Array.isArray(items) ? Number(key) : key }); scope[spec.as || "entry"] = items[key]; fragment.appendChild(node(spec.children, scope)); }); return fragment; }
    if (spec.component) { return T.components.render(spec.component, bindings(spec.data || {}, data)); }
    if (spec.icon) { var icon = T.icons.create(value(spec.icon, data)); if (spec.class) { icon.setAttribute("class", spec.class); } return icon; }
    if (spec.csrf) { return T.dom.element("input", { type: "hidden", name: "_csrf", value: T.api.csrfToken() }); }
    if (spec.rich) { fragment = document.createDocumentFragment(); rich(fragment, value(spec.rich, data)); return fragment; }
    if (spec.slot) { var content = value(spec.slot, data); if (!(content instanceof Node)) { throw new TypeError("A browser component slot requires a DOM node."); } return content; }
    if (!spec.tag) { var text = value(spec, data); return document.createTextNode(text == null ? "" : String(text)); }
    var tag = value(spec.tag, data), root = T.dom.element(tag, value(spec.attrs || {}, data));
    root.appendChild(node(spec.children || [], data));
    if (tag === "textarea") { root.value = root.textContent; }
    return root;
  }
  T.components = {
    rich: rich,
    register: function (definitions) {
      if (!definitions || Array.isArray(definitions) || typeof definitions !== "object") { throw new TypeError("Invalid component catalog."); }
      Object.keys(definitions).forEach(function (name) {
        if (!/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/.test(name) || Object.prototype.hasOwnProperty.call(templates, name)) { throw new TypeError("Invalid or duplicate component: " + name); }
        templates[name] = definitions[name];
      });
    },
    render: function (name, data) {
      if (!Object.prototype.hasOwnProperty.call(templates, name)) { throw new TypeError("Unknown component: " + name); }
      return node(templates[name], data || {});
    }
  };
}());
