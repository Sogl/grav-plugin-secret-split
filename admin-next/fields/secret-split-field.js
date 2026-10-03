const TAG = window.__GRAV_FIELD_TAG;

/**
 * Secret Split field select — the admin-next counterpart of the admin1
 * `secret-split-field-select` JS. Blueprint options carry every plugin's
 * `plugins.<slug>.*` keys; this component finds the sibling `.plugin` select
 * in the same protected_fields list row and narrows options to that plugin,
 * re-filtering whenever the plugin choice changes.
 *
 * Plugin-select discovery walks ancestors until a <select> whose options are
 * NOT `plugins.*` keys turns up — in this blueprint the only such select is
 * the row's `.plugin` field. That is the same DOM trick the admin1 script
 * used, expressed inside the field itself.
 *
 * Under the select the component renders the admin1-style status block:
 * caption + colored status pill + source line, driven by the field facts
 * from GET /secret-split/state (one fetch shared by all instances).
 */

// Field facts are fetched once per MOUNT — the promise is shared only while
// in-flight so a burst of list rows issues one request, but a component that
// remounts after save/navigation always sees fresh storage state (admin1 got
// this for free via full-page reloads; an SPA session needs explicit refetch).
let _statePromise = null;
let _stateEnv = null;

// Admin Next 2.1.7+ exposes the operator's selected environment as the
// documented extension contract window.__GRAV_ENVIRONMENT ('default' = base
// scope); the SPA replays it as X-Grav-Environment + X-Config-Environment on
// every api call — the field's status facts must be computed under the same
// scope.
function _gravEnvironment() {
    const env = window.__GRAV_ENVIRONMENT;
    return typeof env === 'string' && env !== '' ? env : 'default';
}

function _loadState() {
    // The in-flight fetch is also invalidated when the selected environment
    // changed since it started (switcher reloads the page, but a cached
    // promise from before the reload must not leak across scopes).
    const env = _gravEnvironment();
    if (_statePromise && _stateEnv === env) {
        return _statePromise;
    }

    const url = (window.__GRAV_API_SERVER_URL || '') +
        (window.__GRAV_API_PREFIX || '/api/v1') + '/secret-split/state';
    const headers = {};
    const token = window.__GRAV_API_TOKEN;
    if (token) headers['X-API-Token'] = token;
    headers['X-Grav-Environment'] = env;
    headers['X-Config-Environment'] = env;

    _stateEnv = env;

    _statePromise = fetch(url, { headers })
        .then(r => (r.ok ? r.json() : null))
        .then(j => (j && (j.data || j)) || null)
        .catch(() => null)
        .finally(() => { _statePromise = null; });

    return _statePromise;
}

// Status hues from the 1.7 badge palette (assets/admin/secret-split-admin.css),
// chip anatomy from admin-next's own pills (see php-82-shim page .chip/.badge):
// thin outlined border, no heavy fill, small filled dot. Inline styles so the
// chip does not depend on classes being present in admin-next's compiled CSS.
const STATUS_STYLES = {
    stored:    { border: '#97d4af', bg: '#dff4e7', text: '#197a45' },
    pending:   { border: '#f1a4a0', bg: '#ffe2e1', text: '#ba2d21' },
    duplicate: { border: '#e9cf7a', bg: '#fff3cf', text: '#8b6403' },
    missing:   { border: '#d4dae1', bg: '#edf0f4', text: '#616b75' },
};

