/* --- API documentation viewer ----------------------------------------------
   Reads the OpenAPI document this application generates and draws it, with a
   working "send" for each operation.

   Written rather than vendored for two reasons. The Content-Security-Policy is
   `default-src 'self'` and permits no off-origin scripts, so a CDN build of
   Swagger UI would be blocked — and weakening the policy for a documentation
   page is a poor trade. And a repository that hand-writes its own Code 128 and
   QR encoders has no business carrying a megabyte of somebody else's
   JavaScript to render a JSON file.

   Everything shown comes from the spec. Nothing about any particular endpoint
   is written here, so this cannot describe an API that does not exist. */
(function () {
    'use strict';

    var root = document.getElementById('api-docs');
    if (!root) return;

    var keyInput = document.getElementById('api-key-input');

    /** Build an element. Text is set as text, never as HTML. */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    /** Markdown is overkill; the spec's descriptions use blank lines and **bold**. */
    function paragraphs(parent, text) {
        String(text || '').split(/\n{2,}/).forEach(function (block) {
            var p = el('p', 'muted');
            // Bold runs are the only markup the descriptions use.
            block.split(/\*\*/).forEach(function (part, i) {
                p.appendChild(i % 2 ? el('strong', null, part) : document.createTextNode(part));
            });
            parent.appendChild(p);
        });
    }

    function headers() {
        var h = { 'Accept': 'application/json' };
        var key = keyInput && keyInput.value.trim();
        if (key) h.Authorization = 'Bearer ' + key;
        return h;
    }

    /** One operation: method, path, description, parameters, and a Send button. */
    function operation(method, path, spec, base) {
        var details = el('details', 'api-op');
        var summary = el('summary', 'api-op-head');

        summary.appendChild(el('span', 'api-method api-method-' + method, method.toUpperCase()));
        summary.appendChild(el('span', 'api-path mono', path));
        summary.appendChild(el('span', 'api-summary', spec.summary || ''));
        details.appendChild(summary);

        var body = el('div', 'api-op-body');

        if (spec.description) paragraphs(body, spec.description);

        var params = spec.parameters || [];
        var inputs = {};

        if (params.length) {
            var table = el('table', 'table table-compact api-params');
            var thead = el('thead');
            var hr = el('tr');
            ['Parameter', 'In', 'Value', 'Notes'].forEach(function (h) {
                var th = el('th', null, h);
                th.setAttribute('scope', 'col');
                hr.appendChild(th);
            });
            thead.appendChild(hr);
            table.appendChild(thead);

            var tbody = el('tbody');

            params.forEach(function (param) {
                var tr = el('tr');
                tr.appendChild(el('td', 'mono', param.name + (param.required ? ' *' : '')));
                tr.appendChild(el('td', null, param.in));

                var cell = el('td');
                var field;

                var schema = param.schema || {};
                var enums = schema.enum || (schema.items && schema.items.enum);

                if (enums && enums.length && enums.length <= 40) {
                    field = el('select', 'input input-sm');
                    field.appendChild(el('option', null, ''));
                    enums.forEach(function (value) {
                        var option = el('option', null, value);
                        option.value = value;
                        field.appendChild(option);
                    });
                } else {
                    field = el('input', 'input input-sm');
                    field.type = schema.type === 'integer' || schema.type === 'number' ? 'number' : 'text';
                    if (schema.default !== undefined) field.placeholder = String(schema.default);
                }

                inputs[param.name] = { field: field, in: param.in };
                cell.appendChild(field);
                tr.appendChild(cell);
                tr.appendChild(el('td', 'cell-sub', param.description || ''));
                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            var wrap = el('div', 'table-wrap');
            wrap.appendChild(table);
            body.appendChild(wrap);
        }

        var bodyField = null;

        if (spec.requestBody) {
            var schema = ((spec.requestBody.content || {})['application/json'] || {}).schema || {};
            var example = {};

            Object.keys(schema.properties || {}).forEach(function (name) {
                var property = schema.properties[name];
                if ((schema.required || []).indexOf(name) !== -1) {
                    example[name] = property.enum ? property.enum[0] : (property.type === 'integer' ? 0 : '');
                }
            });

            body.appendChild(el('span', 'label', 'Request body (JSON)'));

            bodyField = el('textarea', 'input mono');
            bodyField.rows = 6;
            bodyField.value = JSON.stringify(example, null, 2);
            body.appendChild(bodyField);

            var fields = el('div', 'table-wrap');
            var ftable = el('table', 'table table-compact');
            var fhead = el('thead');
            var fhr = el('tr');
            ['Field', 'Type', 'Required', 'Notes'].forEach(function (h) {
                var th = el('th', null, h);
                th.setAttribute('scope', 'col');
                fhr.appendChild(th);
            });
            fhead.appendChild(fhr);
            ftable.appendChild(fhead);

            var fbody = el('tbody');
            Object.keys(schema.properties || {}).forEach(function (name) {
                var property = schema.properties[name];
                var tr = el('tr');
                tr.appendChild(el('td', 'mono', name));
                tr.appendChild(el('td', null, property.enum ? property.enum.join(' | ') : property.type));
                tr.appendChild(el('td', null, (schema.required || []).indexOf(name) !== -1 ? 'yes' : ''));
                tr.appendChild(el('td', 'cell-sub', property.description || ''));
                fbody.appendChild(tr);
            });
            ftable.appendChild(fbody);
            fields.appendChild(ftable);
            body.appendChild(fields);
        }

        var actions = el('div', 'form-actions');
        var send = el('button', 'btn btn-primary btn-sm', 'Send');
        send.type = 'button';
        actions.appendChild(send);

        var shownUrl = el('span', 'muted mono api-url');
        actions.appendChild(shownUrl);
        body.appendChild(actions);

        var output = el('pre', 'mono code-block api-response');
        output.hidden = true;
        body.appendChild(output);

        function buildUrl() {
            var url = path;
            var query = [];

            Object.keys(inputs).forEach(function (name) {
                var value = inputs[name].field.value.trim();
                if (!value) return;

                if (inputs[name].in === 'path') {
                    url = url.replace('{' + name.replace(/\[\]$/, '') + '}', encodeURIComponent(value));
                } else {
                    query.push(encodeURIComponent(name) + '=' + encodeURIComponent(value));
                }
            });

            return base + url + (query.length ? '?' + query.join('&') : '');
        }

        Object.keys(inputs).forEach(function (name) {
            inputs[name].field.addEventListener('input', function () {
                shownUrl.textContent = buildUrl();
            });
        });

        send.addEventListener('click', function () {
            var url = buildUrl();

            if (url.indexOf('{') !== -1) {
                output.hidden = false;
                output.textContent = 'Fill in the path parameter first — ' + url;
                return;
            }

            shownUrl.textContent = url;
            output.hidden = false;
            output.textContent = 'Sending…';
            send.disabled = true;

            var init = { method: method.toUpperCase(), headers: headers() };

            if (bodyField) {
                init.headers['Content-Type'] = 'application/json';
                init.body = bodyField.value;
            }

            fetch(url, init).then(function (response) {
                return response.text().then(function (text) {
                    var pretty = text;
                    try { pretty = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { /* not JSON */ }

                    var limit = response.headers.get('X-RateLimit-Remaining');

                    output.textContent = response.status + ' ' + response.statusText
                        + (limit === null ? '' : '   (' + limit + ' requests left this minute)')
                        + '\n\n' + (pretty || '(no body)');
                });
            }).catch(function (error) {
                output.textContent = 'Request failed: ' + error.message;
            }).finally(function () {
                send.disabled = false;
            });
        });

        shownUrl.textContent = buildUrl();
        details.appendChild(body);

        return details;
    }

    fetch(root.dataset.specUrl, { headers: { Accept: 'application/json' } })
        .then(function (response) {
            if (!response.ok) throw new Error('the specification returned ' + response.status);
            return response.json();
        })
        .then(function (spec) {
            root.textContent = '';

            // The spec's server URL is the *documented* address, built from
            // APP_URL — right for a script somewhere else, wrong for this page.
            // Reached on a hostname APP_URL does not name (an internal alias, a
            // bare IP, a proxy) an absolute URL is cross-origin, and the
            // Content-Security-Policy's `connect-src 'self'` refuses it, so
            // every "Send" fails with an opaque "Failed to fetch". Requests go
            // to this page's own origin instead, keeping only the path — which
            // is also what makes a subdirectory install work.
            var declared = ((spec.servers || [])[0] || {}).url || '/api/v1';
            var base;

            try {
                base = new URL(declared, window.location.href).pathname.replace(/\/$/, '');
            } catch (e) {
                base = '/api/v1';
            }

            var intro = el('div', 'card');
            intro.appendChild(el('h2', null, 'Conventions'));
            paragraphs(intro, (spec.info || {}).description || '');
            root.appendChild(intro);

            // Group operations by tag, in the order the spec lists the tags —
            // which is the order the resources are declared in.
            var byTag = {};

            Object.keys(spec.paths || {}).forEach(function (path) {
                Object.keys(spec.paths[path]).forEach(function (method) {
                    var op = spec.paths[path][method];
                    ((op.tags || ['other'])).forEach(function (tag) {
                        (byTag[tag] = byTag[tag] || []).push({ method: method, path: path, spec: op });
                    });
                });
            });

            (spec.tags || []).forEach(function (tag) {
                var operations = byTag[tag.name];
                if (!operations) return;

                var card = el('div', 'card');
                card.appendChild(el('h2', null, tag.name));
                if (tag.description) paragraphs(card, tag.description);

                operations.forEach(function (entry) {
                    card.appendChild(operation(entry.method, entry.path, entry.spec, base));
                });

                root.appendChild(card);
            });
        })
        .catch(function (error) {
            root.textContent = '';
            var card = el('div', 'card card-warn');
            card.appendChild(el('h2', null, 'The specification could not be loaded'));
            card.appendChild(el('p', null, error.message
                + '. If the API is switched off, an administrator can enable it under Settings → API keys.'));
            root.appendChild(card);
        });
})();
