import "./index.js";
import "../../../../assets/ui/list-navigation.js";
(function () {
  "use strict";
  var T = window.TinyCat, baseResult = T.accounts.result;
  T.accounts.result = function (source, response, target) {
    var definition = (T.adminDefinitions || {})["core.users"];
    if (definition && target && document.querySelector(definition.target) === target) {
      var result = T.lists.result(definition.target, source, response, target);
      if (result !== false) { return result; }
    }
    return baseResult(source, response, target);
  };
}());
