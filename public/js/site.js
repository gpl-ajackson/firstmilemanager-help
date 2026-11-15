// The help centre's one script: small, plain, no build step and no third-party code. Every
// page works without it; each part below only improves markup that already works as plain HTML,
// and none of it stores anything on the reader's device or counts anything. Add new parts here
// as their own block rather than as new files.
(function () {
    'use strict';

    // ---------- Article Feedback: answer in place ----------
    // "Did this answer your question?" and "What was wrong?" are plain forms that post to the
    // feedback address and get a page back. Here the same post is sent in the background and the
    // answer page's .feedback part replaces the Article's, so the reader stays on the Article.
    // If anything goes wrong the form is posted as plain HTML instead. The answer may have been
    // recorded before it went wrong (a dropped connection), so both posts carry the same random
    // "once" token, kept only in this page's memory: the server counts the click once.
    function randomToken() {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (byte) { return (byte < 16 ? '0' : '') + byte.toString(16); }).join('');
    }

    function postInPlace(form, submitter) {
        var section = form.closest('[data-feedback]');
        var once = randomToken();
        var data = new FormData(form);
        if (submitter && submitter.name) {
            data.append(submitter.name, submitter.value);
        }
        data.append('once', once);
        form.setAttribute('aria-busy', 'true');

        fetch(form.action, {
            method: 'POST',
            body: new URLSearchParams(data),
            headers: { Accept: 'text/html' },
            credentials: 'omit',
        })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var answer = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-feedback]');
                if (!answer) {
                    throw new Error('No feedback in the answer');
                }
                answer = document.importNode(answer, true);
                section.replaceWith(answer);
                // The "Try next" search box arrives after the page loaded.
                Array.prototype.forEach.call(answer.querySelectorAll('form.search'), searchBox);
                var heading = answer.querySelector('.feedback-title');
                if (heading) {
                    heading.setAttribute('tabindex', '-1');
                    heading.focus();
                }
            })
            .catch(function () {
                postPlainly(form, submitter, once);
            });
    }

    function hiddenField(form, name, value) {
        var field = document.createElement('input');
        field.type = 'hidden';
        field.name = name;
        field.value = value;
        form.appendChild(field);
    }

    function postPlainly(form, submitter, once) {
        form.removeAttribute('aria-busy');
        form.setAttribute('data-plain', '');
        if (submitter && submitter.name) {
            hiddenField(form, submitter.name, submitter.value);
        }
        hiddenField(form, 'once', once);
        HTMLFormElement.prototype.submit.call(form);
    }

    if (window.fetch && window.DOMParser && window.FormData && window.URLSearchParams && window.crypto && window.crypto.getRandomValues) {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.closest('[data-feedback]') || form.hasAttribute('data-plain')
                || (form.getAttribute('method') || '').toLowerCase() !== 'post') {
                return;
            }
            event.preventDefault();
            if (form.getAttribute('aria-busy') !== 'true') {
                postInPlace(form, event.submitter);
            }
        });
    }

    // ---------- Search: suggestions as you type (#46) ----------
    // The search box is a plain GET form to /search, which settles (records) a Search when it is
    // sent. Here, as the reader types, the best few Articles are shown under the box, from
    // /suggestions, which records nothing. A Search settles, and is recorded once, when the
    // reader sends the form (Enter or Search, as without script), opens a suggestion, or stops
    // typing for two seconds with three letters or more (POST /searches). Once the script has
    // settled the words, the form carries that Search's id in a hidden "search" field, so
    // sending it doesn't record the same Search again. Nothing is kept between pages.
    var SUGGEST_AFTER_MS = 150;
    var SETTLE_AFTER_MS = 2000;
    var SUGGEST_LETTERS = 2;
    var SETTLE_LETTERS = 3;

    function letters(words) {
        var found = words.match(/[\p{L}\p{N}]/gu);
        return found ? found.length : 0;
    }

    function normal(words) {
        return words.trim().replace(/\s+/g, ' ');
    }

    function suggestAsYouType(form) {
        var box = form.querySelector('input[name="q"]');
        if (!box) {
            return;
        }

        var status = document.createElement('p');
        status.className = 'visually-hidden';
        status.setAttribute('role', 'status');
        var list = document.createElement('ul');
        list.className = 'suggestions';
        // A box with no id (the one under "Try next", swapped into an Article page, whose only
        // ids are its Section Names) gives its list none either, and goes without aria-controls.
        if (box.id) {
            list.id = box.id + '-suggestions';
        }
        list.setAttribute('aria-label', 'Suggestions');
        list.hidden = true;
        var settledField = document.createElement('input');
        settledField.type = 'hidden';
        settledField.name = 'search';
        settledField.disabled = true;
        form.appendChild(settledField);
        form.appendChild(status);
        form.appendChild(list);
        if (list.id) {
            box.setAttribute('aria-controls', list.id);
        }

        var shownFor = null; // the words, as typed, whose suggestions are shown
        var settled = null; // {words, search, items}: the Search settled for the words in the box
        var settling = null; // {words, promise}
        var suggestTimer = null;
        var settleTimer = null;
        var asking = null;

        function hide() {
            list.hidden = true;
            list.textContent = '';
            status.textContent = '';
            shownFor = null;
        }

        // What an answer lists, in place order: Glossary answers first, then Articles.
        function itemsOf(answer, articles) {
            return (answer.glossary_answers || []).map(function (entry) {
                return {
                    address: entry.address,
                    opens_at: entry.opens_at,
                    title: entry.term,
                    meta: (entry.also_called ? 'Also called ' + entry.also_called + ' · ' : '')
                        + (entry.for_clients ? 'Client Glossary' : 'Glossary'),
                    for_clients: false,
                };
            }).concat(articles.map(function (article) {
                return {
                    address: article.address,
                    opens_at: article.opens_at,
                    title: article.title,
                    meta: (article.section ? 'Opens at ' + article.section + ' · ' : '') + article.type + ' · ' + article.product_area,
                    for_clients: article.for_clients,
                };
            }));
        }

        function show(items, typed) {
            list.textContent = '';
            shownFor = typed;
            items.forEach(function (item) {
                var link = document.createElement('a');
                link.href = item.address;
                link.setAttribute('data-opens-at', item.opens_at);
                if (item.address.indexOf('/searches/') === 0) {
                    link.rel = 'nofollow';
                }
                var title = document.createElement('span');
                title.className = 'suggestion-title';
                title.textContent = item.title;
                link.appendChild(title);
                var meta = document.createElement('span');
                meta.className = 'suggestion-meta';
                meta.textContent = item.meta;
                if (item.for_clients) {
                    var badge = document.createElement('span');
                    badge.className = 'badge badge-client';
                    badge.textContent = 'For your clients';
                    meta.appendChild(document.createTextNode(' '));
                    meta.appendChild(badge);
                }
                link.appendChild(meta);
                var entry = document.createElement('li');
                entry.appendChild(link);
                list.appendChild(entry);
            });
            list.hidden = items.length === 0;
            status.textContent = items.length === 0 ? 'No suggestions'
                : items.length + (items.length === 1 ? ' suggestion' : ' suggestions') + ' below the search box';
        }

        function suggest(typed) {
            if (asking) {
                asking.abort();
            }
            asking = new AbortController();
            fetch('/suggestions?q=' + encodeURIComponent(typed), {
                headers: { Accept: 'application/json' },
                credentials: 'omit',
                signal: asking.signal,
            })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                .then(function (answer) {
                    var words = normal(typed);
                    if (normal(box.value) !== words) {
                        return; // the reader has typed on
                    }
                    if (settled && settled.words === words) {
                        show(settled.items, typed);
                    } else {
                        show(itemsOf(answer, answer.suggestions), typed);
                    }
                })
                .catch(function () {
                    // Suggestions are extra: the form still searches.
                });
        }

        // Settles the words as typed: records one Search, unless these words are settled already.
        function settle(typed) {
            var words = normal(typed);
            if (settled && settled.words === words) {
                return Promise.resolve(settled);
            }
            if (settling && settling.words === words) {
                return settling.promise;
            }
            var body = new URLSearchParams();
            body.append('q', typed);
            var promise = fetch('/searches', {
                method: 'POST',
                body: body,
                headers: { Accept: 'application/json' },
                credentials: 'omit',
            })
                .then(function (response) { return response.status === 201 ? response.json() : Promise.reject(response.status); })
                .then(function (answer) {
                    var done = { words: words, search: answer.search, items: itemsOf(answer, answer.results) };
                    if (normal(box.value) === words) {
                        settled = done;
                        settledField.value = done.search;
                        settledField.disabled = false;
                        show(done.items, typed);
                    }
                    return done;
                });
            settling = { words: words, promise: promise };
            var finished = function () {
                if (settling && settling.promise === promise) {
                    settling = null;
                }
            };
            promise.then(finished, finished);
            return promise;
        }

        box.addEventListener('input', function () {
            clearTimeout(suggestTimer);
            clearTimeout(settleTimer);
            var typed = box.value;
            var words = normal(typed);
            if (settled && settled.words !== words) {
                settled = null;
                settledField.disabled = true;
            }
            if (letters(words) < SUGGEST_LETTERS) {
                if (asking) {
                    asking.abort();
                }
                hide();
                return;
            }
            suggestTimer = setTimeout(function () { suggest(typed); }, SUGGEST_AFTER_MS);
            if (letters(words) >= SETTLE_LETTERS) {
                settleTimer = setTimeout(function () { settle(typed).catch(function () {}); }, SETTLE_AFTER_MS);
            }
        });

        // Opening a suggestion settles its words first, then goes through the Search's
        // "opened" address. A link that already goes through it records the opening itself.
        list.addEventListener('click', function (event) {
            var link = event.target.closest('a');
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
                || link.getAttribute('href').indexOf('/searches/') === 0 || shownFor === null) {
                return;
            }
            event.preventDefault();
            clearTimeout(settleTimer);
            var opensAt = link.getAttribute('data-opens-at');
            settle(shownFor).then(function (done) {
                var result = done.items.find(function (each) { return each.opens_at === opensAt; });
                window.location.assign(result ? result.address : opensAt);
            }, function () {
                window.location.assign(opensAt);
            });
        });

        // Enter or Search sends the form, as without script, naming the Search the script has
        // already settled for these words, if any.
        form.addEventListener('submit', function (event) {
            clearTimeout(suggestTimer);
            clearTimeout(settleTimer);
            var words = normal(box.value);
            if (settled && settled.words === words) {
                settledField.value = settled.search;
                settledField.disabled = false;
                return;
            }
            settledField.disabled = true;
            if (settling && settling.words === words) {
                // Settling now: wait for it, so the Search isn't recorded twice.
                event.preventDefault();
                var send = function () { HTMLFormElement.prototype.submit.call(form); };
                settling.promise.then(send, send);
            }
        });

        // Down from the box into the suggestions, up and down between them, Escape to close.
        box.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' && !list.hidden && list.querySelector('a')) {
                event.preventDefault();
                list.querySelector('a').focus();
            } else if (event.key === 'Escape' && !list.hidden) {
                hide();
            }
        });
        list.addEventListener('keydown', function (event) {
            var links = Array.prototype.slice.call(list.querySelectorAll('a'));
            var at = links.indexOf(document.activeElement);
            if (at < 0) {
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                (links[at + 1] || links[at]).focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                (at === 0 ? box : links[at - 1]).focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                hide();
                box.focus();
            }
        });
    }

    function searchBox(form) {
        if (window.fetch && window.URLSearchParams && window.AbortController) {
            suggestAsYouType(form);
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('form.search'), searchBox);
})();
