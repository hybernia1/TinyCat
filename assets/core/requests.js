import "./lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat, pending = new Map();
  function cancel(owner) {
    var request = pending.get(owner);
    if (!request) { return; }
    pending.delete(owner);
    if (request.controller) { request.controller.abort(); }
  }
  T.requests = {
    cancel: cancel,
    begin: function (owner, abortable) {
      cancel(owner);
      var generation = Number(owner.dataset.apiGeneration || 0) + 1;
      owner.dataset.apiGeneration = String(generation);
      var controller = abortable === false ? null : new AbortController();
      var request = {
        controller: controller,
        signal: controller ? controller.signal : undefined,
        current: function () { return pending.get(owner) === request && owner.isConnected !== false && Number(owner.dataset.apiGeneration) === generation; },
        finish: function () { if (pending.get(owner) === request) { pending.delete(owner); } }
      };
      pending.set(owner, request);
      return request;
    }
  };
  T.lifecycle.register("requests", { dispose: function (root) {
    pending.forEach(function (_request, owner) { if (root === owner || (root.contains && root.contains(owner))) { cancel(owner); } });
  } });
}());
