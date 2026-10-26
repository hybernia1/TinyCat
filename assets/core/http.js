import "./i18n.js";
(function () {
  "use strict";

  var TinyCat = window.TinyCat = window.TinyCat || {};
  var messages = Object.create(null);
  function message(notice) {
    var key = notice && messages[notice.code];
    return key && TinyCat.i18n.has(key) ? TinyCat.i18n.t(key, notice.params || {}) : (notice && notice.message || "");
  }

  function csrfToken() {
    var field = document.querySelector('meta[name="csrf-token"]') || document.querySelector('input[name="_csrf"]');
    return field ? String(field.content || field.value || "") : "";
  }

  function failure(message, response, data) {
    var error = new Error(message);
    error.response = response || null;
    error.data = data || null;
    return error;
  }

  async function request(url, options) {
    var target = new URL(url, window.location.href);
    if (target.origin !== window.location.origin) {
      throw failure("API requests must use the current origin.");
    }
    var settings = Object.assign({ credentials: "same-origin" }, options || {});
    var headers = new Headers(settings.headers || {});
    var method = String(settings.method || "GET").toUpperCase();
    headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");
    if (!headers.has("X-CSRF-Token") && csrfToken()) {
      headers.set("X-CSRF-Token", csrfToken());
    }

    var body = settings.body;
    if (body instanceof FormData && method !== "GET" && method !== "POST") {
      var multipart = new FormData();
      body.forEach(function (value, key) { multipart.append(key, value); });
      multipart.set("_method", method);
      body = multipart;
      method = "POST";
    } else if (body !== undefined && body !== null && typeof body !== "string"
      && !(body instanceof FormData) && !(body instanceof Blob) && !(body instanceof URLSearchParams)) {
      headers.set("Content-Type", "application/json");
      body = JSON.stringify(body);
    }

    var response = await fetch(target.href, Object.assign({}, settings, { method: method, headers: headers, body: body }));
    var contentType = (response.headers.get("Content-Type") || "").split(";", 1)[0].trim().toLowerCase();
    if (contentType !== "application/json") {
      throw failure("The API returned an unexpected response format.", response);
    }
    var result;
    try {
      result = await response.json();
    } catch (_error) {
      throw failure("The API returned invalid JSON.", response);
    }
    if (!result || typeof result !== "object" || Array.isArray(result)
      || typeof result.ok !== "boolean" || result.status !== response.status
      || typeof result.code !== "string" || !/^[a-z][a-z0-9_]*$/.test(result.code)
      || !result.params || typeof result.params !== "object" || Array.isArray(result.params)
      || !Object.prototype.hasOwnProperty.call(result, "data")
      || !Object.prototype.hasOwnProperty.call(result, "message")
      || !result.meta || typeof result.meta !== "object" || Array.isArray(result.meta)) {
      throw failure("The API returned an invalid response envelope.", response);
    }
    result.message = message(result);
    if (!response.ok || !result.ok) {
      throw failure(result.message || response.statusText || "Request failed.", response, result);
    }
    return result;
  }

  function unwrapResult(data) {
    if (data && typeof data === "object" && Object.prototype.hasOwnProperty.call(data, "ok") && Object.prototype.hasOwnProperty.call(data, "data")) {
      return data.data;
    }

    return data;
  }
  function resultErrorDetails(data) {
    return data && data.error && data.error.details && typeof data.error.details === "object"
      ? data.error.details
      : {};
  }
  TinyCat.api = {
    message: message,
    registerMessages: function (definitions) {
      Object.keys(definitions).forEach(function (code) {
        if (typeof definitions[code] !== "string" || (messages[code] && messages[code] !== definitions[code])) { throw new TypeError("Invalid API message mapping."); }
        messages[code] = definitions[code];
      });
    },
    unwrap: unwrapResult, details: resultErrorDetails,
    csrfToken: csrfToken,
    request: request,
    errors: function (error) {
      var data = error && error.data;
      var fields = data && data.error && data.error.details && data.error.details.errors || {}, output = {};
      Object.keys(fields).forEach(function (field) { output[field] = fields[field].map(function (issue) { return typeof issue === "string" ? issue : message(issue); }); });
      return output;
    }
  };
}());
