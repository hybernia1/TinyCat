import "./presentation.js";
import "./editor-entry.js";
(function () { "use strict"; var P = window.TinyCat.publishing; P.hydrate = function (root) { P.presentation.hydrate(root); P.editing.hydrate(root); }; P.dispose = function (root) { P.presentation.dispose(root); P.editing.dispose(root); }; }());
