import "../../core/dom.js";
import "./controller.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  function statusFormActionField(form) {
    var field = form && form.elements ? form.elements.namedItem("action") : null;

    if (field instanceof RadioNodeList) {
      field = field[0] || null;
    }

    if (!field && form) {
      field = qs('[name="action"]', form);
    }

    return field ? String(field.value || "") : "";
  }
  function statusFormAction(form, body) {
    var data = body;
    var value = "";

    try {
      if (!data) {
        data = new FormData(form);
      }

      value = String(data.get("action") || "");
    } catch (error) {
      value = "";
    }

    return value || statusFormActionField(form) || "create";
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
  function statusCardAction(form) {
    var card = statusFormCard(form);
    var action = card && card.dataset ? (card.dataset.statusAction || "") : "";
    var url;

    if (action) {
      return action;
    }

    url = new URL(window.location.href);

    return url.pathname + url.search;
  }
  TinyCat.status.controllers.forms = { statusFormActionField: statusFormActionField, statusFormAction: statusFormAction, statusFormCard: statusFormCard, statusCardAction: statusCardAction };
}());
