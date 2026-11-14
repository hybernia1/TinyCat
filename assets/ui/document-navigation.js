import "../core/dom.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  function meta(selector, attribute, value) {
    var node = document.head.querySelector(selector);
    if (node) { node.setAttribute(attribute, value || ""); }
  }
  function metadata(doc) {
    document.title = doc.title;
    meta('link[rel="canonical"]', "href", doc.url);
    var values = { description: doc.description, robots: doc.robots || "index,follow", "twitter:title": doc.meta_title || doc.title, "twitter:description": doc.description, "twitter:card": doc.image ? "summary_large_image" : "summary", "twitter:image": doc.image, "twitter:image:alt": doc.image_alt };
    Object.keys(values).forEach(function (name) {
      var selector = 'meta[name="' + name + '"]', node = document.head.querySelector(selector);
      if (!node) { node = document.createElement("meta"); node.name = name; document.head.appendChild(node); }
      node.content = values[name] || "";
    });
    var properties = { "og:title": doc.meta_title || doc.title, "og:locale": doc.locale, "og:description": doc.description, "og:url": doc.url, "og:type": doc.type, "og:image": doc.image, "og:image:type": doc.image_type, "og:image:width": doc.image_width || "", "og:image:height": doc.image_height || "", "og:image:alt": doc.image_alt, "article:published_time": doc.published_time, "article:author": doc.author };
    Object.keys(properties).forEach(function (name) {
      var node = document.head.querySelector('meta[property="' + name + '"]');
      if (!properties[name]) { if (node) { node.remove(); } return; }
      if (!node) { node = document.createElement("meta"); node.setAttribute("property", name); document.head.appendChild(node); }
      node.content = properties[name];
    });
    document.head.querySelectorAll('meta[property="article:tag"]').forEach(function (node) { node.remove(); });
    if (doc.type === "article") {
      (doc.tags || []).forEach(function (tag) {
        var node = document.createElement("meta"); node.setAttribute("property", "article:tag"); node.content = tag; document.head.appendChild(node);
      });
    }
    document.head.querySelectorAll('link[rel="alternate"][type="application/rss+xml"], link[rel="alternate"][type="application/json+oembed"], link[rel="alternate"][type="text/xml+oembed"], link[rel="prev"], link[rel="next"], script[type="application/ld+json"]').forEach(function (node) { node.remove(); });
    [["oembed", "application/json+oembed"], ["oembed_xml", "text/xml+oembed"]].forEach(function (entry) {
      if (!doc[entry[0]]) return;
      var link = document.createElement("link"); link.rel = "alternate"; link.type = entry[1]; link.href = doc[entry[0]]; document.head.appendChild(link);
    });
    if (doc.rss) { var link = document.createElement("link"); link.rel = "alternate"; link.type = "application/rss+xml"; link.href = doc.rss; link.title = doc.title; document.head.appendChild(link); }
    if (doc.jsonld) { var schema = document.createElement("script"); schema.type = "application/ld+json"; schema.textContent = JSON.stringify(doc.jsonld); document.head.appendChild(schema); }
  }
  T.document = { update: metadata };
}());
