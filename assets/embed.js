(function () {
  "use strict";
  if (window.tinycatEmbedResize) return;
  window.tinycatEmbedResize = true;
  function ready() {
    document.querySelectorAll("iframe.tinycat-embed").forEach(function (frame) {
      function notify() { frame.contentWindow.postMessage({ message: "ready", secret: "" }, new URL(frame.src, document.baseURI).origin); }
      frame.addEventListener("load", notify);
      notify();
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", ready, { once: true });
  else ready();
  window.addEventListener("message", function (event) {
    var data = event.data;
    if (!data || data.type !== "tinycat:embed:resize" || !Number.isFinite(data.height) || data.height < 1 || data.height > 1000) return;
    document.querySelectorAll("iframe.tinycat-embed").forEach(function (frame) {
      if (event.source !== frame.contentWindow || event.origin !== new URL(frame.src, document.baseURI).origin) return;
      frame.height = String(Math.ceil(data.height));
    });
  });
}());
