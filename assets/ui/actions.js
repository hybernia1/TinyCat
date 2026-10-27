import "../core/lifecycle.js";
import "./dialogs.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.actions = {};
  function confirmOptions(trigger) {
    return {
      title: trigger.dataset.confirmTitle || TinyCat.i18n.t("common.confirm_action"),
      message: trigger.dataset.confirm || "",
      confirmLabel: trigger.dataset.confirmOk || TinyCat.i18n.t("common.confirm"),
      cancelLabel: trigger.dataset.confirmCancel || TinyCat.i18n.t("common.cancel"),
      variant: trigger.dataset.confirmVariant || (trigger.classList.contains("btn-danger") ? "danger" : "")
    };
  }
  function confirmAction(trigger) {
    if (!trigger || !trigger.dataset.confirm) {
      return Promise.resolve(true);
    }

    return TinyCat.dialog.confirm(confirmOptions(trigger));
  }
  function shouldSubmitOnEnter(event) {
    return event.key === "Enter"
      && !event.shiftKey
      && !event.altKey
      && !event.ctrlKey
      && !event.metaKey
      && !event.isComposing
      && event.keyCode !== 229;
  }
  function submitForm(form) {
    if (!form || form.dataset.statusBusy === "true") {
      return;
    }

    if (form.requestSubmit) {
      form.requestSubmit();
      return;
    }

    form.submit();
  }
  TinyCat.actions.init = function () {
    document.addEventListener("click", async function (event) {
      var trigger = event.target.closest && event.target.closest("[data-confirm]:not(form):not([data-ajax])");

      if (!trigger || trigger.closest("form[data-ajax-form]")) {
        return;
      }

      event.preventDefault();

      if (!await confirmAction(trigger)) {
        return;
      }

      if (trigger.tagName === "A" && trigger.href) {
        window.location.assign(trigger.href);
        return;
      }

      if (trigger.type === "submit" && trigger.form) {
        trigger.form.__tinycatConfirmPass = true;

        if (trigger.form.requestSubmit) {
          trigger.form.requestSubmit(trigger);
        } else {
          trigger.form.submit();
        }
      }
    });

    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-confirm]:not([data-ajax-form]):not([data-status-form])");

      if (!form) {
        return;
      }

      if (form.__tinycatConfirmPass) {
        delete form.__tinycatConfirmPass;
        return;
      }

      event.preventDefault();

      if (await confirmAction(form)) {
        form.__tinycatConfirmPass = true;

        if (form.requestSubmit) {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
    });
  };

  Object.assign(TinyCat.actions, { confirm: confirmAction, submit: submitForm, submitOnEnter: shouldSubmitOnEnter });
  TinyCat.lifecycle.register("confirm-actions", { init: TinyCat.actions.init });
}());
