import "../core/dom.js";
import "../core/i18n.js";
import "../core/icons.js";
import "../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.toasts = {};
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var uiText = TinyCat.i18n.label;
  function positionStack() {
    var stack = qs(".toast-stack"), viewport = window.visualViewport;
    if (!stack) return;
    if (!viewport || viewport.width <= 0 || viewport.height <= 0) {
      stack.style.transform = "";
      stack.style.maxWidth = "";
      return;
    }
    // Fixed elements use the layout viewport, which can extend behind the mobile keyboard.
    var x = viewport.offsetLeft + viewport.width - window.innerWidth;
    var y = Math.min(0, viewport.offsetTop + viewport.height - window.innerHeight);
    stack.style.transform = "translate(" + Math.round(x) + "px, " + Math.round(y) + "px)";
    stack.style.maxWidth = Math.max(0, viewport.width - 20) + "px";
  }
  function toastIconName(type) {
    if (type === "success") {
      return "check-circle";
    }

    if (type === "danger") {
      return "x-circle";
    }

    if (type === "warning") {
      return "alert";
    }

    return "info";
  }
  function normalizeToastType(type) {
    type = String(type || "info").toLowerCase();

    if (type === "error") {
      return "danger";
    }

    return ["success", "danger", "warning", "info"].indexOf(type) === -1 ? "info" : type;
  }
  function createToastIcon(type) {
    var icon = document.createElement("span");

    icon.className = "toast-icon";
    icon.setAttribute("aria-hidden", "true");
    icon.appendChild(TinyCat.icons.create(toastIconName(type)));

    return icon;
  }
  TinyCat.toasts.show = function (message, type, timeout) {
    var stack = qs(".toast-stack");
    var normalizedType = normalizeToastType(type);
    var closeTimeout;

    if (!stack) {
      stack = document.createElement("div");
      stack.className = "toast-stack";
      stack.setAttribute("aria-live", "polite");
      stack.setAttribute("aria-atomic", "true");
      document.body.appendChild(stack);
    }

    var toast = document.createElement("div");
    var body = document.createElement("div");
    var close = document.createElement("button");

    toast.className = "toast toast-" + normalizedType;
    toast.setAttribute("role", normalizedType === "danger" || normalizedType === "warning" ? "alert" : "status");
    body.className = "toast-body";
    body.textContent = String(message || "");
    close.className = "toast-close";
    close.type = "button";
    close.setAttribute("aria-label", uiText("close", "Close"));
    close.appendChild(TinyCat.icons.create("close"));

    toast.appendChild(createToastIcon(normalizedType));
    toast.appendChild(body);
    toast.appendChild(close);
    stack.appendChild(toast);
    positionStack();

    function removeToast() {
      window.clearTimeout(closeTimeout);
      toast.remove();
    }

    close.addEventListener("click", removeToast);
    closeTimeout = window.setTimeout(removeToast, timeout || (normalizedType === "danger" ? 5600 : 4200));

    toast.addEventListener("pointerenter", function (event) {
      if (event.pointerType === "mouse") window.clearTimeout(closeTimeout);
    });

    toast.addEventListener("pointerleave", function (event) {
      if (event.pointerType !== "mouse") return;
      window.clearTimeout(closeTimeout);
      closeTimeout = window.setTimeout(removeToast, 1800);
    });

    return toast;
  };
  function renderFlashMessages(scope) {
    qsa("[data-tinycat-flashes]", scope || document).forEach(function (node) {
      var messages;

      if (node.dataset.toastReady === "true") {
        return;
      }

      node.dataset.toastReady = "true";

      try {
        messages = JSON.parse(node.textContent || "[]");
      } catch (error) {
        messages = [];
      }

      if (Array.isArray(messages)) {
        messages.forEach(function (item) {
          TinyCat.toasts.show(item.message || "", item.type || "info");
        });
      }

      node.remove();
    });
  }
  TinyCat.toasts.init = function () {
    if (window.visualViewport) {
      window.visualViewport.addEventListener("resize", positionStack);
      window.visualViewport.addEventListener("scroll", positionStack);
    }
    window.addEventListener("resize", positionStack);
    document.addEventListener("tinycat:modal-open", positionStack);
    document.addEventListener("tinycat:modal-close", positionStack);
    renderFlashMessages(document);

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-toast]");

      if (!trigger) {
        return;
      }

      event.preventDefault();
      TinyCat.toasts.show(trigger.dataset.toast, trigger.dataset.toastType || "info");
    });
  };
  TinyCat.lifecycle.register("toasts", { init: function () { TinyCat.toasts.init(); ["tinycat:dialog-error", "tinycat:navigation-error"].forEach(function (name) { document.addEventListener(name, function (event) { TinyCat.toasts.show(event.detail.error.message, "danger"); }); }); } });
}());
