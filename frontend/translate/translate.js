(function () {
  if (window.__txTranslate) return;
  window.__txTranslate = true;

  var PAGE = "en";
  var CHOICE_KEY = "tx-lang";
  var AUTO_KEY = "tx-auto";

  var LANGUAGES = [
    ["en", "English"],
    ["af", "Afrikaans"],
    ["sq", "Shqip"],
    ["am", "አማርኛ"],
    ["ar", "العربية"],
    ["hy", "Հայերեն"],
    ["az", "Azərbaycan"],
    ["eu", "Euskara"],
    ["be", "Беларуская"],
    ["bn", "বাংলা"],
    ["bs", "Bosanski"],
    ["bg", "Български"],
    ["ca", "Català"],
    ["zh-CN", "中文 (简体)"],
    ["zh-TW", "中文 (繁體)"],
    ["hr", "Hrvatski"],
    ["cs", "Čeština"],
    ["da", "Dansk"],
    ["nl", "Nederlands"],
    ["et", "Eesti"],
    ["fi", "Suomi"],
    ["fr", "Français"],
    ["gl", "Galego"],
    ["ka", "ქართული"],
    ["de", "Deutsch"],
    ["el", "Ελληνικά"],
    ["gu", "ગુજરાતી"],
    ["ht", "Kreyòl ayisyen"],
    ["ha", "Hausa"],
    ["he", "עברית"],
    ["hi", "हिन्दी"],
    ["hu", "Magyar"],
    ["is", "Íslenska"],
    ["ig", "Igbo"],
    ["id", "Bahasa Indonesia"],
    ["ga", "Gaeilge"],
    ["it", "Italiano"],
    ["ja", "日本語"],
    ["kn", "ಕನ್ನಡ"],
    ["kk", "Қазақ"],
    ["km", "ខ្មែរ"],
    ["rw", "Kinyarwanda"],
    ["ko", "한국어"],
    ["ky", "Кыргызча"],
    ["lo", "ລາວ"],
    ["lv", "Latviešu"],
    ["lt", "Lietuvių"],
    ["mk", "Македонски"],
    ["ms", "Bahasa Melayu"],
    ["ml", "മലയാളം"],
    ["mt", "Malti"],
    ["mr", "मराठी"],
    ["mn", "Монгол"],
    ["my", "မြန်မာ"],
    ["ne", "नेपाली"],
    ["no", "Norsk"],
    ["ps", "پښتو"],
    ["fa", "فارسی"],
    ["pl", "Polski"],
    ["pt", "Português"],
    ["pa", "ਪੰਜਾਬੀ"],
    ["ro", "Română"],
    ["ru", "Русский"],
    ["sr", "Српски"],
    ["si", "සිංහල"],
    ["sk", "Slovenčina"],
    ["sl", "Slovenščina"],
    ["so", "Soomaali"],
    ["es", "Español"],
    ["sw", "Kiswahili"],
    ["sv", "Svenska"],
    ["tl", "Filipino"],
    ["tg", "Тоҷикӣ"],
    ["ta", "தமிழ்"],
    ["te", "తెలుగు"],
    ["th", "ไทย"],
    ["tr", "Türkçe"],
    ["tk", "Türkmen"],
    ["uk", "Українська"],
    ["ur", "اردو"],
    ["uz", "Oʻzbek"],
    ["vi", "Tiếng Việt"],
    ["cy", "Cymraeg"],
    ["xh", "isiXhosa"],
    ["yo", "Yorùbá"],
    ["zu", "isiZulu"]
  ];

  // Primary language first. Extra codes are official languages used only when
  // the browser already asks for one of them.
  var COUNTRY = {
    AE: ["ar"], SA: ["ar"], QA: ["ar"], KW: ["ar"], BH: ["ar"], OM: ["ar"],
    YE: ["ar"], IQ: ["ar"], SY: ["ar"], JO: ["ar"], LB: ["ar"], PS: ["ar"],
    EG: ["ar"], LY: ["ar"], TN: ["ar"], DZ: ["ar"], SD: ["ar"], MR: ["ar"],
    MA: ["ar", "fr"], PK: ["ur"], IN: ["hi"], BD: ["bn"], LK: ["si"], NP: ["ne"],
    MM: ["my"], TH: ["th"], LA: ["lo"], KH: ["km"], VN: ["vi"], MY: ["ms"],
    BN: ["ms"], ID: ["id"], PH: ["tl", "en"], CN: ["zh-CN"], TW: ["zh-TW"],
    HK: ["zh-TW"], MO: ["zh-TW"], JP: ["ja"], KR: ["ko"], MN: ["mn"],
    KZ: ["kk"], UZ: ["uz"], KG: ["ky"], TJ: ["tg"], TM: ["tk"], AZ: ["az"],
    GE: ["ka"], AM: ["hy"], IR: ["fa"], AF: ["ps", "fa"], TR: ["tr"], IL: ["he"],
    FR: ["fr"], BE: ["nl", "fr", "de"], NL: ["nl"], DE: ["de"], AT: ["de"],
    CH: ["de", "fr", "it"], LI: ["de"], IT: ["it"], ES: ["es", "ca"], PT: ["pt"],
    AD: ["ca"], MC: ["fr"], LU: ["fr", "de"], GR: ["el"], CY: ["el", "tr"],
    PL: ["pl"], CZ: ["cs"], SK: ["sk"], HU: ["hu"], RO: ["ro"], MD: ["ro"],
    BG: ["bg"], HR: ["hr"], SI: ["sl"], RS: ["sr"], BA: ["bs"], ME: ["sr"],
    MK: ["mk"], AL: ["sq"], XK: ["sq"], EE: ["et"], LV: ["lv"], LT: ["lt"],
    FI: ["fi"], SE: ["sv"], NO: ["no"], DK: ["da"], IS: ["is"], UA: ["uk"],
    BY: ["be", "ru"], RU: ["ru"], IE: ["en", "ga"], MT: ["mt", "en"],
    MX: ["es"], GT: ["es"], SV: ["es"], HN: ["es"], NI: ["es"], CR: ["es"],
    PA: ["es"], CU: ["es"], DO: ["es"], PR: ["es"], CO: ["es"], VE: ["es"],
    EC: ["es"], PE: ["es"], BO: ["es"], CL: ["es"], AR: ["es"], UY: ["es"],
    PY: ["es"], GQ: ["es"], BR: ["pt"], AO: ["pt"], MZ: ["pt"], CV: ["pt"],
    GW: ["pt"], ST: ["pt"], HT: ["ht", "fr"], CA: ["en", "fr"],
    US: ["en"], GB: ["en"], AU: ["en"], NZ: ["en"],
    SG: ["en", "zh-CN", "ms", "ta"], ZA: ["en", "af", "zu", "xh"],
    NG: ["en"], GH: ["en"], KE: ["en", "sw"], TZ: ["sw"], UG: ["en", "sw"],
    RW: ["rw", "fr", "en"], SN: ["fr"], CI: ["fr"], ML: ["fr"], BF: ["fr"],
    NE: ["fr"], TD: ["fr"], CM: ["fr", "en"], GA: ["fr"], CG: ["fr"], CD: ["fr"],
    MG: ["fr"], GN: ["fr"], DJ: ["fr"], BI: ["fr"], ET: ["am"], SO: ["so"]
  };

  var names = {};
  var codes = [];
  for (var i = 0; i < LANGUAGES.length; i += 1) {
    codes.push(LANGUAGES[i][0]);
    names[LANGUAGES[i][0]] = LANGUAGES[i][1];
  }

  var root;
  var labelEl;
  var listEl;
  var emptyEl;
  var searchEl;
  var current = PAGE;
  var googleRequested = false;

  function known(code) {
    return !!names[code];
  }

  function matchSupported(tag) {
    if (!tag) return "";
    var lower = String(tag).toLowerCase().replace("_", "-");
    if (lower === "zh" || lower.indexOf("zh-hans") === 0 || lower === "zh-cn") return "zh-CN";
    if (lower.indexOf("zh-hant") === 0 || lower === "zh-tw" || lower === "zh-hk" || lower === "zh-mo") return "zh-TW";
    if (lower === "nb" || lower === "nn" || lower.indexOf("no-") === 0) return "no";
    if (lower === "fil" || lower.indexOf("fil-") === 0) return "tl";
    if (lower === "iw") return "he";
    if (lower === "in") return "id";
    for (var n = 0; n < codes.length; n += 1) {
      if (codes[n].toLowerCase() === lower) return codes[n];
    }
    var base = lower.split("-")[0];
    for (var b = 0; b < codes.length; b += 1) {
      if (codes[b].toLowerCase() === base) return codes[b];
    }
    return "";
  }

  function browserPrefs() {
    var list = navigator.languages && navigator.languages.length
      ? navigator.languages
      : [navigator.language || ""];
    var found = [];
    for (var p = 0; p < list.length; p += 1) {
      var code = matchSupported(list[p]);
      if (code && found.indexOf(code) === -1) found.push(code);
    }
    return found;
  }

  function languageForCountry(country) {
    var options = COUNTRY[country] || [];
    var prefs = browserPrefs();
    for (var p = 0; p < prefs.length; p += 1) {
      if (options.indexOf(prefs[p]) !== -1) return prefs[p];
    }
    if (options.length && known(options[0])) return options[0];
    return PAGE;
  }

  function cookieHost() {
    var host = location.hostname;
    if (!host || host === "localhost" || /^\d+\.\d+\.\d+\.\d+$/.test(host) || host.indexOf(":") !== -1) return "";
    return host;
  }

  function writeCookies(lang) {
    var value = "/" + PAGE + "/" + lang;
    var host = cookieHost();
    document.cookie = "googtrans=" + value + ";path=/";
    if (!host) return;
    document.cookie = "googtrans=" + value + ";path=/;domain=" + host;
    var parts = host.split(".");
    if (parts.length > 2) {
      document.cookie = "googtrans=" + value + ";path=/;domain=." + parts.slice(-2).join(".");
    }
  }

  function clearCookies() {
    var host = cookieHost();
    var expiry = "expires=Thu, 01 Jan 1970 00:00:00 GMT";
    document.cookie = "googtrans=;path=/;" + expiry;
    if (!host) return;
    document.cookie = "googtrans=;path=/;domain=" + host + ";" + expiry;
    var parts = host.split(".");
    if (parts.length > 2) {
      document.cookie = "googtrans=;path=/;domain=." + parts.slice(-2).join(".") + ";" + expiry;
    }
  }

  function loadGoogle() {
    if (googleRequested) return;
    googleRequested = true;
    window.googleTranslateElementInit = function () {
      if (!window.google || !google.translate || !google.translate.TranslateElement) return;
      new google.translate.TranslateElement({
        pageLanguage: PAGE,
        autoDisplay: false,
        includedLanguages: codes.join(","),
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE
      }, "tx-google-translate");
    };
    var script = document.createElement("script");
    script.src = "https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit";
    script.async = true;
    document.body.appendChild(script);
  }

  function combo() {
    return document.querySelector(".goog-te-combo");
  }

  function applyCombo(code) {
    var select = combo();
    if (!select) return false;
    select.value = code === PAGE ? "" : code;
    select.dispatchEvent(new Event("change"));
    return true;
  }

  function remember(code) {
    try { localStorage.setItem(CHOICE_KEY, code); } catch (err) { /* private mode */ }
  }

  function readChoice() {
    try { return localStorage.getItem(CHOICE_KEY) || ""; } catch (err) { return ""; }
  }

  function readAuto() {
    try { return sessionStorage.getItem(AUTO_KEY) || ""; } catch (err) { return ""; }
  }

  function writeAuto(code) {
    try { sessionStorage.setItem(AUTO_KEY, code); } catch (err) { /* private mode */ }
  }

  function setLabel(code) {
    current = known(code) ? code : PAGE;
    if (labelEl) labelEl.textContent = names[current];
    var options = root ? root.querySelectorAll(".tx-lang__option") : [];
    for (var n = 0; n < options.length; n += 1) {
      var on = options[n].getAttribute("data-lang") === current;
      options[n].classList.toggle("is-active", on);
      if (on) options[n].setAttribute("aria-selected", "true");
      else options[n].removeAttribute("aria-selected");
    }
  }

  function choose(code) {
    if (!known(code)) code = PAGE;
    remember(code);
    setLabel(code);
    closeMenu();
    if (code === PAGE) {
      clearCookies();
      location.reload();
      return;
    }
    writeCookies(code);
    loadGoogle();
    if (applyCombo(code)) return;
    var tries = 0;
    var timer = setInterval(function () {
      tries += 1;
      if (applyCombo(code)) {
        clearInterval(timer);
        return;
      }
      if (tries > 8) {
        clearInterval(timer);
        location.reload();
      }
    }, 120);
  }

  function closeMenu() {
    if (!root) return;
    root.classList.remove("is-open");
    var btn = root.querySelector(".tx-lang__btn");
    if (btn) btn.setAttribute("aria-expanded", "false");
  }

  function renderList(query) {
    var needle = (query || "").toLowerCase();
    listEl.innerHTML = "";
    var shown = 0;
    for (var n = 0; n < LANGUAGES.length; n += 1) {
      var code = LANGUAGES[n][0];
      var name = LANGUAGES[n][1];
      if (needle && name.toLowerCase().indexOf(needle) === -1 && code.toLowerCase().indexOf(needle) === -1) continue;
      var item = document.createElement("li");
      var button = document.createElement("button");
      button.type = "button";
      button.className = "tx-lang__option" + (code === current ? " is-active" : "");
      button.setAttribute("data-lang", code);
      button.setAttribute("role", "option");
      button.textContent = name;
      item.appendChild(button);
      listEl.appendChild(item);
      shown += 1;
    }
    emptyEl.hidden = shown !== 0;
  }

  function build() {
    root = document.createElement("div");
    root.className = "tx-lang notranslate";
    root.setAttribute("translate", "no");
    root.innerHTML =
      '<button type="button" class="tx-lang__btn" aria-expanded="false" aria-haspopup="listbox" aria-label="Translate this page">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8">' +
          '<circle cx="12" cy="12" r="9"></circle>' +
          '<path d="M3 12h18M12 3c2.5 2.8 3.8 5.8 3.8 9s-1.3 6.2-3.8 9c-2.5-2.8-3.8-5.8-3.8-9s1.3-6.2 3.8-9z"></path>' +
        "</svg>" +
        '<span class="tx-lang__label"></span>' +
      "</button>" +
      '<div class="tx-lang__menu">' +
        '<input class="tx-lang__search" type="search" placeholder="Search languages" aria-label="Search languages">' +
        '<ul class="tx-lang__list" role="listbox"></ul>' +
        '<p class="tx-lang__empty" hidden>No matching language.</p>' +
        '<p class="tx-lang__credit">Powered by <a href="https://translate.google.com" target="_blank" rel="noopener">Google Translate</a></p>' +
      "</div>" +
      '<div id="tx-google-translate" class="tx-lang__engine" aria-hidden="true"></div>';
    document.body.appendChild(root);
    labelEl = root.querySelector(".tx-lang__label");
    listEl = root.querySelector(".tx-lang__list");
    emptyEl = root.querySelector(".tx-lang__empty");
    searchEl = root.querySelector(".tx-lang__search");
    setLabel(current);
    renderList("");

    root.querySelector(".tx-lang__btn").addEventListener("click", function () {
      var open = root.classList.toggle("is-open");
      this.setAttribute("aria-expanded", open ? "true" : "false");
      if (open) {
        searchEl.value = "";
        renderList("");
        searchEl.focus();
      }
    });

    searchEl.addEventListener("input", function () {
      renderList(searchEl.value);
    });

    listEl.addEventListener("click", function (event) {
      var button = event.target.closest ? event.target.closest(".tx-lang__option") : null;
      if (!button) return;
      choose(button.getAttribute("data-lang"));
    });

    document.addEventListener("click", function (event) {
      if (!root.contains(event.target)) closeMenu();
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") closeMenu();
    });
  }

  function activate(code) {
    if (!known(code)) code = PAGE;
    setLabel(code);
    if (code === PAGE) {
      clearCookies();
      return;
    }
    writeCookies(code);
    loadGoogle();
  }

  function withTimeout(promise, ms) {
    return new Promise(function (resolve) {
      var settled = false;
      var timer = setTimeout(function () {
        if (!settled) {
          settled = true;
          resolve("");
        }
      }, ms);
      promise.then(function (value) {
        if (!settled) {
          settled = true;
          clearTimeout(timer);
          resolve(value);
        }
      }, function () {
        if (!settled) {
          settled = true;
          clearTimeout(timer);
          resolve("");
        }
      });
    });
  }

  function fetchCountry(url, field) {
    return fetch(url, { credentials: "omit", cache: "no-store" }).then(function (response) {
      if (!response.ok) throw new Error("geo failed");
      return response.json();
    }).then(function (data) {
      return String((data && data[field]) || "").toUpperCase();
    });
  }

  function detectCountry() {
    return withTimeout(fetchCountry("https://get.geojs.io/v1/ip/country.json", "country"), 2500).then(function (country) {
      if (country) return country;
      return withTimeout(fetchCountry("https://api.country.is/", "country"), 2500);
    });
  }

  function start() {
    build();
    var saved = readChoice();
    if (known(saved)) {
      activate(saved);
      return;
    }
    var auto = readAuto();
    if (known(auto)) {
      activate(auto);
      return;
    }
    detectCountry().then(function (country) {
      var lang = country && /^[A-Z]{2}$/.test(country)
        ? languageForCountry(country)
        : (browserPrefs()[0] || PAGE);
      writeAuto(lang);
      activate(lang);
    }).catch(function () {
      var lang = browserPrefs()[0] || PAGE;
      writeAuto(lang);
      activate(lang);
    });
  }

  if (document.body) start();
  else document.addEventListener("DOMContentLoaded", start);
})();