class SecretSplitField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = '';
        this._sel = null;
        this._statusEl = null;
        this._stateData = null;
        this._plugSel = null;
        this._hookTimer = null;
        this._hookAttempts = 0;
        this._onPlugChange = () => this._render();
    }

    set field(v) {
        // A rebuilt field object means the form re-rendered (e.g. after save)
        // — drop cached facts so the badge refetches fresh state.
        if (this._field !== null && v !== this._field) {
            this._stateData = null;
        }
        this._field = v;
        this._render();
    }
    get field() { return this._field; }

    set value(v) {
        v = v == null ? '' : String(v);
        if (v !== this._value) {
            this._value = v;
            this._render();
        }
    }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
        // The sibling .plugin select may render after us — retry briefly.
        this._hookAttempts = 0;
        this._hookTimer = setInterval(() => {
            if (this._hookPluginSelect() || ++this._hookAttempts > 50) {
                clearInterval(this._hookTimer);
                this._hookTimer = null;
            }
        }, 100);
    }

    disconnectedCallback() {
        if (this._hookTimer) clearInterval(this._hookTimer);
        this._unhook();
    }

    _unhook() {
        if (this._plugSel) {
            this._plugSel.removeEventListener('change', this._onPlugChange);
            this._plugSel = null;
        }
    }

    _hookPluginSelect() {
        let node = this.parentElement;
        while (node && node !== document.body) {
            const found = [...node.querySelectorAll('select')].find(s =>
                s !== this._sel &&
                s.options.length > 1 &&
                [...s.options].some(o => o.value !== '') &&
                [...s.options].every(o => !String(o.value).startsWith('plugins.')));
            if (found) {
                if (found !== this._plugSel) {
                    this._unhook();
                    this._plugSel = found;
                    found.addEventListener('change', this._onPlugChange);
                    this._render();
                }
                return true;
            }
            node = node.parentElement;
        }
        return false;
    }

    _optionsAll() {
        const opts = this._field?.options;
        if (Array.isArray(opts)) {
            return opts.map(o => typeof o === 'object' && o !== null
                ? { value: String(o.value ?? ''), label: String(o.label ?? o.value ?? '') }
                : { value: String(o), label: String(o) });
        }
        if (opts && typeof opts === 'object') {
            return Object.entries(opts).map(([v, l]) => ({ value: String(v), label: String(l) }));
        }
        return [];
    }

    _render() {
        if (!this._sel) {
            this.innerHTML = '';
            this._sel = document.createElement('select');
            this._sel.className = 'flex h-10 w-full appearance-none rounded-lg border border-input bg-muted/50 ps-3 pe-8 py-2 text-sm';
            this._sel.addEventListener('change', () => {
                this._value = this._sel.value;
                this.dispatchEvent(new CustomEvent('change', { detail: this._sel.value, bubbles: true }));
                this._paintStatus();
            });
            this.appendChild(this._sel);

            this._statusEl = document.createElement('div');
            this._statusEl.style.display = 'none';
            this.appendChild(this._statusEl);
        }

        const slug = this._plugSel?.value || '';
        const all = this._optionsAll();
        const opts = slug ? all.filter(o => o.value.startsWith('plugins.' + slug + '.')) : all;
        const cur = this._value || '';
        const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');

        this._sel.innerHTML = '<option value=""></option>' + opts.map(o =>
            `<option value="${esc(o.value)}"${o.value === cur ? ' selected' : ''}>${esc(o.label)}</option>`).join('');

        // Keep a stored value selectable even if it no longer matches the
        // chosen plugin — the form must never silently drop saved data.
        if (cur && !opts.some(o => o.value === cur)) {
            const opt = document.createElement('option');
            opt.value = cur;
            opt.textContent = cur;
            opt.selected = true;
            this._sel.appendChild(opt);
        }

        this._paintStatus();
    }

    _paintStatus() {
        if (!this._statusEl) {
            return;
        }

        if (!this._value) {
            this._statusEl.style.display = 'none';
            this._statusEl.innerHTML = '';
            return;
        }

        if (this._stateData !== null) {
            this._paintStatusFromState(this._stateData);
            return;
        }

        // Skeleton: the chip+source rows are reserved while facts load so the
        // real badge fills the same space instead of popping in below the
        // select — admin1 rendered the row server-side and never shifted.
        const dim = STATUS_STYLES.missing;
        this._statusEl.innerHTML =
            `<span style="display:inline-flex;align-items:center;gap:0.4rem;margin-top:0.35rem;` +
            `padding:0.22rem 0.7rem;border-radius:999px;border:1px solid ${dim.border};` +
            `background:${dim.bg};color:${dim.text};line-height:1.35;font-size:0.78rem;` +
            `font-weight:600;white-space:nowrap;opacity:.55">` +
            `<span style="width:0.45rem;height:0.45rem;border-radius:999px;` +
            `background:currentColor;flex:none"></span>···</span>` +
            `<div class="mt-1 text-xs" style="color:transparent;user-select:none">·</div>`;
        this._statusEl.style.display = '';

        _loadState().then(state => {
            this._stateData = state;
            this._paintStatusFromState(state);
        });
    }

    _paintStatusFromState(state) {
        if (!this._statusEl || !this._value) {
            return;
        }

        if (state === null) {
            this._statusEl.style.display = 'none';
            return;
        }

        const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
        const facts = state?.states?.facts || {};
        const fact = facts[this._value] || null;
        const labels = state?.states?.meta?.source_labels || {};

        const status = fact?.status || 'missing';
        const label = fact?.label || labels.not_set || '';
        const source = fact?.source || '';
        const style = STATUS_STYLES[status] || STATUS_STYLES.missing;

        this._statusEl.innerHTML =
            `<span style="display:inline-flex;align-items:center;gap:0.4rem;margin-top:0.35rem;` +
            `padding:0.22rem 0.7rem;border-radius:999px;border:1px solid ${style.border};` +
            `background:${style.bg};color:${style.text};line-height:1.35;font-size:0.78rem;` +
            `font-weight:600;white-space:nowrap">` +
            `<span style="width:0.45rem;height:0.45rem;border-radius:999px;` +
            `background:currentColor;flex:none"></span>${esc(label)}</span>` +
            (source ? `<div class="mt-1 text-xs text-muted-foreground">${esc(source)}</div>` : '');
        this._statusEl.style.display = '';
    }
}

customElements.define(TAG, SecretSplitField);
