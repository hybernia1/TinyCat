import "../../core/dom.js";
import "../../core/navigation.js";
import "./views.js";
import "../../ui/images.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var compactUrl = TinyCat.navigation.compact;
  var prepareClientImageInput = TinyCat.images.prepare;
  var replaceImageInputFile = TinyCat.images.replace;
  function markStatusLinkImageMissing(image) {
    var media = image && image.closest ? image.closest("[data-status-link-media]") : null;
    var card = image && image.closest ? image.closest(".status-link-card") : null;
    var fallback = media ? qs("[data-status-link-fallback]", media) : null;

    if (!image) {
      return;
    }

    image.hidden = true;
    image.dataset.statusLinkImageMissing = "true";

    if (card) {
      card.classList.add("is-image-missing");
      card.classList.remove("is-image-ready");
    }

    if (fallback) {
      fallback.hidden = false;
    }
  }
  function markStatusLinkImageReady(image) {
    var media = image && image.closest ? image.closest("[data-status-link-media]") : null;
    var card = image && image.closest ? image.closest(".status-link-card") : null;
    var fallback = media ? qs("[data-status-link-fallback]", media) : null;

    if (!image) {
      return;
    }

    image.hidden = false;
    image.dataset.statusLinkImageMissing = "false";

    if (card) {
      card.classList.add("is-image-ready");
      card.classList.remove("is-image-missing");
    }

    if (fallback) {
      fallback.hidden = true;
    }
  }
  function initStatusLinkImageRoot(image) {
    if (!image || image.dataset.statusLinkImageReady === "true") {
      return;
    }

    image.dataset.statusLinkImageReady = "true";
    image.addEventListener("load", function () {
      markStatusLinkImageReady(image);
    });
    image.addEventListener("error", function () {
      markStatusLinkImageMissing(image);
    });

    if (image.complete) {
      if (image.naturalWidth > 0) {
        markStatusLinkImageReady(image);
      } else {
        markStatusLinkImageMissing(image);
      }
    }
  }
  function allowedStatusVideoUrl(value) {
    var url;
    var allowedHosts = [
      "www.youtube-nocookie.com",
      "youtube-nocookie.com",
      "www.youtube.com",
      "youtube.com",
      "player.vimeo.com",
      "www.dailymotion.com",
      "dailymotion.com"
    ];

    try {
      url = new URL(String(value || ""), window.location.href);
    } catch (error) {
      return "";
    }

    if (url.protocol !== "https:" || allowedHosts.indexOf(url.hostname.toLowerCase()) === -1) {
      return "";
    }

    return url.href;
  }
  function initStatusVideoRoot(root) {
    var button;
    var embedUrl;

    if (!root || root.dataset.statusVideoReady === "true") {
      return;
    }

    button = qs("[data-status-video-load]", root);
    embedUrl = allowedStatusVideoUrl(root.dataset.embedUrl || "");

    if (!button || !embedUrl) {
      return;
    }

    root.dataset.statusVideoReady = "true";
    button.addEventListener("click", function () {
      var iframe = document.createElement("iframe");
      var url = new URL(embedUrl);
      var host = url.hostname.toLowerCase();

      url.searchParams.set("autoplay", "1");
      url.searchParams.set("playsinline", "1");

      if (host === "youtube.com" || host === "www.youtube.com" || host === "youtube-nocookie.com" || host === "www.youtube-nocookie.com") {
        url.searchParams.set("origin", window.location.origin);
        url.searchParams.set("rel", "0");
      }

      iframe.className = "status-video-frame";
      iframe.src = url.href;
      iframe.loading = "lazy";
      iframe.referrerPolicy = "strict-origin-when-cross-origin";
      iframe.allow = "accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share";
      iframe.allowFullscreen = true;
      root.innerHTML = "";
      root.appendChild(iframe);
    });
  }
  function initStatusImageRoot(root) {
    var input = qs("[data-status-image-input]", root);
    var preview = qs("[data-status-image-preview]", root);
    var previewWrap = qs("[data-status-image-preview-wrap]", root);
    var picker = qs("[data-status-image-picker]", root);
    var removeButton = qs("[data-status-image-remove]", root);
    var removeInput = qs("[data-status-image-remove-input]", root);
    var altWrap = qs("[data-status-image-alt-wrap]", root);
    var originalSrc = preview ? String(preview.getAttribute("src") || "") : "";
    var originalAlt = preview ? String(preview.getAttribute("alt") || "") : "";
    var previewRequestId = 0;
    var selectionRequestId = 0;

    if (!input || !preview || root.dataset.statusImageReady === "true") {
      return;
    }

    root.dataset.statusImageReady = "true";

    function revokePreview() {
      previewRequestId++;
    }

    function showOriginal() {
      revokePreview();
      previewWrap.hidden = !originalSrc;
      preview.hidden = !originalSrc;
      if (originalSrc) {
        preview.src = originalSrc;
      } else {
        preview.removeAttribute("src");
      }
      preview.alt = originalAlt;
      if (altWrap) {
        altWrap.hidden = !originalSrc;
      }
      if (picker) {
        picker.hidden = Boolean(originalSrc);
      }
    }

    function showFile(file) {
      var reader;
      var requestId;

      revokePreview();
      requestId = previewRequestId;
      preview.removeAttribute("src");
      preview.alt = "";
      preview.hidden = false;
      previewWrap.hidden = false;
      if (altWrap) {
        altWrap.hidden = false;
      }
      if (removeInput) {
        removeInput.value = "0";
      }
      if (picker) {
        picker.hidden = true;
      }

      reader = new FileReader();
      reader.addEventListener("load", function () {
        if (requestId !== previewRequestId || typeof reader.result !== "string") {
          return;
        }

        preview.src = reader.result;
        preview.alt = originalAlt;
      });
      reader.addEventListener("error", function () {
        if (requestId === previewRequestId) {
          preview.hidden = true;
          previewWrap.hidden = true;
          if (picker) {
            picker.hidden = false;
          }
        }
      });
      reader.readAsDataURL(file);
    }

    input.addEventListener("change", async function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      var requestId = ++selectionRequestId;
      var prepared;

      if (!file) {
        showOriginal();
        return;
      }

      if (["image/jpeg", "image/png", "image/webp"].indexOf(file.type) === -1) {
        input.value = "";
        TinyCat.toasts.show("The selected image is not supported or exceeds the size limit.", "danger");
        showOriginal();
        return;
      }

      try {
        prepared = await prepareClientImageInput(input, file);
        if (requestId !== selectionRequestId) {
          return;
        }
        replaceImageInputFile(input, prepared);
        showFile(prepared);
      } catch (error) {
        if (requestId !== selectionRequestId) {
          return;
        }
        input.value = "";
        TinyCat.toasts.show("The selected image could not be prepared for upload.", "danger");
        showOriginal();
      }
    });

    if (removeButton) {
      removeButton.addEventListener("click", function () {
        selectionRequestId++;
        revokePreview();
        input.value = "";
        preview.removeAttribute("src");
        preview.hidden = true;
        previewWrap.hidden = true;
        if (altWrap) {
          altWrap.hidden = true;
        }
        if (removeInput) {
          removeInput.value = "1";
        }
        if (picker) {
          picker.hidden = false;
        }
      });
    }

    if (root.closest("form")) {
      root.closest("form").addEventListener("reset", function () {
        window.setTimeout(showOriginal, 0);
      });
    }
  }
  function mobileStatusUrl(trigger) {
    var parent;
    var modalTarget;
    var card;
    var href;
    var statusId;

    if (!trigger || !window.matchMedia || !window.matchMedia("(max-width: 760px), (hover: none) and (pointer: coarse)").matches) {
      return "";
    }

    parent = trigger.closest("[data-modal-parent-open]");
    modalTarget = String(trigger.dataset.modalOpen || (parent ? parent.dataset.modalParentOpen : ""));
    if (modalTarget.indexOf("status-post-modal-") !== 0) {
      return "";
    }

    href = String(trigger.getAttribute("href") || "");
    if (href.indexOf("/status/") === 0) {
      return mobileStatusPageUrl(href);
    }

    card = trigger.closest(".status-card");
    if (card && card.dataset.statusUrl) {
      return mobileStatusPageUrl(String(card.dataset.statusUrl));
    }

    statusId = parseInt(modalTarget.slice("status-post-modal-".length), 10) || 0;
    return statusId > 0 ? mobileStatusPageUrl("/status/" + statusId) : "";
  }
  function mobileStatusPageUrl(value) {
    var url = new URL(value, window.location.origin);

    url.searchParams.set("compact", "1");
    return compactUrl(url);
  }
  TinyCat.status.controllers.media = { markStatusLinkImageMissing: markStatusLinkImageMissing, markStatusLinkImageReady: markStatusLinkImageReady, initStatusLinkImageRoot: initStatusLinkImageRoot, allowedStatusVideoUrl: allowedStatusVideoUrl, initStatusVideoRoot: initStatusVideoRoot, initStatusImageRoot: initStatusImageRoot, mobileStatusUrl: mobileStatusUrl, mobileStatusPageUrl: mobileStatusPageUrl };
}());
