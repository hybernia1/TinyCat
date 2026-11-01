import "../../core/api-client.js";
import "../../core/lifecycle.js";
import "../../ui/component-builders.js";
import "../../ui/form-state.js";
(function () {
  var T = window.TinyCat, states = new WeakMap(), sequence = 0;
  function fields(root) { return (root || document).querySelectorAll("input[data-email-check-url]"); }
  function value(input) { return input.value.trim().toLowerCase(); }
  function bind(input) {
    if (states.has(input)) { return; }
    var timer, controller, revision = 0, form = input.form;
    var status = document.createElement("span");
    status.id = "email-check-" + (++sequence); status.className = "help"; status.setAttribute("aria-live", "polite");
    input.closest(".field").appendChild(status);
    input.setAttribute("aria-describedby", ((input.getAttribute("aria-describedby") || "") + " " + status.id).trim());
    function cancel() { clearTimeout(timer); if (controller) { controller.abort(); } revision++; }
    function reset() {
      cancel(); input.setCustomValidity(""); input.removeAttribute("aria-invalid");
      status.textContent = ""; status.className = "help";
    }
    function update() {
      reset(); T.forms.clearErrors(form, input.name);
      var email = value(input), current = revision;
      if (!email || !input.validity.valid) { return; }
      timer = setTimeout(async function () {
        controller = new AbortController();
        status.textContent = T.ui.text("account.email_checking");
        try {
          var response = await T.api.request(input.dataset.emailCheckUrl, { method: "POST", body: { email: email }, signal: controller.signal });
          if (current !== revision || !input.isConnected || email !== value(input)) { return; }
          var taken = response.data.available === false;
          var message = T.ui.text(taken ? "account.messages.email_taken" : "account.email_available");
          status.textContent = message; status.className = taken ? "field-error" : "help";
          input.setCustomValidity(taken ? message : "");
          if (taken) { input.setAttribute("aria-invalid", "true"); }
        } catch (error) {
          if (current === revision && input.isConnected && error.name !== "AbortError") {
            var errors = T.api.errors(error), message = errors.email && errors.email[0];
            status.textContent = message || T.ui.text("account.email_check_failed");
            if (message) { status.className = "field-error"; input.setCustomValidity(message); input.setAttribute("aria-invalid", "true"); }
          }
        }
      }, 450);
    }
    form.addEventListener("reset", reset);
    input.addEventListener("input", update); input.addEventListener("change", update);
    states.set(input, function () {
      cancel(); form.removeEventListener("reset", reset); input.removeEventListener("input", update); input.removeEventListener("change", update);
      input.setCustomValidity("");
      var ids = (input.getAttribute("aria-describedby") || "").split(/\s+/).filter(function (id) { return id && id !== status.id; });
      if (ids.length) { input.setAttribute("aria-describedby", ids.join(" ")); } else { input.removeAttribute("aria-describedby"); }
      status.remove(); states.delete(input);
    });
  }
  T.lifecycle.register("email-availability", {
    hydrate: function (root) { fields(root).forEach(bind); },
    dispose: function (root) { fields(root).forEach(function (input) { var dispose = states.get(input); if (dispose) { dispose(); } }); }
  });
}());
