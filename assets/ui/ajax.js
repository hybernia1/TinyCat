import "./lists.js";
import "../core/requests.js";
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
  var urlWithFormData = TinyCat.navigation.formUrl;
  var historyUrl = TinyCat.navigation.historyUrl;
  var pushHistory = TinyCat.navigation.push;
  var currentAjaxUrl = TinyCat.navigation.currentAjaxUrl;
  var uiText = TinyCat.i18n.label;
  var unwrapResult = TinyCat.api.unwrap;
  var resultErrors = TinyCat.api.errors;
  var confirmAction = TinyCat.actions.confirm;
  function refreshFormCaptcha(form, data) {
    var details = resultErrorDetails(data);
    if (details.captcha) { TinyCat.ui.captcha(form, details.captcha); }
  }
  function handleResult(source, data, target) {
    var payload = unwrapResult(data);
    var filterState = TinyCat.filters ? TinyCat.filters.capture(source) : null;
    var menuState = TinyCat.menus && TinyCat.menus.capture ? TinyCat.menus.capture(source) : null;
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

    var roots = TinyCat.modules.result(source, data, target);
    if (Array.isArray(roots)) {
      roots = Array.from(new Set(roots)).filter(function (root) { return root && root.isConnected !== false; });
      roots.filter(function (root) { return !roots.some(function (parent) { return parent !== root && parent.contains(root); }); }).forEach(hydrateDynamic);
    } else { hydrateDynamic(target || document); }
    if (filterState) { TinyCat.filters.restore(filterState); }
    if (menuState) { TinyCat.menus.restore(menuState); }
    resetForm(source);
    markFormClean(source);

    if (modalToClose && !TinyCat.forms.dirty(modalToClose).length) {
      TinyCat.dialog.close(modalToClose);
    }

    emit(source, "tinycat:success", { data: data, payload: payload, target: target });
  }
  var mutationLocks = new Set(), loadingLinks = new WeakMap();
  function connected(source, target) {
    return source.isConnected !== false && (!target || target.isConnected !== false);
  }
  function beginRequest(source, target, method) {
    var read = method === "GET" || method === "HEAD";
    var request = TinyCat.requests.begin(read ? target || source : source, read);
    var generation = null;
    if (!read && target && target !== source) {
      TinyCat.requests.cancel(target);
      generation = Number(target.dataset.apiGeneration || 0) + 1;
      target.dataset.apiGeneration = String(generation);
    }
    return {
      signal: request.signal,
      current: function () { return request.current() && connected(source, target) && (generation === null || Number(target.dataset.apiGeneration) === generation); },
      finish: request.finish
    };
  }
  TinyCat.ajax.init = function () {
    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-ajax-form]");
      if (!form) { return; }
      event.preventDefault();
      if (form.dataset.apiBusy === "true") { return; }
      form.dataset.apiBusy = "true";
      var target = form.dataset.ajaxTarget ? qs(form.dataset.ajaxTarget) : null;
      var request = null, invalidField = null;
      try {
        if ((form.dataset.ajaxTarget && !target) || !connected(form, target)) { return; }
        var method = (form.dataset.apiMethod || form.getAttribute("method") || "POST").toUpperCase();
        var action = form.dataset.ajaxAction || form.getAttribute("action") || window.location.href;
        var body = new FormData(form);
        method = String(form.dataset.apiMethod || body.get("_method") || method).toUpperCase();
        body.delete("_method");
        var url = action, nextHistory = "";
        if (method === "GET") {
          url = urlWithFormData(action, body, []);
          nextHistory = historyUrl(form, action, body);
          body = null;
        }
        url = TinyCat.lists.url(url, target);
        nextHistory = TinyCat.lists.url(nextHistory, target);
        if (method === "GET" || method === "HEAD") { request = beginRequest(form, target, method); }
        if (form.dataset.confirm && !await confirmAction(form)) { return; }
        if (!connected(form, target) || (request && !request.current())) { return; }
        clearErrors(form);
        if (!request) { request = beginRequest(form, target, method); }
        var options = { method: method, headers: {} };
        if (request.signal) { options.signal = request.signal; }
        if (body) { options.body = body; }
        emit(form, "tinycat:before", { target: target });
        var data = await TinyCat.forms.request(form, url, options);
        if (!request.current()) { return; }
        handleResult(form, data, target);
        pushHistory(nextHistory);
      } catch (error) {
        if (error.name === "AbortError" || !connected(form, target) || (request && !request.current())) { return; }
        var errors = resultErrors(error);
        refreshFormCaptcha(form, error.data);
        if (errors) { invalidField = applyErrors(form, errors); }
        TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
        emit(form, "tinycat:error", { error: error, target: target });
      } finally {
        delete form.dataset.apiBusy;
        if (invalidField && connected(form, target) && (!request || request.current())) { TinyCat.forms.focusInvalid(invalidField); }
        if (request) { request.finish(); }
      }
    });

    document.addEventListener("click", async function (event) {
      var link = event.target.closest && event.target.closest("[data-ajax]");
      if (!link) { return; }
      event.preventDefault();
      var method = (link.dataset.method || "GET").toUpperCase();
      var read = method === "GET" || method === "HEAD";
      var url = link.dataset.ajaxCurrent === "true" ? currentAjaxUrl(link) : (link.dataset.url || link.getAttribute("href"));
      var key = read ? null : method + " " + new URL(url, window.location.href).href;
      if (key && mutationLocks.has(key)) { return; }
      if (key) { mutationLocks.add(key); }
      var target = link.dataset.ajaxTarget ? qs(link.dataset.ajaxTarget) : null;
      var request = null, loading = false;
      try {
        if ((link.dataset.ajaxTarget && !target) || !connected(link, target)) { return; }
        // Invalidate older reads before waiting for a confirmation dialog.
        if (read) { request = beginRequest(link, target, method); }
        if (link.dataset.confirm && !await confirmAction(link)) { return; }
        if (!connected(link, target) || (request && !request.current())) { return; }
        if (target && !await TinyCat.forms.confirmDiscard(TinyCat.forms.dirty(target))) { return; }
        if (!connected(link, target) || (request && !request.current())) { return; }
        if (!request) { request = beginRequest(link, target, method); }
        var nextHistory = TinyCat.lists.url(historyUrl(link, url), target);
        url = TinyCat.lists.url(url, target);
        if (!loadingLinks.has(link)) { setLoading(link, true); }
        loadingLinks.set(link, request); loading = true;
        emit(link, "tinycat:before", { target: target });
        var options = { method: method, headers: {} };
        if (request.signal) { options.signal = request.signal; }
        var data = await TinyCat.api.request(url, options);
        if (!request.current()) { return; }
        handleResult(link, data, target);
        pushHistory(nextHistory);
      } catch (error) {
        if (error.name === "AbortError" || !connected(link, target) || (request && !request.current())) { return; }
        TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
        emit(link, "tinycat:error", { error: error, target: target });
      } finally {
        if (key) { mutationLocks.delete(key); }
        if (loading && loadingLinks.get(link) === request) { loadingLinks.delete(link); setLoading(link, false); }
        if (request) { request.finish(); }
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
