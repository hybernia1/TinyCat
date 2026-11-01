import "../core/lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.content = {
    // The caller prepares a detached view first, then hydrates the retained
    // container once after its domain-specific setup has completed.
    replaceChildren: function (root, view) {
      if (!(view instanceof Node) || view.isConnected ||
          view.contains(root) || root.contains(view)) {
        throw new TypeError("Expected a detached view.");
      }
      Array.from(root.children).forEach(T.lifecycle.dispose);
      root.replaceChildren(view);
    },
    remove: function (root) { T.lifecycle.dispose(root); root.remove(); },
    replaceNode: function (root, view) {
      if (!(view instanceof Node) || view.isConnected || view.contains(root) || root.contains(view)) { throw new TypeError("Expected a detached view."); }
      T.lifecycle.dispose(root); root.replaceWith(view);
    }
  };
}());
