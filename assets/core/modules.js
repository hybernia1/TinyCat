
(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {}, modules = new Map();
  T.modules = {
    register: function (name, module) {
      if (modules.has(name)) { throw new TypeError("Duplicate client module: " + name); }
      modules.set(name, module);
    },
    result: function (source, response, target) {
      return Array.from(modules.values()).some(function (module) { return typeof module.result === "function" && module.result(source, response, target); });
    },
    hydrate: function (root) { modules.forEach(function (module) { if (typeof module.hydrate === "function") { module.hydrate(root); } }); },
    dispose: function (root) { modules.forEach(function (module) { if (typeof module.dispose === "function") { module.dispose(root); } }); },
    modal: function (url, response, id) {
      var dialog = null;
      modules.forEach(function (module) { if (!dialog && typeof module.modal === "function") { dialog = module.modal(url, response, id); } });
      return dialog;
    },
    navigationUrl: function (trigger) {
      var url = "";
      modules.forEach(function (module) { if (!url && typeof module.navigationUrl === "function") { url = module.navigationUrl(trigger); } });
      return url;
    },
    init: function () { modules.forEach(function (module) { if (typeof module.init === "function") { module.init(); } }); }
  };
}());
