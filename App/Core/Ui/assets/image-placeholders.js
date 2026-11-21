import "./runtime/lifecycle.js";
(function () {
  function fallback(image) {
    if (!image || image.tagName !== "IMG" || image.hidden || image.dataset.imageFallback || image.closest("[data-status-link-media]")) { return; }
    image.dataset.imageFallback = "true";
    image.removeAttribute("srcset");
    var picture = image.closest("picture");
    if (picture) { picture.querySelectorAll("source").forEach(function (source) { source.removeAttribute("srcset"); }); }
    var url = document.body.dataset[image.hasAttribute("data-user-avatar-image") ? "fallbackAvatar" : "fallbackImage"];
    if (url) { image.src = url; }
  }
  window.TinyCat.lifecycle.register("image-placeholders", { init: function () { document.addEventListener("error", function (event) { fallback(event.target); }, true); }, hydrate: function (root) {
    (root || document).querySelectorAll("img[src]").forEach(function (image) { if (image.complete && !image.naturalWidth) { fallback(image); } });
  } });
}());
