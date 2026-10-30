import "../core/dom.js";
import "../core/http.js";
import "../core/i18n.js";
import "../core/lifecycle.js";
import "../core/modules.js";
import "../core/navigation.js";
import "./actions.js";
import "./dialogs.js";
import "./fields.js";
import "./form-state.js";
import "./forms.js";
import "./toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.ajax = {};
  var qs = TinyCat.dom.find;
  var emit = TinyCat.dom.emit;
  var hydrateDynamic = TinyCat.lifecycle.hydrate;
  var clearErrors = TinyCat.forms.clearErrors;
  var applyErrors = TinyCat.forms.applyErrors;
  var resetForm = TinyCat.forms.reset;
  var markFormClean = TinyCat.forms.markClean;
  var setLoading = TinyCat.forms.loading;
  var isFocusableVisible = TinyCat.dom.visible;
  var urlWithFormData = TinyCat.navigation.formUrl;
  var historyUrl = TinyCat.navigation.historyUrl;
  var pushHistory = TinyCat.navigation.push;
  var currentAjaxUrl = TinyCat.navigation.currentAjaxUrl;
  var uiText = TinyCat.i18n.label;
  var unwrapResult = TinyCat.api.unwrap;
  var resultErrors = TinyCat.api.errors;
  var confirmAction = TinyCat.actions.confirm;
  function preserveListSorting(url, target) {
    if (!url || !target || !["users-list", "bot-accounts-list", "bots-list", "bot-queue-list", "scheduler-list", "moderation-reports"].includes(target.id)) { return url; }
    var next = new URL(url, window.location.href);
    var current = new URL(window.location.href);
    ["sort", "direction"].forEach(function (key) {
      if (!next.searchParams.has(key) && current.searchParams.has(key)) { next.searchParams.set(key, current.searchParams.get(key)); }
    });
    return TinyCat.navigation.compact(next);
  }
  function refreshFormCaptcha(form, data) {
    var details = resultErrorDetails(data);
    if (details.captcha) { TinyCat.ui.captcha(form, details.captcha); }
  }
  function handleResult(source, data, target) {
    var payload = unwrapResult(data);
    var filterState = TinyCat.filters ? TinyCat.filters.capture(source) : null;
    var modalToClose = source && source.dataset && source.dataset.modalCloseOnSuccess === "true"
      ? source.closest(".modal")
      : null;

    if (data && typeof data === "object") {
      var redirect = data.redirect || (payload && typeof payload === "object" ? payload.redirect : "");

      if (redirect) {
        // A successful form submission may intentionally navigate to its
        // canonical page. It is no longer an unsaved draft, so let the
        // global beforeunload guard allow that redirect.
        markFormClean(source);
        window.location.assign(TinyCat.urls.local(redirect));
        return;
      }

      var message = data.message || (payload && typeof payload === "object" ? payload.message : "");
      var type = data.type
        || (data.meta && data.meta.type)
        || (payload && typeof payload === "object" ? (payload.type || (payload.meta && payload.meta.type)) : "")
        || (data.ok === false ? "danger" : "success");

      if (message) {
        TinyCat.toasts.show(message, type);
      }
    }

    TinyCat.modules.result(source, data, target);

    hydrateDynamic(target || document);
    if (filterState) { TinyCat.filters.restore(filterState); }
    resetForm(source);
    markFormClean(source);

    if (modalToClose && !TinyCat.forms.dirty(modalToClose).length) {
      TinyCat.dialog.close(modalToClose);
    }

    emit(source, "tinycat:success", { data: data, payload: payload, target: target });
  }
  TinyCat.ajax.init = function () {
    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-ajax-form]");
      var invalidField = null;

      if (!form) {
        return;
      }

      event.preventDefault();
      if (form.dataset.apiBusy === "true") { return; }
      form.dataset.apiBusy = "true";

      if (form.dataset.confirm && !(await confirmAction(form))) {
        delete form.dataset.apiBusy;
        return;
      }

      clearErrors(form);

      var target = form.dataset.ajaxTarget ? qs(form.dataset.ajaxTarget) : null;
      var method = (form.dataset.apiMethod || form.getAttribute("method") || "POST").toUpperCase();
      var action = form.dataset.ajaxAction || form.getAttribute("action") || window.location.href;
      var body = new FormData(form);
      var headers = {};
      var override = body.get("_method");
      var url = action;
      var nextHistory = "";
      var requestOptions;
      method = String(form.dataset.apiMethod || override || method).toUpperCase();
      body.delete("_method");

      if (method === "GET") {
        url = urlWithFormData(action, body, []);
        nextHistory = historyUrl(form, action, body);
        body = null;
      }
      url = preserveListSorting(url, target);
      nextHistory = preserveListSorting(nextHistory, target);

      requestOptions = {
        method: method,
        headers: headers
      };

      if (body) {
        requestOptions.body = body;
      }

      var generation = target ? (Number(target.dataset.apiGeneration || 0) + 1) : 0;
      if (target) { target.dataset.apiGeneration = String(generation); }
      try {
        emit(form, "tinycat:before", { target: target });
        var data = await TinyCat.forms.request(form, url, requestOptions);
        if (target && Number(target.dataset.apiGeneration) !== generation) { return; }
        handleResult(form, data, target);
        pushHistory(nextHistory);
      } catch (error) {
        if (error.name === "AbortError") { return; }
        var errors = resultErrors(error);

        refreshFormCaptcha(form, error.data);

        if (errors) {
          invalidField = applyErrors(form, errors);
        }

        TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
        emit(form, "tinycat:error", { error: error, target: target });
      } finally {
        delete form.dataset.apiBusy;

        if (invalidField && invalidField.isConnected && invalidField.focus && isFocusableVisible(invalidField)) {
          invalidField.focus();
        }
      }
    });

    document.addEventListener("click", async function (event) {
      var link = event.target.closest && event.target.closest("[data-ajax]");
      var url;
      var target;
      var method;
      var headers;
      var nextHistory;

      if (!link) {
        return;
      }

      event.preventDefault();

      if (link.dataset.confirm && !await confirmAction(link)) {
        return;
      }

      url = link.dataset.ajaxCurrent === "true" ? currentAjaxUrl(link) : (link.dataset.url || link.getAttribute("href"));
      target = link.dataset.ajaxTarget ? qs(link.dataset.ajaxTarget) : null;
      method = (link.dataset.method || "GET").toUpperCase();
      headers = {};
      nextHistory = historyUrl(link, url);
      url = preserveListSorting(url, target);
      nextHistory = preserveListSorting(nextHistory, target);

      if (target && !await TinyCat.forms.confirmDiscard(TinyCat.forms.dirty(target))) {
        return;
      }

      var generation = target ? (Number(target.dataset.apiGeneration || 0) + 1) : 0;
      if (target) { target.dataset.apiGeneration = String(generation); }
      try {
        setLoading(link, true);
        emit(link, "tinycat:before", { target: target });
        var data = await TinyCat.api.request(url, { method: method, headers: headers });
        if (target && Number(target.dataset.apiGeneration) !== generation) { return; }
        handleResult(link, data, target);
        pushHistory(nextHistory);
      } catch (error) {
        TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
        emit(link, "tinycat:error", { error: error, target: target });
      } finally {
        setLoading(link, false);
      }
    });
  };
  TinyCat.ajax.autoSubmit = function () {
    if (TinyCat.__autoSubmitEventsBound === true) {
      return;
    }

    TinyCat.__autoSubmitEventsBound = true;

    document.addEventListener("change", function (event) {
      var field = event.target.closest && event.target.closest("[data-submit-on-change]");

      if (!field || !field.form) {
        return;
      }

      if (field.form.hasAttribute("data-filter-content")) {
        field.form.dataset.filterFocus = field.getAttribute("name") || "";
      }

      if (field.form.requestSubmit) {
        field.form.requestSubmit();
        return;
      }

      field.form.submit();
    });
  };
  var resultErrorDetails = TinyCat.api.details;

  TinyCat.lifecycle.register("ajax", { init: function () { TinyCat.ajax.init(); TinyCat.ajax.autoSubmit(); } });
}());
