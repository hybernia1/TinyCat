(function () {
  "use strict";
  if (document.querySelectorAll) document.querySelectorAll("[data-status-video]").forEach(function (root) {
    var button = root.querySelector("button");
    var mount = root.querySelector("[data-status-video-mount]");
    var kind = root.dataset.cardKind;
    if (!mount) return;
    if (!button) return;
    button.addEventListener("click", function () {
      if (kind === "audio" && root.dataset.audioUrl) {
        var audioUrl;
        try { audioUrl = new URL(root.dataset.audioUrl); } catch (_) { return; }
        if (audioUrl.username || audioUrl.password || (audioUrl.protocol !== "https:" && audioUrl.origin !== window.location.origin)) return;
        var audio = document.createElement("audio");
        audio.className = "status-audio-player";
        audio.controls = true;
        audio.preload = "none";
        audio.src = audioUrl.href;
        audio.setAttribute("aria-label", button.getAttribute("aria-label") || "");
        mount.replaceChildren(audio);
        var playback = audio.play();
        if (playback && playback.catch) playback.catch(function () {});
        return;
      }
      var url;
      try { url = new URL(root.dataset.embedUrl); } catch (_) { return; }
      if (url.protocol !== "https:" || url.username || url.password || url.origin !== root.dataset.embedOrigin) return;
      var frame = document.createElement("iframe");
      frame.className = "status-video-frame";
      var autoplay = root.dataset.embedAutoplayParam || "autoplay";
      if (/^[a-z_]+$/.test(autoplay)) url.searchParams.set(autoplay, root.dataset.embedAutoplayValue || "1");
      frame.src = url.href;
      frame.title = button.getAttribute("aria-label") || "";
      frame.referrerPolicy = "strict-origin-when-cross-origin";
      frame.allow = "autoplay; fullscreen; picture-in-picture; encrypted-media";
      frame.allowFullscreen = kind !== "audio";
      if (kind === "audio") frame.height = String(Math.max(80, Math.min(600, Number(root.dataset.embedHeight) || 166)));
      mount.replaceChildren(frame);
    });
  });
  if (window.parent === window) {
    var code = document.getElementById("embed-code");
    if (code) code.addEventListener("click", function () { code.select(); });
    return;
  }
  var card = document.querySelector(".embed-card");
  if (!card) return;
  function httpOrigin(value) {
    try {
      var url = new URL(value);
      return /^https?:$/.test(url.protocol) && !url.username && !url.password ? url.origin : "";
    } catch (_) { return ""; }
  }
  var parentOrigin = httpOrigin(document.referrer);
  function secret() {
    var value = new URLSearchParams(window.location.hash.replace(/^#\??/, "")).get("secret") || "";
    return /^[a-zA-Z0-9]{1,64}$/.test(value) ? value : "";
  }
  function resize() {
    if (!parentOrigin) return;
    var height = Math.min(Number(document.documentElement.dataset.embedMaxheight) || 600, Math.ceil(card.getBoundingClientRect().height));
    window.parent.postMessage({ type: "tinycat:embed:resize", height: height }, parentOrigin);
    var token = secret();
    if (token) window.parent.postMessage({ message: "height", value: height, secret: token }, parentOrigin);
  }
  window.addEventListener("message", function (event) {
    if (event.source !== window.parent || !event.data || event.data.message !== "ready" || event.data.secret !== secret()) return;
    var origin = httpOrigin(event.origin);
    if (!origin || (parentOrigin && origin !== parentOrigin)) return;
    parentOrigin = origin;
    resize();
  });
  document.addEventListener("click", function (event) {
    var link = event.target.closest("a");
    var token = secret();
    if (link && token && parentOrigin && new URL(link.href).origin === window.location.origin) {
      event.preventDefault();
      window.parent.postMessage({ message: "link", value: link.href, secret: token }, parentOrigin);
    }
  });
  window.addEventListener("load", resize);
  window.addEventListener("hashchange", resize);
  if (window.ResizeObserver) new ResizeObserver(resize).observe(card);
  resize();
}());
