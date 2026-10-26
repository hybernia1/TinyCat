import "../../core/i18n.js";
import "../../core/icons.js";
import "./views.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  var detectorPromise = null;
  function languageDetector() {
    if (!detectorPromise) {
      detectorPromise = window.LanguageDetector.create().catch(function () {
        detectorPromise = null;
        return null;
      });
    }
    return detectorPromise;
  }
  function meaningfulText(body) {
    var copy = body.cloneNode(true);
    copy.querySelectorAll("a").forEach(function (link) { link.remove(); });
    return copy.textContent.replace(/https?:\/\/\S+|www\.\S+|[@#][\p{L}\p{N}_]+/gu, " ").trim();
  }
  function text(key) { return T.i18n.t("account.translation_" + key); }
  function supported() {
    return window.isSecureContext && window.Translator && window.LanguageDetector;
  }
  function hydrate(root) {
    if (!supported()) { return; }
    T.dom.all(".status-body", root).forEach(function (body) {
      if (body.closest(".status-translation") || body.dataset.translationReady || !body.textContent.trim()) { return; }
      var sample = meaningfulText(body);
      if ((sample.match(/\p{L}/gu) || []).length < 4) { return; }
      body.dataset.translationReady = "true";
      var button = document.createElement("button");
      var label = document.createElement("span");
      var state = document.createElement("span");
      var output = document.createElement("div");
      var controls = document.createElement("div");
      var source = null, translated = false, translator = null;
      var target = (T.i18n.locale() || document.documentElement.lang || "en").split(/[-_]/)[0];
      controls.className = "status-translation";
      controls.hidden = true;
      button.type = "button";
      button.className = "badge status-translation-link";
      label.textContent = text("translate");
      button.append(T.icons.create("translate"), label);
      button.setAttribute("aria-expanded", "false");
      state.setAttribute("role", "status");
      output.className = "status-body status-translation-output";
      output.lang = target;
      output.dir = "auto";
      output.hidden = true;
      controls.append(button, state, output);
      body.after(controls);
      async function checkLanguage() {
        try {
          // A missing detection model is prepared only after a user gesture.
          if (!detectorPromise && await window.LanguageDetector.availability() !== "available") {
            body.removeAttribute("data-translation-ready");
            controls.remove();
            return;
          }
          var detector = await languageDetector();
          if (!detector) {
            body.removeAttribute("data-translation-ready");
            controls.remove();
            return;
          }
          var results = await detector.detect(sample);
          if (!results.length || results[0].confidence < 0.7) { return; }
          source = results[0].detectedLanguage;
          if (source === target || source === "und") { return; }
          var available = await window.Translator.availability({ sourceLanguage: source, targetLanguage: target });
          controls.hidden = available === "unavailable";
        } catch (error) {
          controls.hidden = true;
        }
      }
      checkLanguage();
      function monitor(m) {
        m.addEventListener("downloadprogress", function (event) {
          state.textContent = text("preparing") + " " + Math.round(event.loaded * 100) + "%";
        });
      }
      button.addEventListener("click", async function () {
        if (translated) {
          output.hidden = !output.hidden;
          label.textContent = text(output.hidden ? "translate" : "hide");
          button.setAttribute("aria-expanded", String(!output.hidden));
          state.textContent = output.hidden ? "" : text("machine");
          return;
        }
        button.disabled = true;
        state.textContent = text("preparing");
        try {
          if (source === target) {
            state.textContent = text("same");
            return;
          }
          var options = { sourceLanguage: source, targetLanguage: target, monitor: monitor };
          if (await window.Translator.availability(options) === "unavailable") {
            state.textContent = text("unavailable");
            return;
          }
          // Detection/download may consume activation; the next click can reuse the detected language.
          if (navigator.userActivation && !navigator.userActivation.isActive) {
            state.textContent = text("continue");
            return;
          }
          translator = await window.Translator.create(options);
          output.textContent = await translator.translate(body.innerText);
          translated = true;
          output.hidden = false;
          label.textContent = text("hide");
          button.setAttribute("aria-expanded", "true");
          state.textContent = text("machine");
        } catch (error) {
          state.textContent = text("failed");
        } finally {
          if (translator) { translator.destroy(); translator = null; }
          button.disabled = false;
        }
      });
    });
  }
  function prepareDetection() {
    if (!supported()) { return; }
    languageDetector().then(function (detector) {
      if (detector) { hydrate(document); }
    });
  }
  document.addEventListener("pointerdown", prepareDetection, { once: true });
  document.addEventListener("keydown", prepareDetection, { once: true });
  T.status.controllers.translation = { hydrate: hydrate };
}());
