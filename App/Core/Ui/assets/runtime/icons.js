
(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {};
  T.icons = {
    create: function (name) {
      if (!/^[A-Za-z0-9_-]+$/.test(name)) { throw new TypeError("Invalid icon name."); }
      var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      var use = document.createElementNS("http://www.w3.org/2000/svg", "use");
      svg.setAttribute("class", "icon"); svg.setAttribute("aria-hidden", "true"); svg.setAttribute("focusable", "false");
      use.setAttribute("href", (document.body.getAttribute("data-icon-sprite") || "") + "#" + name);
      svg.appendChild(use); return svg;
    }
  };
}());
