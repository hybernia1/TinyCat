import "../../core/dom.js";
import "../../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.siteAnalytics = {};
  var qsa = TinyCat.dom.all;
  function loadGoogleAnalytics(measurementId) {
    measurementId = String(measurementId || "").trim();

    if (!/^G-[A-Z0-9]+$/i.test(measurementId) || window.__tinycatGoogleAnalytics === measurementId) {
      return;
    }

    window.__tinycatGoogleAnalytics = measurementId;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () {
      window.dataLayer.push(arguments);
    };
    window.gtag("consent", "default", {
      analytics_storage: "granted",
      ad_storage: "denied",
      ad_user_data: "denied",
      ad_personalization: "denied",
      wait_for_update: 500
    });
    window.gtag("js", new Date());
    window.gtag("config", measurementId);

    var script = document.createElement("script");

    script.async = true;
    script.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(measurementId);
    script.dataset.googleAnalytics = measurementId;
    document.head.appendChild(script);
  }

  TinyCat.siteAnalytics.init = function () {

    qsa("[data-cookie-consent-choice]").forEach(function (button) {
      button.addEventListener("click", function () {
        var banner = button.closest("[data-cookie-consent]");
        var choice = button.getAttribute("data-cookie-consent-choice") === "granted" ? "granted" : "denied";

        document.cookie = "tinycat_analytics_consent=" + choice + "; Max-Age=" + (180 * 86400) + "; Path=/; SameSite=Lax" + (window.location.protocol === "https:" ? "; Secure" : "");

        if (choice === "granted" && banner) {
          loadGoogleAnalytics(banner.dataset.googleMeasurementId);
        }

        if (banner) {
          banner.remove();
        }
      });
    });
  };
  TinyCat.lifecycle.register("analytics", { init: TinyCat.siteAnalytics.init });
}());
