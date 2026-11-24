import "./runtime/lifecycle.js";
import "./runtime/modules.js";
import "./runtime/navigation.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.lifecycle.register("modules", { init: TinyCat.modules.init, hydrate: TinyCat.modules.hydrate, hydrateOrder: -10, dispose: TinyCat.modules.dispose });
  TinyCat.navigation.help(TinyCat.urls.local("/privacy", TinyCat.i18n.locale()));
  TinyCat.init = TinyCat.lifecycle.start;
  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", TinyCat.init, { once: true }); }
  else { TinyCat.init(); }
}());
