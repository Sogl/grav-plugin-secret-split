const TAG = window.__GRAV_FIELD_TAG;

/**
 * Secret Split overview field — mounted at the blueprint `overview` display
 * slot inside the plugin's own settings form. Renders the admin1-style
 * overview: four status tiles, the migrate/return actions with notes and the
 * storage-file caption — the same block secret-split-admin.js injected into
 * #secret-split-overview on Grav 1.7.
 *
 * Field selection itself stays native (the protected_fields list below) —
 * this component only reports state and exposes the two storage actions.
 */

let _statePromise = null;
let _stateEnv = null;

// Admin Next 2.1.7+ exposes the operator's selected environment as the
// documented extension contract window.__GRAV_ENVIRONMENT ('default' = base
// scope); the SPA replays it as X-Grav-Environment + X-Config-Environment on
// every api call — the overview must be computed under the same scope.
function _gravEnvironment() {
    const env = window.__GRAV_ENVIRONMENT;
    return typeof env === 'string' && env !== '' ? env : 'default';
}

function _headers(json = false) {
    const h = {};
    const token = window.__GRAV_API_TOKEN;
    if (token) h['X-API-Token'] = token;
    const env = _gravEnvironment();
    h['X-Grav-Environment'] = env;
    h['X-Config-Environment'] = env;
    if (json) h['Content-Type'] = 'application/json';
    return h;
}

function _apiUrl(path) {
    return (window.__GRAV_API_SERVER_URL || '') +
           (window.__GRAV_API_PREFIX || '/api/v1') + path;
}

function _loadState() {
    const env = _gravEnvironment();
    if (_statePromise && _stateEnv === env) {
        return _statePromise;
    }
    _stateEnv = env;
    _statePromise = fetch(_apiUrl('/secret-split/state'), { headers: _headers() })
        .then(r => (r.ok ? r.json() : null))
        .then(j => (j && (j.data || j)) || null)
        .catch(() => null)
        .finally(() => { _statePromise = null; });
    return _statePromise;
}

