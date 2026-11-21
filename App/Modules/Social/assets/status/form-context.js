import "../../../../Core/Ui/assets/runtime/dom.js";
import "./controller.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  function statusFormAction(form) {
    var field = form && form.elements ? form.elements.namedItem("action") : null;
    return field ? String(field.value || "create") : "create";
  }
  function statusFormCard(form) {
    var card = form ? form.closest(".status-card") : null;
    var id = form && form.dataset ? form.dataset.statusId : "";
    var field;

    if (card) {
      return card;
    }

    if (!id && form) {
      field = qs('input[name="id"]', form);
      id = field ? field.value : "";
    }

    return id ? document.getElementById("status-" + id) : null;
  }
  TinyCat.status.controllers.forms = { statusFormAction: statusFormAction, statusFormCard: statusFormCard };
}());
