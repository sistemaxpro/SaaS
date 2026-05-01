(function () {
    const COOKIE_NAME = 'smx_locale';
    const SUPPORTED = ['es', 'en', 'pt'];
    const TEXT_ATTRIBUTES = ['placeholder', 'title', 'aria-label'];

    const configMessages = window.__SISTEMAX_LOCALE__?.messages;
    const runtimeUiMap = window.__SISTEMAX_LOCALE__?.uiMap || {};
    const messages = {
        es: {
            login: {
                language: 'Idioma'
            }
        },
        en: {
            login: {
                language: 'Language'
            }
        },
        pt: {
            login: {
                language: 'Idioma'
            }
        }
    };

    if (configMessages && typeof configMessages === 'object') {
        const localeFromConfig = normalizeLocale(window.__SISTEMAX_LOCALE__?.locale) || 'es';
        messages[localeFromConfig] = Object.assign({}, messages[localeFromConfig] || {}, configMessages);
    }

    function normalizeLocale(locale) {
        const value = String(locale || '').trim().toLowerCase();
        if (!value) return null;
        if (value.startsWith('pt')) return 'pt';
        if (value.startsWith('en')) return 'en';
        if (value.startsWith('es')) return 'es';
        return null;
    }

    function readCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function resolveInitialLocale() {
        const fromWindow = normalizeLocale(window.__SISTEMAX_LOCALE__?.locale || window.__SISTEMAX_LOCALE__);
        const fromStorage = normalizeLocale(window.localStorage?.getItem('smx_locale'));
        const fromCookie = normalizeLocale(readCookie(COOKIE_NAME));
        const fromHtml = normalizeLocale(document.documentElement?.lang);
        const fromBrowser = normalizeLocale(navigator.language || navigator.userLanguage);
        return fromWindow || fromStorage || fromCookie || fromHtml || fromBrowser || 'es';
    }

    let currentLocale = resolveInitialLocale();

    function writeCookie(locale) {
        document.cookie = COOKIE_NAME + '=' + encodeURIComponent(locale) + '; path=/; max-age=31536000; samesite=lax';
    }

    function applyDocumentLocale(locale) {
        document.documentElement.lang = locale;
        document.documentElement.dataset.locale = locale;
    }

    function findByDotKey(dict, key) {
        return String(key || '').split('.').reduce(function (acc, part) {
            if (!acc || typeof acc !== 'object') return null;
            return Object.prototype.hasOwnProperty.call(acc, part) ? acc[part] : null;
        }, dict);
    }

    function t(key) {
        return findByDotKey(messages[currentLocale] || {}, key)
            || findByDotKey(messages.es || {}, key)
            || key;
    }

    function translateExactText(source) {
        const raw = String(source || '');
        const trimmed = raw.trim();
        if (!trimmed || currentLocale === 'es') return raw;
        const translated = runtimeUiMap[trimmed];
        if (!translated || translated === trimmed) return raw;

        const leading = raw.match(/^\s*/)?.[0] || '';
        const trailing = raw.match(/\s*$/)?.[0] || '';
        return leading + translated + trailing;
    }

    function shouldSkipNode(node) {
        const parent = node && node.parentElement;
        if (!parent) return true;
        const tag = parent.tagName;
        if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT' || tag === 'CODE' || tag === 'PRE') {
            return true;
        }
        if (parent.closest('[data-no-auto-i18n="1"]')) {
            return true;
        }
        return false;
    }

    function translateTextNode(node) {
        if (!node || shouldSkipNode(node)) return;
        const original = node.nodeValue || '';
        const translated = translateExactText(original);
        if (translated !== original) {
            node.nodeValue = translated;
        }
    }

    function translateAttributes(element) {
        if (!element || element.nodeType !== Node.ELEMENT_NODE) return;
        TEXT_ATTRIBUTES.forEach(function (attr) {
            if (!element.hasAttribute(attr)) return;
            const original = element.getAttribute(attr) || '';
            const translated = translateExactText(original);
            if (translated !== original) {
                element.setAttribute(attr, translated);
            }
        });

        if (element instanceof HTMLInputElement) {
            const type = String(element.type || '').toLowerCase();
            if (['button', 'submit', 'reset'].includes(type)) {
                const original = element.value || '';
                const translated = translateExactText(original);
                if (translated !== original) {
                    element.value = translated;
                }
            }
        }
    }

    function translateSubtree(root) {
        if (!root || currentLocale === 'es') return;
        if (root.nodeType === Node.TEXT_NODE) {
            translateTextNode(root);
            return;
        }
        if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) {
            return;
        }

        if (root.nodeType === Node.ELEMENT_NODE) {
            translateAttributes(root);
        }

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
        let current = walker.currentNode;
        while (current) {
            if (current.nodeType === Node.TEXT_NODE) {
                translateTextNode(current);
            } else if (current.nodeType === Node.ELEMENT_NODE) {
                translateAttributes(current);
            }
            current = walker.nextNode();
        }
    }

    function bindAutoTranslator() {
        const start = function () {
            translateSubtree(document.body || document.documentElement);

            const observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (mutation.type === 'characterData') {
                        translateTextNode(mutation.target);
                        return;
                    }
                    if (mutation.type === 'attributes' && mutation.target) {
                        translateAttributes(mutation.target);
                        return;
                    }
                    mutation.addedNodes.forEach(function (node) {
                        translateSubtree(node);
                    });
                });
            });

            observer.observe(document.documentElement, {
                childList: true,
                subtree: true,
                characterData: true,
                attributes: true,
                attributeFilter: TEXT_ATTRIBUTES
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start, { once: true });
        } else {
            start();
        }
    }

    async function setLocale(locale, options) {
        const normalized = normalizeLocale(locale) || 'es';
        const persist = options?.persist !== false;
        currentLocale = normalized;
        applyDocumentLocale(normalized);

        if (persist) {
            try {
                window.localStorage?.setItem('smx_locale', normalized);
            } catch (error) {}
            writeCookie(normalized);
        }

        if (options?.sync !== false) {
            try {
                await fetch('/public/api/locale.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ locale: normalized })
                });
            } catch (error) {}
        }

        document.dispatchEvent(new CustomEvent('smx:locale-changed', {
            detail: { locale: normalized }
        }));

        return normalized;
    }

    applyDocumentLocale(currentLocale);
    bindAutoTranslator();

    window.SmxI18n = {
        supported: SUPPORTED.slice(),
        normalizeLocale,
        getLocale() {
            return currentLocale;
        },
        setLocale,
        translateSubtree,
        t
    };
})();
