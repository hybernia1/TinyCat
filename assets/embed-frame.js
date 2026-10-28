(function () {
  "use strict";
  if (document.querySelectorAll) document.querySelectorAll("[data-embed-player]").forEach(function (root) {
    var button = root.querySelector("button");
    if (!button) return;
    button.addEventListener("click", function () {
      var url;
      try { url = new URL(root.dataset.playerUrl); } catch (_) { return; }
      if (url.protocol !== "https:" || url.username || url.password || url.origin !== root.dataset.playerOrigin) return;
      var frame = document.createElement("iframe");
      var autoplay = root.dataset.playerAutoplayParam || "autoplay";
      if (/^[a-z_]+$/.test(autoplay)) url.searchParams.set(autoplay, root.dataset.playerAutoplayValue || "1");
      frame.src = url.href;
      frame.title = button.getAttribute("aria-label") || "";
      frame.referrerPolicy = "strict-origin-when-cross-origin";
      frame.allow = "autoplay; fullscreen; picture-in-picture; encrypted-media";
      frame.allowFullscreen = root.dataset.playerKind !== "audio";
      if (root.dataset.playerKind === "audio") frame.height = String(Math.max(80, Math.min(600, Number(root.dataset.playerHeight) || 166)));
      root.replaceChildren(frame);
    });
  });
  if (window.parent === window) {
    var code = document.getElementById("embed-code");
    if (code) code.addEventListener("click", function () { code.select(); });
    return;
  }
  var card = document.querySelector(".embed-card");
  if (!card) return;
  function secret() {
    var value = new URLSearchParams(window.location.hash.replace(/^#\??/, "")).get("secret") || "";
    return /^[a-zA-Z0-9]{1,64}$/.test(value) ? value : "";
  }
  function resize() {
    var height = Math.min(Number(document.documentElement.dataset.embedMaxheight) || 600, Math.ceil(card.getBoundingClientRect().height));
    window.parent.postMessage({ type: "tinycat:embed:resize", height: height }, "*");
    var token = secret();
    if (token) window.parent.postMessage({ message: "height", value: height, secret: token }, "*");
  }
  window.addEventListener("message", function (event) {
    if (event.source === window.parent && event.data && event.data.message === "ready" && event.data.secret === secret()) resize();
  });
  document.addEventListener("click", function (event) {
    var link = event.target.closest("a");
    var token = secret();
    if (link && token && new URL(link.href).origin === window.location.origin) {
      event.preventDefault();
      window.parent.postMessage({ message: "link", value: link.href, secret: token }, "*");
    }
  });
  window.addEventListener("load", resize);
  window.addEventListener("hashchange", resize);
  if (window.ResizeObserver) new ResizeObserver(resize).observe(card);
  resize();
}());