class SecretSplitOverview extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._state = null;
        this._loading = true;
    }

    connectedCallback() {
        this._render();
        _loadState().then(state => {
            this._state = state;
            this._loading = false;
            this._render();
        });
    }

    _t(key, fallback) {
        const s = this._state?.strings || {};
        return s[key] || fallback || key;
    }

    _esc(v) {
        return String(v ?? '').replace(/[&<>"']/g,
            c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    async _action(kind) {
        const confirmText = kind === 'return'
            ? this._t('return_confirm', 'Write secrets back into config YAML files?')
            : this._t('migrate_confirm', 'Move pending values into the secrets file?');
        const confirmFn = window.__GRAV_DIALOGS?.confirm;
        const ok = typeof confirmFn === 'function'
            ? await confirmFn({ title: 'Secret Split', message: confirmText })
            : window.confirm(confirmText);
        if (ok !== true) return;

        this._busy(true);
        try {
            const resp = await fetch(_apiUrl('/secret-split/' + kind), {
                method: 'POST', headers: _headers(true)
            });
            const json = await resp.json().catch(() => ({}));
            if (!resp.ok) throw new Error(json?.error?.detail || json?.detail || resp.statusText);
            const res = json.data || json;
            if (res.state) this._state = res.state;
            if (res.message) window.__GRAV_TOAST?.success(res.message);
            this._busy(false);
            this._render();
            // Tracked configs changed on disk — the settings form and the
            // per-field status chips reflect the old snapshot, so reload the
            // whole page like admin1 always did.
            window.location.reload();
        } catch (err) {
            window.__GRAV_TOAST?.error(err.message || 'Action failed');
            this._busy(false);
            this._render();
        }
    }

    _busy(on) {
        this.shadowRoot.querySelectorAll('button').forEach(b => { b.disabled = on; });
    }

    _render() {
        const s = this._state || {};
        const t = (k, f) => this._t(k, f);
        const esc = (v) => this._esc(v);

        if (this._loading) {
            this.shadowRoot.innerHTML = `${this._style()}
<div class="ovgrid">${['stored','pending','duplicate','missing'].map(k =>
    `<div class="ovcard st-${k}"><strong>${esc(t('overview_' + k, k))}</strong><span class="skel">···</span></div>`
).join('')}</div>`;
            return;
        }
        if (!s || s.error) {
            this.shadowRoot.innerHTML = `${this._style()}<p class="err">${esc(s?.error || t('load_failed', 'Secret Split state unavailable'))}</p>`;
            return;
        }

        const counts = s.states?.counts || {};
        const meta = s.states?.meta || {};
        const primaryFile = meta.env_storage_available
            ? (meta.env_storage_file || '')
            : (meta.base_storage_file || '');
        const tf = (key, fallback) => String(t(key, fallback)).split('%file%').join(primaryFile);
        const pendingTotal = (counts.pending || 0) + (counts.duplicate || 0);
        const returnTotal = (counts.stored || 0) + (counts.duplicate || 0);

        this.shadowRoot.innerHTML = `${this._style()}
<div class="ovgrid">${['stored','pending','duplicate','missing'].map(k =>
    `<div class="ovcard st-${k}"><strong>${esc(t('overview_' + k, k))}</strong><span>${counts[k] || 0}</span></div>`
).join('')}</div>
<div class="ovactions">
    <div class="ovact">
        <button type="button" class="btn primary" data-action="migrate" ${pendingTotal > 0 ? '' : 'disabled'}>${esc(tf('migrate_to_file', 'Move to %file%'))}</button>
        <span class="ovnote">${esc(tf('migrate_note_to_file', 'Pending and duplicate values will be moved into %file% and removed from config YAML.'))}</span>
    </div>
    <div class="ovact">
        <button type="button" class="btn" data-action="return" ${returnTotal > 0 ? '' : 'disabled'}>${esc(t('return_to_config', 'Move to config'))}</button>
        <span class="ovnote">${esc(tf('return_note_from_file', 'Current values from %file% will be written back into config YAML and removed from %file%.'))}</span>
    </div>
</div>
<p class="meta"><span class="muted">${esc(meta.base_storage_file || '')}${meta.env_storage_file ? ' · ' + esc(meta.env_storage_file) : ''}</span></p>`;

        this.shadowRoot.querySelectorAll('button[data-action]').forEach(btn => {
            btn.addEventListener('click', () => this._action(btn.dataset.action));
        });
    }

    _style() {
        return `<style>
            :host { display: block; margin: .35rem 0 .9rem; }
            .muted { color: var(--muted-foreground, #777); }
            .meta { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin: .35rem 0 0; font-size: .8rem; }
            .skel { opacity: .35; }
            /* 1.7 overview tiles + action rows (assets/admin/secret-split-admin.css) */
            .ovgrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin: .35rem 0 .75rem; }
            @media (max-width: 900px) { .ovgrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
            .ovcard { padding: .8rem .9rem; border: 1px solid; border-radius: 8px; }
            .ovcard strong, .ovcard span { display: block; }
            .ovcard span { margin-top: .2rem; font-size: 1.55rem; font-weight: 700; line-height: 1.1; }
            .ovcard.st-stored { background: #dff4e7; color: #197a45; border-color: #97d4af; }
            .ovcard.st-pending { background: #ffe2e1; color: #ba2d21; border-color: #f1a4a0; }
            .ovcard.st-duplicate { background: #fff3cf; color: #8b6403; border-color: #e9cf7a; }
            .ovcard.st-missing { background: #edf0f4; color: #616b75; border-color: #d4dae1; }
            .ovactions { display: flex; flex-direction: column; gap: .75rem; margin: .4rem 0 .2rem; }
            .ovact { display: flex; align-items: center; gap: .9rem; flex-wrap: wrap; }
            .ovnote { color: var(--muted-foreground, #777); font-size: .85rem; flex: 1; min-width: 14rem; }
            .btn { padding: .45rem 1rem; border-radius: 6px; border: 1px solid var(--border, #d4d4d4);
                   background: var(--card, #fff); color: var(--foreground, #222); font-size: .85rem; cursor: pointer; }
            .btn.primary { background: var(--primary, #2563eb); border-color: transparent; color: #fff; }
            .btn:disabled { opacity: .45; cursor: default; }
            .btn:not(:disabled):hover { filter: brightness(.96); }
            .err { color: #b91c1c; }
        </style>`;
    }
}

customElements.define(TAG, SecretSplitOverview);
