'use strict';

(function () {
    var root          = document.getElementById('oroai-hc');
    if (!root) return;

    var messageUrl    = root.dataset.messageUrl;
    var csrfToken     = root.dataset.csrfToken;
    var panel         = document.getElementById('oroai-panel');
    var body          = document.getElementById('oroai-hc-body');
    var msgs          = document.getElementById('oroai-hc-msgs');
    var minimizeBtn   = document.getElementById('oroai-hc-minimize');
    var clearBtn      = document.getElementById('oroai-hc-clear');
    var input         = document.getElementById('oroai-hc-input');
    var sendBtn       = document.getElementById('oroai-hc-send');
    var anchor        = document.getElementById('oroai-hc-anchor');
    var panelInputBar = document.getElementById('oroai-panel-input-bar');

    var history    = [];
    var isOpen     = false;
    var inputMoved = false;

    // ── Mount panel below the header container ────────────────
    function mountPanel() {
        var container = document.querySelector('.app-header__container-panel');
        if (container && panel) {
            container.parentNode.insertBefore(panel, container.nextSibling);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountPanel);
    } else {
        mountPanel();
    }

    // ── Relocate input into / out of panel ───────────────────
    function moveInputToPanel() {
        if (inputMoved) return;
        panelInputBar.appendChild(root);
        inputMoved = true;
    }

    function moveInputToHeader() {
        if (!inputMoved) return;
        anchor.parentNode.insertBefore(root, anchor);
        inputMoved = false;
    }

    // ── Panel open / close ────────────────────────────────────
    function openPanel() {
        if (isOpen) return;
        isOpen = true;
        panel.classList.add('oroai-panel--open');
        panel.setAttribute('aria-hidden', 'false');
    }

    function closePanel() {
        if (!isOpen) return;
        isOpen = false;
        panel.classList.remove('oroai-panel--open');
        panel.setAttribute('aria-hidden', 'true');
        moveInputToHeader();
    }

    // Re-open panel when user focuses the header input and there is history
    input.addEventListener('focus', function () {
        if (msgs.children.length > 0) {
            openPanel();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) {
            closePanel();
            input.blur();
        }
    });

    document.addEventListener('click', function (e) {
        if (isOpen && !panel.contains(e.target) && !root.contains(e.target)) {
            closePanel();
        }
    });

    // ── Messages area expand / collapse ──────────────────────
    function expand() {
        body.style.display = 'block';
        openPanel();
        moveInputToPanel();
        setTimeout(function () { input.focus(); }, 50);
    }

    minimizeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        body.style.display = 'none';
        closePanel();
    });

    clearBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        history = [];
        msgs.innerHTML = '';
        body.style.display = 'none';
        closePanel();
    });

    // ── Send ──────────────────────────────────────────────────
    function send() {
        var msg = input.value.trim();
        if (!msg || sendBtn.disabled) return;

        expand();
        input.value = '';
        addMsg('user', msg);
        history.push({role: 'user', content: msg});
        setLoading(true);

        fetch(messageUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify({message: msg, history: history.slice(-20)})
        })
        .then(function (r) {
            var contentType = r.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') === -1) {
                var friendlyErr = r.redirected || r.status === 401 || r.status === 403
                    ? new Error('Your session has expired or you no longer have access. Please refresh the page and sign in again.')
                    : new Error('Unexpected response from the server (HTTP ' + r.status + '). Please refresh the page and try again.');
                friendlyErr.friendly = true;
                throw friendlyErr;
            }
            return r.json();
        })
        .then(function (data) {
            setLoading(false);
            if (data.error) {
                addMsg('error', data.error);
                input.focus();
                return;
            }
            var reply = (data.reply || 'No response.')
                .replace(/(\/admin\/[^\s<>"']+)/g, '<a href="$1">$1</a>');
            var trace = data.tool_trace || [];
            var traceHtml = trace.length
                ? '<div class="oroai-trace">Tools: ' + trace.map(function (t) { return t.tool; }).join(', ') + '</div>'
                : '';
            addMsg('assistant', reply + traceHtml, true);
            history.push({role: 'assistant', content: data.reply || ''});
            input.focus();
        })
        .catch(function (err) {
            setLoading(false);
            addMsg('error', err.friendly ? err.message : 'Network error: ' + err.message);
            input.focus();
        });
    }

    sendBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        send();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });

    // ── Helpers ───────────────────────────────────────────────
    function addMsg(type, content, isHtml) {
        var div = document.createElement('div');
        div.className = 'oroai-hc-msg ' + type;
        if (isHtml) div.innerHTML = content;
        else div.textContent = content;
        msgs.appendChild(div);
        msgs.parentElement.scrollTop = msgs.parentElement.scrollHeight;
    }

    function setLoading(on) {
        sendBtn.disabled = on;
        input.disabled   = on;
        var ld = msgs.querySelector('.oroai-hc-loading');
        if (on && !ld) {
            var d = document.createElement('div');
            d.className  = 'oroai-hc-loading';
            d.textContent = 'Thinking…';
            msgs.appendChild(d);
            msgs.parentElement.scrollTop = msgs.parentElement.scrollHeight;
        } else if (!on && ld) {
            ld.remove();
        }
    }
}());
