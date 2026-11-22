import "./lifecycle.js";

(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {}, modules = new Map();
  function hooks(phase, root) {
    var ok = true;
    modules.forEach(function (module, name) {
      if (!T.lifecycle.call(name, module, phase, root)) { ok = false; }
    });
    return ok;
  }
  function first(phase, args, fallback) {
    for (var module of modules.values()) {
      var value = typeof module[phase] === "function" && module[phase].apply(module, args);
      if (value) { return value; }
    }
    return fallback;
  }
  T.modules = {
    register: function (name, module) {
      if (modules.has(name)) { throw new TypeError("Duplicate client module: " + name); }
      modules.set(name, module);
    },
    result: function (source, response, target) {
      return first("result", [source, response, target], false);
    },
    hydrate: function (root) { hooks("hydrate", root); },
    dispose: function (root) { hooks("dispose", root); },
    modal: function (url, response, id) {
      return first("modal", [url, response, id], null);
    },
    navigationUrl: function (trigger) { return first("navigationUrl", [trigger], ""); },
    init: function () { return hooks("init"); }
  };
}());
