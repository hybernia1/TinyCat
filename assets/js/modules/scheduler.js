(function () {
  "use strict";
  var T = window.TinyCat;
  T.scheduler = {
    result: function (source, response) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || "/", window.location.href);
      if (url.pathname !== "/api/scheduler/tokens") { return false; }
      var current = document.querySelector("[data-scheduler-token]"), previous = current ? current.value : "", token = response.data.token;
      document.querySelectorAll("[data-scheduler-url]").forEach(function (field) { var target = new URL(field.value); target.searchParams.set("token", token); field.value = target.href; });
      document.querySelectorAll("[data-scheduler-command]").forEach(function (node) { if (previous) { node.textContent = node.textContent.split(previous).join(token); } });
      if (current) { current.value = token; }
      return true;
    }
  };
}());
