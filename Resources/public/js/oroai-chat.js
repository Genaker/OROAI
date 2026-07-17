'use strict';

/**
 * Reusable OroAI chat widget. Call window.initOroAiChat(config) once per
 * instance on the page — the header placeholder and the dashboard widget
 * each get their own independent instance (own DOM ids, own history, own
 * in-flight request) from this same file, instead of maintaining separate
 * copies of the chat logic.
 *
 * config:
 *   idPrefix  {string} e.g. 'oroai-hc' — every element is looked up as
 *             `${idPrefix}-<part>`, so multiple instances never collide.
 *   mode      {'panel'|'inline'}
 *             'panel'  — header behavior: messages slide open in a panel
 *                        mounted below the app header, input relocates
 *                        into the panel while open (see chatBar.html.twig).
 *             'inline' — dashboard widget behavior: messages expand in
 *                        place directly under the input, no relocation.
 */
(function() {
    // Friendly labels for the live "what is it doing" checklist. Falls back
    // to "Running <tool>…" for any tool not listed here.
    const TOOL_LABELS = {
        sql_query: 'Querying the database',
        schema_inspector: 'Inspecting database schema',
        entity_url: 'Looking up admin URL',
        find_entity: 'Searching for matching records',
        doc_search: 'Searching documentation',
        config_inspector: 'Reading system configuration',
        entity_metadata: 'Inspecting entity structure',
        route_search: 'Searching admin routes',
        log_reader: 'Reading log files',
        system_info: 'Checking system info',
        translation_lookup: 'Looking up translations',
        user_info: 'Looking up user info',
        skill: 'Loading skill instructions'
    };

    // Whimsical, Claude-Code-style nonsense status words — all drawn from
    // B2B/OroCommerce domain verbs — shown while the agent is working and no
    // real checklist steps have arrived yet.
    const THINKING_WORDS = [
        'Ordering', 'Quoting', 'Invoicing', 'Checkouting', 'Upselling',
        'Cross-selling', 'Discounting', 'Bundling', 'Reordering', 'Backordering',
        'Fulfilling', 'Drop-shipping', 'Expediting', 'Rescheduling', 'Consolidating',
        'Cataloguing', 'Merchandising', 'Pricing', 'Repricing', 'Tiering',
        'Segmenting', 'Personalizing', 'Configuring', 'Kitting', 'Warehousing',
        'Inventorying', 'Stocking', 'Restocking', 'Picking', 'Packing',
        'Palletizing', 'Routing', 'Dispatching', 'Delivering', 'Tracking',
        'Returning', 'Refunding', 'Reconciling', 'Billing', 'Crediting',
        'Taxing', 'Auditing', 'Forecasting', 'Budgeting', 'Approving',
        'Escalating', 'Onboarding', 'Provisioning', 'Authorizing', 'Verifying',
        'Validating', 'Syncing', 'Importing', 'Exporting', 'Migrating',
        'Indexing', 'Caching', 'Querying', 'Searching', 'Filtering',
        'Sorting', 'Paginating', 'Rendering', 'Carting', 'Wishlisting',
        'Negotiating', 'Drafting', 'Submitting', 'Rejecting', 'Assigning',
        'Delegating', 'Notifying', 'Alerting', 'Flagging', 'Tagging',
        'Labeling', 'Categorizing', 'Classifying', 'Grouping', 'Targeting',
        'Localizing', 'Translating', 'Converting', 'Calculating', 'Totaling',
        'Summing', 'Tallying', 'Balancing', 'Settling', 'Clearing',
        'Posting', 'Journaling', 'Booking', 'Logging', 'Procuring',
        'Requisitioning', 'Contracting', 'Sourcing', 'Vetting', 'Rebalancing'
    ];

    function initOroAiChat(config) {
        const idPrefix = config.idPrefix;
        const mode = config.mode || 'panel';
        const isPanelMode = mode === 'panel';

        function id(part) {
            return idPrefix + '-' + part;
        }
        function el(part) {
            return document.getElementById(id(part));
        }

        const root = el('root');
        if (!root) return;

        const messageUrl = root.dataset.messageUrl;
        const progressUrl = root.dataset.progressUrl;
        const sessionsUrl = root.dataset.sessionsUrl;
        const sessionUrl = root.dataset.sessionUrl;
        const csrfToken = root.dataset.csrfToken;
        const panel = isPanelMode ? el('panel') : null;
        const body = el('body');
        const msgs = el('msgs');
        const minimizeBtn = el('minimize');
        const clearBtn = el('clear');
        const input = el('input');
        const sendBtn = el('send');
        const anchor = isPanelMode ? el('anchor') : null;
        const panelInputBar = isPanelMode ? el('panel-input-bar') : null;
        const tokensLabel = el('tokens');
        const tokenBar = el('tokenbar');
        const sessionsBox = el('sessions');

        if (!body || !msgs || !minimizeBtn || !clearBtn || !input || !sendBtn) return;

        // Conversation history lives SERVER-side (ChatSessionStore, keyed by
        // session id) — the widget sends only the new message. The server
        // loads, token-trims and persists the transcript, so a page reload or
        // another device resumes the same conversation.
        let isOpen = false;
        let inputMoved = false;
        let sessionTokens = 0;
        let sessionCost = 0;
        // Debug session id — also the transcript file name under
        // var/cache/{env}/chats/ on the server; shown in the header label.
        let sessionId = newSessionId();

        function newSessionId() {
            return Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        }

        // Show the session id in the header immediately, not only after the
        // first reply arrives (function declarations are hoisted).
        updateTokensLabel();
        loadSessions();
        let progressTimer = null;
        let thinkingWordTimer = null;
        let lastThinkingWord = null;

        function nextThinkingWord() {
            let word = THINKING_WORDS[Math.floor(Math.random() * THINKING_WORDS.length)];
            while (word === lastThinkingWord && THINKING_WORDS.length > 1) {
                word = THINKING_WORDS[Math.floor(Math.random() * THINKING_WORDS.length)];
            }
            lastThinkingWord = word;
            return word + '…';
        }

        function setThinkingText(node) {
            node.textContent = nextThinkingWord();
            node.classList.remove('oroai-hc-loading--pulse');
            void node.offsetWidth;
            node.classList.add('oroai-hc-loading--pulse');
        }

        function startThinkingWordRotation() {
            stopThinkingWordRotation();
            thinkingWordTimer = setInterval(function() {
                const node = el('progress');
                if (!node || !node.classList.contains('oroai-hc-loading')) {
                    stopThinkingWordRotation();
                    return;
                }
                setThinkingText(node);
            }, 1400);
        }

        function stopThinkingWordRotation() {
            if (thinkingWordTimer !== null) {
                clearInterval(thinkingWordTimer);
                thinkingWordTimer = null;
            }
        }

        // ── Panel mode only: mount panel below the header container ────────
        function mountPanel() {
            if (!isPanelMode) return;
            const container = document.querySelector('.app-header__container-panel');
            if (container && panel) {
                container.parentNode.insertBefore(panel, container.nextSibling);
            }
        }

        if (isPanelMode) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', mountPanel);
            } else {
                mountPanel();
            }
        }

        // ── Panel mode only: relocate input into / out of panel ────────────
        function moveInputToPanel() {
            if (!isPanelMode || inputMoved) return;
            panelInputBar.appendChild(root);
            inputMoved = true;
        }

        function moveInputToHeader() {
            if (!isPanelMode || !inputMoved) return;
            anchor.parentNode.insertBefore(root, anchor);
            inputMoved = false;
        }

        // ── Panel mode only: panel open / close ─────────────────────────────
        function openPanel() {
            if (!isPanelMode || isOpen) return;
            isOpen = true;
            panel.classList.add('oroai-panel--open');
            panel.setAttribute('aria-hidden', 'false');
        }

        function closePanel() {
            if (!isPanelMode || !isOpen) return;
            isOpen = false;
            panel.classList.remove('oroai-panel--open');
            panel.setAttribute('aria-hidden', 'true');
            moveInputToHeader();
        }

        if (isPanelMode) {
            // Re-open panel when user focuses the header input and there is history
            input.addEventListener('focus', function() {
                if (msgs.children.length > 0) {
                    openPanel();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && isOpen) {
                    closePanel();
                    input.blur();
                }
            });

            document.addEventListener('click', function(e) {
                if (isOpen && !panel.contains(e.target) && !root.contains(e.target)) {
                    closePanel();
                }
            });
        }

        // ── Messages area expand / collapse ──────────────────────
        function expand() {
            body.style.display = 'block';
            if (isPanelMode) {
                openPanel();
                moveInputToPanel();
                setTimeout(function() {
                    input.focus();
                }, 50);
            }
        }

        function collapseBody() {
            body.style.display = 'none';
            if (isPanelMode) closePanel();
        }

        minimizeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            collapseBody();
        });

        clearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            msgs.innerHTML = '';
            // Stay open on an empty transcript (not collapseBody()) so Recent
            // chats is still reachable — otherwise clicking Clear hides the
            // very list meant to let you jump into a different past
            // conversation instead of starting from scratch.
            expand();
            sessionTokens = 0;
            sessionCost = 0;
            sessionId = newSessionId();
            updateTokensLabel();
            loadSessions();
        });

        // ── Send ──────────────────────────────────────────────────
        function send() {
            const msg = input.value.trim();
            if (!msg || sendBtn.disabled) return;

            expand();
            input.value = '';
            addMsg('user', msg);
            setLoading(true);

            const requestId = generateRequestId();
            startProgressPolling(requestId);

            fetch(messageUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({message: msg, request_id: requestId, session_id: sessionId})
            })
                .then(function(r) {
                    const contentType = r.headers.get('content-type') || '';
                    if (contentType.indexOf('application/json') === -1) {
                        const isAuthError = r.redirected || r.status === 401 || r.status === 403;
                        const sessionMsg = 'Your session has expired or you no longer have access.' +
                        ' Please refresh the page and sign in again.';
                        const httpMsg = 'Unexpected response from the server (HTTP ' +
                        r.status + '). Please refresh the page and try again.';
                        const friendlyErr = new Error(isAuthError ? sessionMsg : httpMsg);
                        friendlyErr.friendly = true;
                        throw friendlyErr;
                    }
                    return r.json();
                })
                .then(function(data) {
                    stopProgressPolling();
                    setLoading(false);
                    if (data.error) {
                        addMsg('error', buildErrorHtml(data.error, data.error_detail), true);
                        input.focus();
                        return;
                    }
                    const reply = renderMarkdown(data.reply || 'No response.');
                    const trace = data.tool_trace || [];
                    const traceTools = trace.map(function(t) {
                        const title = t.tool_description ? ' title="' + escapeHtml(t.tool_description) + '"' : '';
                        return '<span class="oroai-trace-tool"' + title + '>' + escapeHtml(traceToolDisplay(t)) + '</span>';
                    }).join(', ');
                    const traceHtml = trace.length
                        ? '<div class="oroai-trace">Tools: ' + traceTools + '</div>'
                        : '';
                    const usageHtml = formatUsage(data.usage);
                    if (data.cost && data.cost.total) sessionCost += data.cost.total;
                    updateTokenBar(data.token_breakdown, data.usage, data.cost);
                    addMsg('assistant', reply + traceHtml + usageHtml, true);
                    addSessionTokens(data.usage);
                    loadSessions();
                    input.focus();
                })
                .catch(function(err) {
                    stopProgressPolling();
                    setLoading(false);
                    addMsg('error', err.friendly ? err.message : 'Network error: ' + err.message);
                    input.focus();
                });
        }

        sendBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            send();
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });

        // ── Helpers ───────────────────────────────────────────────
        function addMsg(type, content, isHtml) {
            const div = document.createElement('div');
            div.className = 'oroai-hc-msg ' + type;
            if (isHtml) {
                div.innerHTML = content;
            } else {
                div.textContent = content;
            }
            msgs.appendChild(div);
            msgs.parentElement.scrollTop = msgs.parentElement.scrollHeight;
        }

        // Renders an error as a short human summary plus, when the server
        // included the provider's raw response body (e.g. Gemini's precise
        // rejection reason for a 400), a collapsed <details> accordion so the
        // technical detail is available without cluttering the chat by default.
        function buildErrorHtml(summary, detail) {
            const summaryHtml = escapeHtml(summary);
            if (!detail) {
                return summaryHtml;
            }

            return summaryHtml
                + '<details class="oroai-error-details">'
                + '<summary>Show details</summary>'
                + '<pre class="oroai-code">' + escapeHtml(detail) + '</pre>'
                + '</details>';
        }

        function setLoading(on) {
            sendBtn.disabled = on;
            input.disabled = on;
            const ld = el('progress');
            if (on && !ld) {
                const d = document.createElement('div');
                d.id = id('progress');
                d.className = 'oroai-hc-loading';
                d.textContent = nextThinkingWord();
                msgs.appendChild(d);
                msgs.parentElement.scrollTop = msgs.parentElement.scrollHeight;
                startThinkingWordRotation();
            } else if (!on && ld) {
                stopThinkingWordRotation();
                ld.remove();
            }
        }

        // ── Live checklist (polling, no streaming) ─────────────────
        // The main POST request blocks until the whole (potentially multi-turn,
        // multi-tool) run finishes. Rather than a single opaque spinner for the
        // full duration, we poll a lightweight progress endpoint every 600ms and
        // render what the agent is doing right now as a checklist.
        function generateRequestId() {
            return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        }

        function toolLabel(name, args) {
            if (name === 'skill' && args && args.name) {
                return 'Loading skill "' + args.name + '"';
            }
            return TOOL_LABELS[name] || ('Running ' + name);
        }

        // Display name for the "Tools:" trace line under a reply — for the
        // generic skill tool, show WHICH skill was loaded (args travel in the
        // trace as a JSON string).
        function traceToolDisplay(t) {
            if (t.tool === 'skill') {
                try {
                    const args = typeof t.args === 'string' ? JSON.parse(t.args) : (t.args || {});
                    if (args.name) {
                        return 'skill: ' + args.name;
                    }
                } catch (e) { /* fall through to the plain name */ }
            }
            return t.tool;
        }

        // Minimal safe markdown renderer for assistant replies: escapes ALL HTML
        // first, then re-introduces a small allow-list of tags (bold, italics,
        // code, lists, links). Raw markdown used to be shown as plain text and
        // newlines collapsed, so multi-line lists ran together into one blob.
        function renderMarkdown(raw) {
            let text = escapeHtml(String(raw));

            // Fenced code blocks first — protect contents from other rules.
            const blocks = [];
            text = text.replace(/```[a-z]*\n?([\s\S]*?)```/g, function(_, code) {
                blocks.push('<pre class="oroai-code">' + code.replace(/\n$/, '') + '</pre>');
                return '\u0000B' + (blocks.length - 1) + '\u0000';
            });

            text = text
                .replace(/`([^`\n]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                .replace(/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,;:!?])/gm, '$1<em>$2</em>')
                .replace(/^#{1,6}\s+(.+)$/gm, '<strong>$1</strong>');

            // Markdown-style [text](url) links, then bare admin/absolute URLs —
            // each inserted <a> is swapped for a placeholder token (same
            // technique as the fenced-code-block guard above) so the second
            // pass can never re-match text the first pass already linked, e.g.
            // a markdown link whose own text repeats its target URL. Without
            // this, the bare-URL pass would either bridge through the "](" of
            // an unmatched link into the next URL, or wrap the first pass's
            // own anchor text in a second, nested <a>.
            const links = [];
            const NUL = String.fromCharCode(0);
            function protectLink(html) {
                links.push(html);
                return NUL + 'L' + (links.length - 1) + NUL;
            }
            text = text
                .replace(
                    /\[([^\]\n]+)\]\(((?:https?:\/\/|\/admin\/)[^\s")]+)\)/g,
                    function(_, label, url) {
                        return protectLink('<a href="' + url + '">' + label + '</a>');
                    }
                )
                .replace(
                    /(^|[^"'=])((?:https?:\/\/|\/admin\/)[^\s"'()\[\]<>]+)/g,
                    function(_, pre, url) {
                        return pre + protectLink('<a href="' + url + '">' + url + '</a>');
                    }
                );

            // Group consecutive bullet / numbered lines into real lists;
            // everything else keeps its line breaks via <br>.
            const out = [];
            let list = null; // 'ul' | 'ol' | null
            text.split('\n').forEach(function(line) {
                const bullet = line.match(/^\s*[-*]\s+(.*)$/);
                const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
                const kind = bullet ? 'ul' : (numbered ? 'ol' : null);
                if (kind) {
                    if (list !== kind) {
                        if (list) { out.push('</' + list + '>'); }
                        out.push('<' + kind + ' class="oroai-list">');
                        list = kind;
                    }
                    out.push('<li>' + (bullet ? bullet[1] : numbered[1]) + '</li>');
                    return;
                }
                if (list) { out.push('</' + list + '>'); list = null; }
                out.push(line);
                out.push('<br>');
            });
            if (list) { out.push('</' + list + '>'); }
            let html = out.join('');
            // Drop a trailing <br> and collapse the <br> directly after block elements.
            html = html.replace(/(<\/(?:ul|ol|pre)>)<br>/g, '$1').replace(/<br>$/, '');

            return html.replace(/\u0000B(\d+)\u0000/g, function(_, i) {
                return blocks[Number(i)];
            }).replace(new RegExp(NUL + 'L(\\d+)' + NUL, 'g'), function(_, i) {
                return links[Number(i)];
            });
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = String(str);
            return div.innerHTML;
        }

        function renderProgress(steps) {
            const node = el('progress');
            if (!node) return;

            if (!steps || !steps.length) {
                if (node.className !== 'oroai-hc-loading') {
                    node.className = 'oroai-hc-loading';
                    node.textContent = nextThinkingWord();
                    startThinkingWordRotation();
                }
                return;
            }

            let headerText = '';
            const rows = [];
            let openRow = null;

            steps.forEach(function(step) {
                if (step.type === 'harness_attempt') {
                    headerText = step.max > 1 ? ('Attempt ' + step.attempt + ' of ' + step.max + '…') : '';
                } else if (step.type === 'evaluating') {
                    headerText = 'Double-checking the answer…';
                } else if (step.type === 'tool_call') {
                    openRow = {tool: step.tool, status: 'pending'};
                    rows.push(openRow);
                    headerText = '';
                } else if (step.type === 'tool_result' && openRow) {
                    openRow.status = step.success ? 'done' : 'error';
                    openRow = null;
                }
            });

            const listHtml = rows.map(function(row) {
                return '<li class="' + row.status + '"><span class="oroai-checklist-icon"></span>' +
                    escapeHtml(toolLabel(row.tool, row.args)) + '…</li>';
            }).join('');

            const headerHtml = headerText ? '<div class="oroai-hc-loading">' + escapeHtml(headerText) + '</div>' : '';

            node.className = '';
            node.innerHTML = headerHtml + (listHtml ? '<ul class="oroai-checklist">' + listHtml + '</ul>' : '');
            msgs.parentElement.scrollTop = msgs.parentElement.scrollHeight;
        }

        function startProgressPolling(requestId) {
            if (!progressUrl) return;

            progressTimer = setInterval(function() {
                fetch(progressUrl + '?request_id=' + encodeURIComponent(requestId), {
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                })
                    .then(function(r) {
                        const contentType = r.headers.get('content-type') || '';
                        return contentType.indexOf('application/json') === -1 ? null : r.json();
                    })
                    .then(function(data) {
                        if (data) renderProgress(data.steps);
                    })
                    .catch(function() {
                        // Best-effort — a failed poll tick just skips this update.
                    });
            }, 600);
        }

        function stopProgressPolling() {
            if (progressTimer !== null) {
                clearInterval(progressTimer);
                progressTimer = null;
            }
        }

        // ── Token usage display ─────────────────────────────────────
        function formatTokenCount(n) {
            return n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(n);
        }

        // Bottom toolbar: where the prompt tokens go (estimates, ~chars/4) plus
        // the provider-reported output/thinking cost of the last exchange.
        function formatCost(v) {
            if (v >= 0.1) return '$' + v.toFixed(2);
            if (v >= 0.001) return '$' + v.toFixed(3);
            return '$' + v.toFixed(4);
        }

        // Built with DOM nodes (not an HTML string) so tooltips may contain
        // any character — quotes in a title attribute previously broke the markup.
        function tokenChip(label, value, tooltip) {
            const chip = document.createElement('span');
            chip.className = 'oroai-tokenbar-chip' + (label === 'cost' ? ' oroai-tokenbar-cost' : '');
            chip.title = tooltip;
            const lbl = document.createElement('span');
            lbl.className = 'oroai-tokenbar-lbl';
            lbl.textContent = label;
            const val = document.createElement('b');
            val.className = 'oroai-tokenbar-val';
            val.textContent = value;
            chip.appendChild(lbl);
            chip.appendChild(val);
            return chip;
        }

        function updateTokenBar(breakdown, usage, cost) {
            if (!tokenBar) return;

            // Each segment: [label, value, tooltip]. Left side = estimated
            // (~chars/4) size of every prompt ingredient of ONE request; right
            // side = the provider's actual, billed numbers for the whole turn.
            const segments = [];
            if (breakdown) {
                const meta = {
                    system_prompt: ['prompt',
                        'Base system prompt — the built-in agent instructions sent with every request (estimated).'],
                    guidelines: ['guidance',
                        'Guidelines merged from all bundles + admin "Additional Guidelines" — always in the prompt (estimated).'],
                    custom_instructions: ['instructions',
                        'Admin "Custom Instructions" prepended to the prompt (estimated).'],
                    skills_catalog: ['skills',
                        'Skill catalog — one trigger line per enabled skill; full skill bodies only load when used (estimated).'],
                    tools: ['tools',
                        'All other tool definitions: names, descriptions, parameter schemas (estimated).'],
                    history: ['history',
                        'Earlier messages of this conversation — re-sent in full with every request (estimated).'],
                    user_message: ['message', 'Your current message (estimated).']
                };
                Object.keys(meta).forEach(function(key) {
                    const v = breakdown[key] || 0;
                    if (v > 0) segments.push([meta[key][0], '~' + formatTokenCount(v), meta[key][1]]);
                });
            }

            const outSegments = [];
            if (usage && usage.completion_tokens) {
                outSegments.push(['output', formatTokenCount(usage.completion_tokens),
                    'Tokens the model generated for the visible reply (provider-reported).']);
            }
            if (usage && usage.thinking_tokens) {
                outSegments.push(['thinking', formatTokenCount(usage.thinking_tokens),
                    'Hidden reasoning tokens the model spent before answering (Gemini "thoughts") — '
                    + 'billed like output but never shown in the reply (provider-reported).']);
            }
            if (usage && usage.prompt_tokens) {
                outSegments.push(['in', formatTokenCount(usage.prompt_tokens),
                    'Total input tokens the provider actually processed this turn, summed over ALL '
                    + 'iterations — every tool call re-sends the whole conversation plus prior tool '
                    + 'results, so this is usually several times the single-request estimate on the left '
                    + '(provider-reported).']);
            }
            if (cost && cost.total) {
                const perTurn = formatCost(cost.total);
                const session = sessionCost > cost.total ? ' (session ' + formatCost(sessionCost) + ')' : '';
                outSegments.push(['cost', '\u2248' + perTurn + session,
                    'APPROXIMATE cost of this turn at ' + (cost.model || 'model') + ' list prices ('
                    + formatCost(cost.input) + ' input + ' + formatCost(cost.output)
                    + ' output incl. thinking, USD per public per-1M-token rates — actual billing may '
                    + 'differ). The session figure sums every turn since this chat was opened.']);
            }

            tokenBar.textContent = '';
            segments.forEach(function(s) { tokenBar.appendChild(tokenChip(s[0], s[1], s[2])); });
            if (segments.length && outSegments.length) {
                const divider = document.createElement('span');
                divider.className = 'oroai-tokenbar-div';
                tokenBar.appendChild(divider);
            }
            outSegments.forEach(function(s) { tokenBar.appendChild(tokenChip(s[0], s[1], s[2])); });
            tokenBar.style.display = (segments.length || outSegments.length) ? '' : 'none';
        }

        // ── Recent sessions (restore a conversation, like `claude --resume`) ──
        // The server keeps the last 5 conversations per admin user in cache;
        // the list lives at the very bottom of the widget and clicking an
        // entry reloads that conversation and continues under the same id.
        function formatSessionTime(epochSeconds) {
            const diff = Math.max(0, Math.floor(Date.now() / 1000) - epochSeconds);
            if (diff < 60) return 'now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h';
            return Math.floor(diff / 86400) + 'd';
        }

        function loadSessions() {
            if (!sessionsBox || !sessionsUrl) return;
            fetch(sessionsUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function(r) { return r.ok ? r.json() : {sessions: []}; })
                .then(function(data) { renderSessions(data.sessions || []); })
                .catch(function() { /* the list is a nicety — never break the chat */ });
        }

        function renderSessions(sessions) {
            if (!sessionsBox) return;
            sessionsBox.textContent = '';
            if (!sessions.length) {
                sessionsBox.style.display = 'none';
                return;
            }
            const title = document.createElement('div');
            title.className = 'oroai-sessions-title';
            title.textContent = 'Recent chats';
            sessionsBox.appendChild(title);
            sessions.forEach(function(s) {
                const row = document.createElement('div');
                row.className = 'oroai-session-row' + (s.id === sessionId ? ' active' : '');
                row.title = 'Restore this conversation (' + s.count + ' messages)';
                const name = document.createElement('span');
                name.className = 'oroai-session-name';
                name.textContent = s.title;
                const time = document.createElement('span');
                time.className = 'oroai-session-time';
                time.textContent = formatSessionTime(s.updated_at);
                row.appendChild(name);
                row.appendChild(time);
                row.addEventListener('click', function(e) {
                    e.stopPropagation();
                    restoreSession(s.id);
                });
                sessionsBox.appendChild(row);
            });
            sessionsBox.style.display = '';
        }

        function restoreSession(id) {
            if (!sessionUrl || id === sessionId) return;
            fetch(sessionUrl + '?id=' + encodeURIComponent(id), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'}
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    const messages = data.messages || [];
                    if (!messages.length) return;
                    sessionId = id;
                    msgs.innerHTML = '';
                    messages.forEach(function(m) {
                        if (m.role === 'assistant') {
                            addMsg('assistant', renderMarkdown(m.content), true);
                        } else {
                            addMsg('user', m.content);
                        }
                    });
                    sessionTokens = 0;
                    sessionCost = 0;
                    updateTokensLabel();
                    loadSessions();
                    expand();
                })
                .catch(function() { /* ignore — restoring is best-effort */ });
        }

        function formatUsage(usage) {
            const total = usage && usage.total_tokens;
            if (!total) return '';
            return '<div class="oroai-usage">' + formatTokenCount(total) + ' tokens</div>';
        }

        function updateTokensLabel() {
            if (!tokensLabel) return;
            // e.g. "· 5.5k tokens - m1x3k9a7f2b4c8" — the trailing id is the debug
            // session id = transcript file name in var/cache/{env}/chats/.
            const tokens = sessionTokens > 0 ? '· ' + formatTokenCount(sessionTokens) + ' tokens - ' : '· ';
            tokensLabel.textContent = tokens + sessionId;
            tokensLabel.title = 'Debug session id — full request/response transcript at var/cache/{env}/chats/'
                + sessionId + '.txt';
        }

        function addSessionTokens(usage) {
            if (usage && usage.total_tokens) {
                sessionTokens += usage.total_tokens;
                updateTokensLabel();
            }
        }
    }

    window.initOroAiChat = initOroAiChat;
}());
