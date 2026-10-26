import "../../core/dom.js";
import "../../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.captcha = {};
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var captchaProviderLoads = {};
  function captchaProviderApi(provider) {
    if (provider === "recaptcha") {
      return window.grecaptcha;
    }

    if (provider === "turnstile") {
      return window.turnstile;
    }

    if (provider === "hcaptcha") {
      return window.hcaptcha;
    }

    return null;
  }
  function captchaProviderScript(provider) {
    if (provider === "recaptcha") {
      return "https://www.google.com/recaptcha/api.js?render=explicit";
    }

    if (provider === "turnstile") {
      return "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    }

    if (provider === "hcaptcha") {
      return "https://js.hcaptcha.com/1/api.js?render=explicit";
    }

    return "";
  }
  function loadCaptchaProvider(provider) {
    var source = captchaProviderScript(provider);

    if (captchaProviderApi(provider)) {
      return Promise.resolve(captchaProviderApi(provider));
    }

    if (captchaProviderLoads[provider]) {
      return captchaProviderLoads[provider];
    }

    if (!source) {
      return Promise.reject(new Error("Unknown CAPTCHA provider"));
    }

    captchaProviderLoads[provider] = new Promise(function (resolve, reject) {
      var script = document.createElement("script");

      script.src = source;
      script.async = true;
      script.defer = true;
      script.onload = function () {
        var api = captchaProviderApi(provider);

        if (api) {
          resolve(api);
          return;
        }

        reject(new Error("CAPTCHA provider did not initialize"));
      };
      script.onerror = function () {
        reject(new Error("CAPTCHA provider could not be loaded"));
      };
      document.head.appendChild(script);
    });

    return captchaProviderLoads[provider];
  }
  function initExternalCaptchaRoot(root) {
    var provider = root.dataset.captchaProvider || "";
    var sitekey = root.dataset.captchaSitekey || "";
    var theme = root.dataset.captchaTheme || "auto";
    var context = root.dataset.captchaContext || "form";
    var widget = qs("[data-captcha-widget]", root);

    if (root.dataset.captchaReady || !provider || !sitekey || !widget) {
      return;
    }

    root.dataset.captchaReady = "loading";
    loadCaptchaProvider(provider).then(function (api) {
      var options;

      if (!root.isConnected || root.dataset.captchaReady !== "loading") {
        return;
      }

      options = {
        sitekey: sitekey,
        theme: theme,
        callback: function () {
          root.dataset.captchaState = "verified";
        },
        "expired-callback": function () {
          root.dataset.captchaState = "expired";
        },
        "error-callback": function () {
          root.dataset.captchaState = "error";
        }
      };

      if (provider === "turnstile") {
        options.action = context;
      }

      api.render(widget, options);
      root.dataset.captchaReady = "true";
    }).catch(function () {
      root.dataset.captchaReady = "";
      root.dataset.captchaState = "error";
    });
  }
  function initCaptchaRoot(root) {
    initExternalCaptchaRoot(root);
  }
  TinyCat.captcha.hydrate = function (scope) {
    qsa("[data-captcha]", scope || document).forEach(initCaptchaRoot);
  };
  TinyCat.lifecycle.register("captcha", { hydrate: TinyCat.captcha.hydrate });
}());
