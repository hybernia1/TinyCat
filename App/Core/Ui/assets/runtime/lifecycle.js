
(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {}, services = new Map(), started = false, starting = false, initialized = new WeakSet(), preparationDepth = 0;
  function ordered() { return Array.from(services.values()).sort(function (a, b) { return (a.hydrateOrder || 0) - (b.hydrateOrder || 0); }); }
  function call(name, service, phase, root) {
    if (phase === "init" && initialized.has(service)) { return true; }
    try {
      var ok = !service[phase] || service[phase](root) !== false;
      if (ok && phase === "init") { initialized.add(service); }
      return ok;
    }
    catch (error) { if (window.reportError) { window.reportError(error); } else { console.error("UI " + name + ": " + phase, error); } return false; }
  }
  T.lifecycle = {
    register: function (name, service) {
      if (services.has(name)) { throw new TypeError("Duplicate UI service: " + name); }
      services.set(name, service);
    },
    call: call,
    start: function () {
      if (started || starting) { return; }
      starting = true; preparationDepth++;
      try {
        started = true;
        services.forEach(function (service, name) { if (!call(name, service, "init")) { started = false; } });
        T.lifecycle.hydrate(document);
      } finally { preparationDepth--; starting = false; }
    },
    hydrate: function (root) {
      preparationDepth++;
      try { ordered().forEach(function (service) { call("service", service, "hydrate", root || document); }); }
      finally { preparationDepth--; }
    },
    preparing: function () { return preparationDepth > 0; },
    dispose: function (root) { ordered().reverse().forEach(function (service) { call("service", service, "dispose", root); }); }
  };
}());
