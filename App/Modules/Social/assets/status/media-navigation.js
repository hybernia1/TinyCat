import "../../../../Core/Ui/assets/content/media.js";
import "./controller.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat, compactUrl = TinyCat.navigation.compact;
  function mobileStatusUrl(trigger) {
    var parent;
    var modalTarget;
    var card;
    var href;
    var statusId;

    if (!trigger || !window.matchMedia || !window.matchMedia("(max-width: 899px)").matches) {
      return "";
    }

    parent = trigger.closest("[data-modal-parent-open]");
    modalTarget = String(trigger.dataset.modalOpen || (parent ? parent.dataset.modalParentOpen : ""));
    if (modalTarget.indexOf("status-post-modal-") !== 0) {
      return "";
    }

    href = String(trigger.getAttribute("href") || "");
    if (href.indexOf("/status/") === 0) {
      return mobileStatusPageUrl(href);
    }

    card = trigger.closest(".status-card");
    if (card && card.dataset.statusUrl) {
      return mobileStatusPageUrl(String(card.dataset.statusUrl));
    }

    statusId = parseInt(modalTarget.slice("status-post-modal-".length), 10) || 0;
    return statusId > 0 ? mobileStatusPageUrl("/status/" + statusId) : "";
  }
  function mobileStatusPageUrl(value) {
    var url = new URL(value, window.location.origin);

    url.searchParams.set("compact", "1");
    return compactUrl(url);
  }
  TinyCat.status.navigation = { mobileStatusUrl: mobileStatusUrl, mobileStatusPageUrl: mobileStatusPageUrl };
}());
