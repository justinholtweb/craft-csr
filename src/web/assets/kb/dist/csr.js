/**
 * CSR's front-end runtime.
 *
 * Progressive enhancement, meant literally: everything here upgrades markup the server already
 * sent. With this file blocked, the search box still submits, the rating buttons are still form
 * posts, and the support form still works — you just lose the live results, the silent vote and
 * the suggestions panel.
 *
 * No dependencies, no build step, and no framework. A help centre is the page a customer reaches
 * when something is already going wrong; it should not be the heaviest page on the site.
 */
(function () {
    'use strict';

    var csrf = {
        name: document.querySelector('meta[name="csrf-param"]')?.content,
        value: document.querySelector('meta[name="csrf-token"]')?.content,
    };

    /** Post to a Craft action, as JSON, with the CSRF token the page was rendered with. */
    function post(action, data) {
        var body = new FormData();
        body.append('action', action);

        if (csrf.name && csrf.value) {
            body.append(csrf.name, csrf.value);
        }

        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== null && data[key] !== undefined) {
                body.append(key, data[key]);
            }
        });

        return fetch(window.location.href, {
            method: 'POST',
            body: body,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (response) {
            return response.json();
        });
    }

    function get(url, params) {
        var query = new URLSearchParams(params || {});

        return fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + query.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (response) {
            return response.json();
        });
    }

    function debounce(fn, wait) {
        var timer;

        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(null, args);
            }, wait);
        };
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);

        return div.innerHTML;
    }

    // Live search
    // ---------------------------------------------------------------------

    function initSearch(form) {
        var input = form.querySelector('[data-csr-search-input]');
        var results = form.querySelector('[data-csr-search-results]');
        var endpoint = form.getAttribute('data-csr-suggest-url');
        var minLength = parseInt(form.getAttribute('data-csr-min-length') || '2', 10);

        if (!input || !results || !endpoint) {
            return;
        }

        // The panel is described to assistive technology as a listbox that grows under the input,
        // rather than appearing silently for people who can see it and not for anybody else.
        results.setAttribute('role', 'listbox');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('aria-expanded', 'false');

        var close = function () {
            results.innerHTML = '';
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        };

        var render = function (items) {
            if (!items.length) {
                close();

                return;
            }

            results.innerHTML = items.map(function (item) {
                return '<li role="option"><a href="' + escapeHtml(item.url) + '">' +
                    '<span class="csr-suggestion__title">' + escapeHtml(item.title) + '</span>' +
                    (item.category ? '<span class="csr-suggestion__meta">' + escapeHtml(item.category) + '</span>' : '') +
                    '</a></li>';
            }).join('');

            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        input.addEventListener('input', debounce(function () {
            var value = input.value.trim();

            if (value.length < minLength) {
                close();

                return;
            }

            get(endpoint, { q: value }).then(function (data) {
                render(data.results || []);
            }).catch(close);
        }, 200));

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });

        document.addEventListener('click', function (event) {
            if (!form.contains(event.target)) {
                close();
            }
        });
    }

    // "Was this helpful?"
    // ---------------------------------------------------------------------

    function initRating(widget) {
        var articleId = widget.getAttribute('data-csr-article');
        var buttons = widget.querySelectorAll('[data-csr-vote]');
        var thanks = widget.querySelector('[data-csr-thanks]');
        var why = widget.querySelector('[data-csr-why]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                var helpful = button.getAttribute('data-csr-vote') === 'yes';

                post('csr/kb/rate', { articleId: articleId, helpful: helpful ? 1 : 0 }).then(function (data) {
                    widget.setAttribute('data-csr-voted', helpful ? 'yes' : 'no');

                    // The comment box only ever appears after a "no". Asking somebody who just
                    // said the page helped to explain themselves is how you stop people voting.
                    if (data.askWhy && why) {
                        why.hidden = false;
                        var field = why.querySelector('textarea');

                        if (field) {
                            field.focus();
                        }
                    } else if (thanks) {
                        thanks.hidden = false;
                    }
                });
            });
        });

        if (why) {
            var send = why.querySelector('[data-csr-send-why]');

            if (send) {
                send.addEventListener('click', function (event) {
                    event.preventDefault();
                    var field = why.querySelector('textarea');

                    post('csr/kb/rate', {
                        articleId: articleId,
                        helpful: 0,
                        comment: field ? field.value : '',
                    }).then(function () {
                        why.hidden = true;

                        if (thanks) {
                            thanks.hidden = false;
                        }
                    });
                });
            }
        }
    }

    // Deflection: the answer before the form
    // ---------------------------------------------------------------------

    function initDeflection(form) {
        var panel = form.querySelector('[data-csr-suggestions]');
        var subject = form.querySelector('[data-csr-subject]');
        var message = form.querySelector('[data-csr-message]');
        var category = form.querySelector('[data-csr-category]');
        var endpoint = form.getAttribute('data-csr-suggest-url');
        var minChars = parseInt(form.getAttribute('data-csr-min-chars') || '8', 10);

        if (!panel || !subject || !endpoint) {
            return;
        }

        var list = panel.querySelector('[data-csr-suggestion-list]');
        var shown = false;

        var render = function (items) {
            if (!items.length) {
                panel.hidden = true;

                return;
            }

            list.innerHTML = items.map(function (item) {
                return '<li class="csr-suggestion' + (item.confident ? ' csr-suggestion--strong' : '') + '">' +
                    '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener" data-csr-suggestion="' + escapeHtml(item.id) + '">' +
                    '<span class="csr-suggestion__title">' + escapeHtml(item.title) + '</span>' +
                    '<span class="csr-suggestion__snippet">' + escapeHtml(item.snippet) + '</span>' +
                    '</a></li>';
            }).join('');

            panel.hidden = false;

            // Announced once rather than on every keystroke: a live region that re-reads three
            // article titles every time somebody types a letter is unusable with a screen reader.
            if (!shown) {
                panel.setAttribute('aria-live', 'polite');
                shown = true;
            }
        };

        var lookup = debounce(function () {
            var text = [subject.value, message ? message.value : ''].join(' ').trim();

            if (text.length < minChars) {
                panel.hidden = true;

                return;
            }

            get(endpoint, {
                text: text,
                categoryId: category ? category.value : '',
            }).then(function (data) {
                render(data.results || []);
            }).catch(function () {
                panel.hidden = true;
            });
        }, 400);

        subject.addEventListener('input', lookup);

        if (message) {
            message.addEventListener('input', lookup);
        }

        if (category) {
            category.addEventListener('change', lookup);
        }

        // Opening a suggestion is not yet a deflection — they may still write in — so it is
        // recorded as a click and nothing else.
        panel.addEventListener('click', function (event) {
            var link = event.target.closest('[data-csr-suggestion]');

            if (link) {
                post('csr/support/suggestion-clicked', { articleId: link.getAttribute('data-csr-suggestion') });
            }
        });

        var answered = panel.querySelector('[data-csr-deflected]');

        if (answered) {
            answered.addEventListener('click', function (event) {
                event.preventDefault();

                post('csr/support/deflected', {}).then(function () {
                    var done = panel.querySelector('[data-csr-deflected-thanks]');

                    if (done) {
                        done.hidden = false;
                    }

                    form.querySelectorAll('[data-csr-form-body]').forEach(function (part) {
                        part.hidden = true;
                    });
                });
            });
        }
    }

    // Canned reply shortcuts are control-panel-side; nothing here needs them.

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-csr-search]').forEach(initSearch);
        document.querySelectorAll('[data-csr-rating]').forEach(initRating);
        document.querySelectorAll('[data-csr-support-form]').forEach(initDeflection);
    });
})();
