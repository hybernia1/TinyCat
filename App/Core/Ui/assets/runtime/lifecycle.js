
(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {}, services = new Map(), started = false, preparationDepth = 0;
  T.lifecycle = {
    register: function (name, service) {
      if (services.has(name)) { throw new TypeError("Duplicate UI service: " + name); }
      services.set(name, service);
    },
    start: function () {
      if (started) { return; }
      started = true;
      preparationDepth++;
      try { services.forEach(function (service) { if (service.init) { service.init(); } }); }
      finally { preparationDepth--; }
      T.lifecycle.hydrate(document);
    },
    hydrate: function (root) {
      preparationDepth++;
      try {
        Array.from(services.values()).sort(function (a, b) { return (a.hydrateOrder || 0) - (b.hydrateOrder || 0); }).forEach(function (service) {
          if (service.hydrate) { service.hydrate(root || document); }
        });
      } finally { preparationDepth--; }
    },
    preparing: function () { return preparationDepth > 0; },
    dispose: function (root) { Array.from(services.values()).reverse().forEach(function (service) { if (service.dispose) { service.dispose(root); } }); }
  };
}());
