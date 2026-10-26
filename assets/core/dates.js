import "./i18n.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  function config() { return document.documentElement && document.documentElement.dataset || {}; }
  function relative(date, now) {
    var seconds = (date.getTime() - now) / 1000, absolute = Math.abs(seconds), unit, count;
    if (absolute < 45) { return T.i18n.t("relative.now"); }
    if (absolute < 90) { unit = "minute"; count = 1; }
    else if (absolute < 2700) { unit = "minute"; count = Math.round(absolute / 60); }
    else if (absolute < 5400) { unit = "hour"; count = 1; }
    else if (absolute < 79200) { unit = "hour"; count = Math.round(absolute / 3600); }
    else if (absolute < 129600) { unit = "day"; count = 1; }
    else if (absolute < 604800) { unit = "day"; count = Math.round(absolute / 86400); }
    else if (absolute < 2419200) { unit = "week"; count = Math.max(1, Math.round(absolute / 604800)); }
    else if (absolute < 3888000) { unit = "month"; count = 1; }
    else if (absolute < 29808000) { unit = "month"; count = Math.max(1, Math.round(absolute / 2592000)); }
    else if (absolute < 47088000) { unit = "year"; count = 1; }
    else { unit = "year"; count = Math.max(1, Math.round(absolute / 31536000)); }
    var key = "relative." + (seconds > 0 ? "future" : "past") + "." + unit;
    return count === 1 ? T.i18n.t(key + ".single") : T.i18n.plural(key, count);
  }
  function format(input, kind) {
    if (!input) { return ""; }
    var text = String(input), date = new Date(text), settings = config();
    if (!Number.isFinite(date.getTime())) { return ""; }
    if (kind !== "input" && settings.dateRelative === "true") { return relative(date, Date.now()); }
    // API ISO timestamps carry the site's offset, retaining calendar fields even
    // when the browser uses a different timezone (including datetime-local inputs).
    var parts = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!parts) { return ""; }
    var hour = Number(parts[4] || 0), month = Number(parts[2]);
    var tokens = { Y: parts[1], m: parts[2], n: String(month), d: parts[3], j: String(Number(parts[3])), H: parts[4] || "00", G: String(hour), i: parts[5] || "00", s: parts[6] || "00", g: String(hour % 12 || 12), h: String(hour % 12 || 12).padStart(2, "0"), A: hour < 12 ? "AM" : "PM", M: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][month - 1] };
    return (kind === "input" ? "Y-m-d\\TH:i" : settings.dateFormat || "Y-m-d H:i:s").replace(/\\(.)|[YmdnjHGisghAM]/g, function (token, literal) { return literal || tokens[token]; });
  }
  T.dates = { format: format, relative: relative };
}());
