(function () {
  const SELECTORS = {
    form: 'form#blueprints, .admin-content form, form',
    observerRoot: 'form#blueprints, .admin-content form',
    pluginSelect: 'select[name$="[plugin]"]',
    fieldSelect: 'select[name$="[field_key]"]',
    listItem: 'li[data-collection-item], li[data-grav-field="list-item"]',
    statusRow: ':scope > .secret-split-field-status-row',
  };

  const runtime = {
    observer: null,
    observerRoot: null,
    scanScheduled: false,
  };

  const DataStore = {
    getCatalog() {
      return window.SecretSplitFieldCatalog || { plugins: {}, fields: {}, fieldPlugins: {}, passwordFields: [] };
    },

    getStates() {
      return window.SecretSplitFieldStates || {
        fields: {},
        facts: {},
        counts: { stored: 0, pending: 0, duplicate: 0, missing: 0 },
        actions: {},
      };
    },

    getNormalizedStates() {
      const raw = this.getStates();
      const facts = raw.facts && typeof raw.facts === 'object' ? raw.facts : {};
      const fields = raw.fields && typeof raw.fields === 'object' ? raw.fields : {};

      return {
        ...raw,
        fields,
        facts,
        allFacts: { ...fields, ...facts },
        counts: raw.counts && typeof raw.counts === 'object'
          ? raw.counts
          : { stored: 0, pending: 0, duplicate: 0, missing: 0 },
        actions: raw.actions && typeof raw.actions === 'object' ? raw.actions : {},
        meta: raw.meta && typeof raw.meta === 'object' ? raw.meta : {},
      };
    },

    t(key, fallback) {
      const messages = window.SecretSplitI18n || {};
      return messages[key] || fallback;
    },

    tf(key, fallback, replacements) {
      let text = this.t(key, fallback);
      Object.entries(replacements || {}).forEach(([token, value]) => {
        text = text.replaceAll(token, String(value));
      });
      return text;
    },

    getFieldFact(fullKey) {
      if (!fullKey) {
        return null;
      }

      return this.getNormalizedStates().allFacts[fullKey] || null;
    },

    getPrimarySecretsFileName() {
      const meta = this.getNormalizedStates().meta;
      if (meta.env_storage_available && meta.env_storage_file) {
        return meta.env_storage_file;
      }

      return meta.base_storage_file || 'secrets.yaml';
    },

    getSourceLabels() {
      const labels = this.getNormalizedStates().meta.source_labels;
      return labels && typeof labels === 'object'
        ? labels
        : {
          base_secrets: this.t('source_base_secrets', 'Base secrets'),
          env_secrets: this.t('source_env_secrets', 'Environment secrets'),
          base_config: this.t('source_base_config', 'Base config'),
          env_config: this.t('source_env_config', 'Environment config'),
          not_set: this.t('source_not_set', 'Not set'),
        };
    },
  };

  const Dom = {
    getCurrentForm() {
      return document.querySelector(SELECTORS.form);
    },

    getObserverRoot() {
      return document.querySelector(SELECTORS.observerRoot);
    },

    findAncestorListItem(element) {
      return element ? element.closest(SELECTORS.listItem) : null;
    },

    findFieldContainer(element) {
      if (!element) {
        return null;
      }

      return element.closest('.form-field') || element.parentElement;
    },

    findFieldItem(fieldSelect) {
      return fieldSelect ? fieldSelect.closest('li[data-collection-item]') : null;
    },

    findFieldListWrapper(fieldSelect) {
      return fieldSelect ? fieldSelect.closest('.form-list-wrapper') : null;
    },

    findPluginSelectForFieldRow(fieldSelect) {
      let current = this.findAncestorListItem(fieldSelect);
      while (current) {
        const pluginSelect = current.querySelector(SELECTORS.pluginSelect);
        if (pluginSelect) {
          return pluginSelect;
        }

        current = current.parentElement ? current.parentElement.closest(SELECTORS.listItem) : null;
      }

      return null;
    },

    ensurePendingActionInput() {
      const form = this.getCurrentForm();
      if (!form) {
        return null;
      }

      let input = form.querySelector('input[name="_secret_split_pending_action"]');
      if (input) {
        return input;
      }

      input = document.createElement('input');
      input.type = 'hidden';
      input.name = '_secret_split_pending_action';
      input.value = '';
      form.appendChild(input);
      return input;
    },

    ensureStatusNode(fieldSelect) {
      const fieldRow = this.findFieldContainer(fieldSelect);
      if (!fieldRow || !fieldRow.parentElement) {
        return null;
      }

      let row = fieldRow.parentElement.querySelector(SELECTORS.statusRow);
      if (!row) {
        row = document.createElement('div');
        row.className = 'form-field grid secret-split-field-status-row';

        const label = document.createElement('div');
        label.className = 'form-label block size-1-3 secret-split-field-status-label';
        label.textContent = DataStore.t('status_title', 'Status');

        const content = document.createElement('div');
        content.className = 'form-data block size-2-3 secret-split-field-status';

        row.append(label, content);
        fieldRow.insertAdjacentElement('afterend', row);
      }

      return row.querySelector('.secret-split-field-status');
    },
  };

  const AdminState = {
    rebaselineAdminFormState() {
      const FormState = globalThis.Grav?.default?.Forms?.FormState?.FormState;
      if (typeof FormState !== 'function') {
        return;
      }

      new FormState({
        ignore: [],
        form_id: 'blueprints',
      });
    },

    isAdminFormDirty() {
      const instance = globalThis.Grav?.default?.Forms?.FormState?.Instance;
      if (!instance || typeof instance.equals !== 'function') {
        return false;
      }

      return !instance.equals();
    },

    clearChangesHash() {
      if (window.location.hash !== '#changes') {
        return;
      }

      const cleanUrl = `${window.location.pathname}${window.location.search}`;
      window.history.replaceState(null, document.title, cleanUrl);
    },

    closeChangesModal() {
      const jq = globalThis.jQuery || globalThis.$;
      const modal = jq?.('[data-remodal-id="changes"]');
      if (modal?.length) {
        const lookup = jq?.remodal?.lookup;
        const index = modal.data('remodal');
        const instance = Array.isArray(lookup) && index !== undefined ? lookup[index] : null;
        if (instance && typeof instance.close === 'function') {
          instance.close();
        }
      }

      this.clearChangesHash();
    },

    closeChangesModalIfClean() {
      if (this.isAdminFormDirty()) {
        return;
      }

      this.closeChangesModal();
    },

    getPendingAction() {
      return Dom.ensurePendingActionInput()?.value || '';
    },

    setPendingAction(action) {
      const input = Dom.ensurePendingActionInput();
      if (!input) {
        return;
      }

      input.value = input.value === action ? '' : action;
    },

    clearPendingAction() {
      const input = Dom.ensurePendingActionInput();
      if (input) {
        input.value = '';
      }
    },
  };

  const SelectizeAdapter = {
    getControl(selectElement) {
      if (!selectElement) {
        return null;
      }

      const selectize = selectElement.selectize;
      if (selectize?.$control?.[0]) {
        return selectize.$control[0];
      }

      const sibling = selectElement.nextElementSibling;
      return sibling?.classList?.contains('selectize-control') ? sibling : null;
    },

    setWrapperOptions(wrapper, optionMap) {
      if (!wrapper) {
        return;
      }

      const json = JSON.stringify(optionMap);
      wrapper.setAttribute('data-select-unique', json);
      if (window.jQuery) {
        window.jQuery(wrapper).data('selectUnique', optionMap);
      }
    },

    getWrapperOptions(wrapper) {
      if (!wrapper) {
        return {};
      }

      if (window.jQuery) {
        const options = window.jQuery(wrapper).data('selectUnique');
        if (options && typeof options === 'object') {
          return Object.assign({}, options);
        }
      }

      const raw = wrapper.getAttribute('data-select-unique');
      if (!raw) {
        return {};
      }

      try {
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (error) {
        return {};
      }
    },

    rebuildFieldSelect(fieldSelect, optionMap, selectedValue) {
      fieldSelect.replaceChildren();

      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = '';
      fieldSelect.appendChild(empty);

      const entries = Object.entries(optionMap);
      for (const [fullKey, label] of entries) {
        const option = document.createElement('option');
        option.value = fullKey;
        option.textContent = label;
        if (fullKey === selectedValue) {
          option.selected = true;
        }
        fieldSelect.appendChild(option);
      }

      if (selectedValue && !entries.some(([fullKey]) => fullKey === selectedValue)) {
        fieldSelect.value = '';
      }

      TestHooks.syncField(fieldSelect, entries.length);

      const selectize = fieldSelect.selectize;
      if (!selectize) {
        return;
      }

      selectize.clear(true);
      selectize.clearOptions();

      for (const [fullKey, label] of entries) {
        selectize.addOption({ value: fullKey, text: label });
      }

      if (selectedValue && optionMap[selectedValue]) {
        selectize.setValue(selectedValue, true);
        selectize.refreshItems();
      }

      TestHooks.syncField(fieldSelect, entries.length);
    },
  };

  const TestHooks = {
    syncPlugin(pluginSelect) {
      const item = Dom.findAncestorListItem(pluginSelect);
      if (!item) {
        return;
      }

      item.dataset.testid = 'secret-split-plugin-row';
      item.dataset.secretSplitPlugin = pluginSelect.value || '';

      const control = SelectizeAdapter.getControl(pluginSelect);
      if (control) {
        control.dataset.testid = 'secret-split-plugin-picker';
      }
    },

    syncField(fieldSelect, optionCount) {
      const item = Dom.findFieldItem(fieldSelect);
      if (!item) {
        return;
      }

      const pluginSelect = Dom.findPluginSelectForFieldRow(fieldSelect);
      item.dataset.testid = 'secret-split-field-row';
      item.dataset.secretSplitPlugin = pluginSelect?.value || '';
      if (typeof optionCount === 'number') {
        item.dataset.secretSplitOptionCount = String(optionCount);
      }

      const control = SelectizeAdapter.getControl(fieldSelect);
      if (control) {
        control.dataset.testid = 'secret-split-field-picker';
        if (typeof optionCount === 'number') {
          control.dataset.secretSplitOptionCount = String(optionCount);
        }
      }
    },
  };

  const Catalog = {
    getFilteredOptions(pluginSlug) {
      const catalog = DataStore.getCatalog();
      const entries = Object.entries(catalog.fields || {}).filter(([fullKey]) => {
        return (catalog.fieldPlugins || {})[fullKey] === pluginSlug;
      });

      entries.sort((a, b) => String(a[1]).localeCompare(String(b[1]), 'ru'));

      return Object.fromEntries(entries);
    },

    collectSelectedFieldKeys() {
      const seen = new Set();
      const keys = [];

      document.querySelectorAll(SELECTORS.fieldSelect).forEach((fieldSelect) => {
        const value = fieldSelect.value;
        if (!value || seen.has(value)) {
          return;
        }

        seen.add(value);
        keys.push(value);
      });

      return keys;
    },

    extractPluginSlug(fullKey) {
      const parts = String(fullKey || '').split('.');
      return parts.length >= 3 && parts[0] === 'plugins' ? parts[1] : '';
    },

    buildSourceLabel(pluginSlug, scope, kind) {
      const meta = DataStore.getNormalizedStates().meta;
      const labels = DataStore.getSourceLabels();
      if (!pluginSlug) {
        return labels.not_set || 'Not set';
      }

      if (kind === 'tracked') {
        const prefix = scope === 'env' ? labels.env_config : labels.base_config;
        return `${prefix} (${pluginSlug}.yaml)`;
      }

      if (kind === 'secrets') {
        const prefix = scope === 'env' ? labels.env_secrets : labels.base_secrets;
        const fileName = scope === 'env' ? (meta.env_storage_file || 'secrets.env.yaml') : (meta.base_storage_file || 'secrets.yaml');
        return `${prefix} (${fileName})`;
      }

      return labels.not_set || 'Not set';
    },
  };

  const FieldState = {
    deriveMigratedState(fullKey, fact) {
      if (!fact?.tracked_exists && !fact?.secret_exists) {
        return {
          status: 'missing',
          label: DataStore.t('overview_missing', 'Missing'),
          source: DataStore.t('source_not_set', 'Not set'),
        };
      }

      let targetScope = 'base';
      if (fact?.tracked_scope === 'env') {
        targetScope = 'env';
      } else if (fact?.secret_exists) {
        targetScope = fact.secret_scope === 'env' ? 'env' : 'base';
      } else if (fact?.tracked_exists) {
        targetScope = fact.tracked_scope === 'env' ? 'env' : 'base';
      } else if (DataStore.getNormalizedStates().meta.env_storage_available) {
        targetScope = 'env';
      }

      return {
        status: 'stored',
        label: DataStore.t('overview_stored', 'Stored'),
        source: Catalog.buildSourceLabel(Catalog.extractPluginSlug(fullKey), targetScope, 'secrets'),
      };
    },

    deriveReturnedState(fullKey, fact) {
      if (!fact?.secret_exists && !fact?.tracked_exists) {
        return {
          status: 'missing',
          label: DataStore.t('overview_missing', 'Missing'),
          source: DataStore.t('source_not_set', 'Not set'),
        };
      }

      const targetScope = fact?.secret_exists
        ? (fact.secret_scope === 'env' ? 'env' : 'base')
        : (fact?.tracked_scope === 'env' ? 'env' : 'base');

      return {
        status: 'pending',
        label: DataStore.t('overview_pending', 'Pending'),
        source: Catalog.buildSourceLabel(Catalog.extractPluginSlug(fullKey), targetScope, 'tracked'),
      };
    },

    getDerivedFieldState(fullKey) {
      const fact = DataStore.getFieldFact(fullKey);
      const action = AdminState.getPendingAction();

      if (action === 'migrate') {
        return this.deriveMigratedState(fullKey, fact);
      }

      if (action === 'return') {
        return this.deriveReturnedState(fullKey, fact);
      }

      return fact || {
        status: 'missing',
        label: DataStore.t('overview_missing', 'Missing'),
        source: DataStore.t('source_not_set', 'Not set'),
      };
    },

    buildLiveState() {
      const counts = { stored: 0, pending: 0, duplicate: 0, missing: 0 };
      const actualCounts = { stored: 0, pending: 0, duplicate: 0, missing: 0 };
      const fields = {};
      const action = AdminState.getPendingAction();

      Catalog.collectSelectedFieldKeys().forEach((fullKey) => {
        const currentFact = DataStore.getFieldFact(fullKey) || { status: 'missing' };
        const fact = this.getDerivedFieldState(fullKey);

        fields[fullKey] = fact;
        if (Object.prototype.hasOwnProperty.call(counts, fact.status)) {
          counts[fact.status] += 1;
        }
        if (Object.prototype.hasOwnProperty.call(actualCounts, currentFact.status)) {
          actualCounts[currentFact.status] += 1;
        }
      });

      return {
        fields,
        counts,
        actualCounts,
        pendingTotal: Number(actualCounts.pending || 0) + Number(actualCounts.duplicate || 0),
        returnTotal: Number(actualCounts.stored || 0) + Number(actualCounts.duplicate || 0),
        previewPendingTotal: Number(counts.pending || 0) + Number(counts.duplicate || 0),
        previewReturnTotal: Number(counts.stored || 0) + Number(counts.duplicate || 0),
        selectedAction: action,
      };
    },
  };

  const FieldUi = {
    refreshFieldListOptions(fieldSelect) {
      const wrapper = Dom.findFieldListWrapper(fieldSelect);
      if (!wrapper) {
        return;
      }

      const baseOptions = SelectizeAdapter.getWrapperOptions(wrapper);
      const fieldSelects = Array.from(wrapper.querySelectorAll(SELECTORS.fieldSelect));
      const selectedValues = fieldSelects
        .map((select) => select.value)
        .filter((value) => Boolean(value));

      fieldSelects.forEach((select) => {
        const selectedValue = select.value;
        const optionMap = Object.assign({}, baseOptions);
        const selectedOption = selectedValue ? select.querySelector(`option[value="${selectedValue}"]`) : null;
        const selectedFallbackLabel = (
          selectedOption?.textContent ||
          select.selectize?.options?.[selectedValue]?.text ||
          selectedValue ||
          ''
        ).trim();

        selectedValues.forEach((value) => {
          if (value === selectedValue) {
            return;
          }

          delete optionMap[value];
        });

        if (selectedValue) {
          optionMap[selectedValue] = DataStore.getCatalog().fields?.[selectedValue] || selectedFallbackLabel || selectedValue;
        }

        SelectizeAdapter.rebuildFieldSelect(select, optionMap, selectedValue);
        this.renderFieldStatus(select);
      });
    },

    renderFieldStatus(fieldSelect) {
      const row = Dom.findFieldContainer(fieldSelect);
      if (!row) {
        return;
      }

      const node = Dom.ensureStatusNode(fieldSelect);
      if (!node) {
        return;
      }

      const fullKey = fieldSelect.value || fieldSelect.selectize?.items?.[0] || '';
      row.dataset.secretSplitFieldKey = fullKey || '';

      if (!fullKey) {
        row.dataset.secretSplitStatus = '';
        node.replaceChildren();
        return;
      }

      const state = FieldState.getDerivedFieldState(fullKey);
      if (!state) {
        row.dataset.secretSplitStatus = '';
        node.replaceChildren();
        return;
      }

      row.dataset.secretSplitStatus = state.status;

      const badge = document.createElement('span');
      badge.className = `secret-split-badge secret-split-badge--${state.status}`;

      const icon = document.createElement('i');
      icon.className = 'fa fa-circle secret-split-badge-icon';
      icon.setAttribute('aria-hidden', 'true');

      const text = document.createElement('span');
      text.className = 'secret-split-badge-text';
      text.textContent = state.label;

      badge.append(icon, document.createTextNode(' '), text);

      const source = document.createElement('span');
      source.className = 'secret-split-source';
      source.textContent = state.source;

      node.replaceChildren(badge, source);
    },

    bindPluginSelect(pluginSelect) {
      if (!pluginSelect || pluginSelect.dataset.secretSplitBound === '1') {
        return;
      }

      pluginSelect.dataset.secretSplitBound = '1';
      TestHooks.syncPlugin(pluginSelect);
      pluginSelect.addEventListener('change', () => {
        TestHooks.syncPlugin(pluginSelect);
        document.querySelectorAll(SELECTORS.fieldSelect).forEach((fieldSelect) => {
          if (Dom.findPluginSelectForFieldRow(fieldSelect) !== pluginSelect) {
            return;
          }

          const wrapper = Dom.findFieldListWrapper(fieldSelect);
          if (wrapper) {
            SelectizeAdapter.setWrapperOptions(wrapper, Catalog.getFilteredOptions(pluginSelect.value));
            this.refreshFieldListOptions(fieldSelect);
          }
          this.renderFieldStatus(fieldSelect);
        });
        OverviewUi.render();
      });
    },

    bindFieldSelect(fieldSelect) {
      if (!fieldSelect || fieldSelect.dataset.secretSplitBound === '1') {
        return;
      }

      fieldSelect.dataset.secretSplitBound = '1';
      TestHooks.syncField(fieldSelect);

      const refresh = () => {
        const pluginSelect = Dom.findPluginSelectForFieldRow(fieldSelect);
        if (pluginSelect) {
          const wrapper = Dom.findFieldListWrapper(fieldSelect);
          if (wrapper) {
            SelectizeAdapter.setWrapperOptions(wrapper, Catalog.getFilteredOptions(pluginSelect.value));
            this.refreshFieldListOptions(fieldSelect);
          }
        }
        this.renderFieldStatus(fieldSelect);
      };

      fieldSelect.addEventListener('change', () => {
        this.refreshFieldListOptions(fieldSelect);
        this.renderFieldStatus(fieldSelect);
        OverviewUi.render();
      });

      refresh();
    },
  };

  const OverviewUi = {
    makeCountCard(name, label, value) {
      const card = document.createElement('div');
      card.className = `secret-split-overview-card secret-split-overview-card--${name}`;
      card.dataset.secretSplitCount = name;

      const title = document.createElement('strong');
      title.textContent = label;

      const number = document.createElement('span');
      number.textContent = String(value);

      card.append(title, document.createTextNode(' '), number);
      return card;
    },

    makeAction(actionName, className, label, enabled, noteText, selectedAction) {
      const action = document.createElement('div');
      action.className = 'secret-split-overview-action';

      const button = document.createElement('button');
      button.type = 'button';
      button.className = `button ${className}`;
      button.textContent = label;
      if (selectedAction === actionName) {
        button.classList.add('secret-split-overview-button--active');
      }

      if (!enabled) {
        button.classList.add('disabled');
        button.setAttribute('aria-disabled', 'true');
      } else {
        button.addEventListener('click', () => {
          AdminState.setPendingAction(actionName);
          this.render();
          document.querySelectorAll(SELECTORS.fieldSelect).forEach((fieldSelect) => FieldUi.renderFieldStatus(fieldSelect));
        });
      }

      const note = document.createElement('span');
      note.className = 'secret-split-overview-note';
      note.textContent = `${noteText} ${DataStore.t('save_first_note', 'Save this form first, then run migrate or return.')}`.trim();

      action.append(button, note);
      return action;
    },

    render() {
      const target = document.getElementById('secret-split-overview');
      if (!target) {
        return;
      }

      const liveState = FieldState.buildLiveState();
      const counts = liveState.counts || {};
      const pendingTotal = Number(liveState.pendingTotal || 0);
      const returnTotal = Number(liveState.returnTotal || 0);
      const previewPendingTotal = Number(liveState.previewPendingTotal || 0);
      const previewReturnTotal = Number(liveState.previewReturnTotal || 0);
      const selectedAction = liveState.selectedAction || '';

      if ((selectedAction === 'migrate' && pendingTotal === 0) || (selectedAction === 'return' && returnTotal === 0)) {
        AdminState.clearPendingAction();
        return this.render();
      }

      const grid = document.createElement('div');
      grid.className = 'secret-split-overview-grid';
      grid.append(
        this.makeCountCard('stored', DataStore.t('overview_stored', 'Stored'), Number(counts.stored || 0)),
        this.makeCountCard('pending', DataStore.t('overview_pending', 'Pending'), Number(counts.pending || 0)),
        this.makeCountCard('duplicate', DataStore.t('overview_duplicate', 'Tracked + secrets'), Number(counts.duplicate || 0)),
        this.makeCountCard('missing', DataStore.t('overview_missing', 'Missing'), Number(counts.missing || 0))
      );

      const actions = document.createElement('div');
      actions.className = 'secret-split-overview-actions';
      actions.append(
        this.makeAction(
          'migrate',
          'secret-split-migrate-button',
          DataStore.tf('migrate_to_file', 'Move to %file%', {
            '%file%': DataStore.getPrimarySecretsFileName(),
          }),
          previewPendingTotal > 0,
          DataStore.tf('migrate_note_to_file', 'Pending and duplicate values will be moved into %file% and removed from config YAML.', {
            '%file%': DataStore.getPrimarySecretsFileName(),
          }),
          selectedAction
        ),
        this.makeAction(
          'return',
          'secret-split-return-button',
          DataStore.t('return_to_config', 'Move to config'),
          previewReturnTotal > 0,
          DataStore.tf('return_note_from_file', 'Current values from %file% will be written back into config YAML and removed from %file%.', {
            '%file%': DataStore.getPrimarySecretsFileName(),
          }),
          selectedAction
        )
      );

      target.replaceChildren(grid, actions);
    },
  };

  const Runtime = {
    isRelevantMutationNode(node) {
      if (!(node instanceof Element)) {
        return false;
      }

      if (
        node.closest('.selectize-control')
        || node.closest('.selectize-dropdown')
        || node.closest('.selectize-dropdown-content')
      ) {
        return true;
      }

      if (node.matches(SELECTORS.pluginSelect) || node.matches(SELECTORS.fieldSelect) || node.matches(SELECTORS.listItem)) {
        return true;
      }

      return Boolean(
        node.querySelector(SELECTORS.pluginSelect) ||
        node.querySelector(SELECTORS.fieldSelect) ||
        node.querySelector(SELECTORS.listItem) ||
        node.querySelector('.form-list-wrapper') ||
        node.querySelector('.selectize-control')
      );
    },

    observeRoot() {
      runtime.observerRoot = Dom.getObserverRoot();
      if (runtime.observer && runtime.observerRoot) {
        runtime.observer.observe(runtime.observerRoot, { childList: true, subtree: true });
      }
    },

    withObserverPaused(callback) {
      if (runtime.observer) {
        runtime.observer.disconnect();
      }

      try {
        callback();
      } finally {
        this.observeRoot();
      }
    },

    scan() {
      runtime.scanScheduled = false;

      this.withObserverPaused(() => {
        OverviewUi.render();
        document.querySelectorAll(SELECTORS.pluginSelect).forEach((pluginSelect) => FieldUi.bindPluginSelect(pluginSelect));
        document.querySelectorAll(SELECTORS.fieldSelect).forEach((fieldSelect) => FieldUi.bindFieldSelect(fieldSelect));
        document.querySelectorAll(SELECTORS.fieldSelect).forEach((fieldSelect) => FieldUi.renderFieldStatus(fieldSelect));
      });
    },

    scheduleScan() {
      if (runtime.scanScheduled) {
        return;
      }

      runtime.scanScheduled = true;
      if (typeof queueMicrotask === 'function') {
        queueMicrotask(() => this.scan());
        return;
      }

      Promise.resolve().then(() => this.scan());
    },

    handleMutations(mutations) {
      const hasRelevantNodes = mutations.some((mutation) => {
        return Array.from(mutation.addedNodes).some((node) => this.isRelevantMutationNode(node));
      });

      if (hasRelevantNodes) {
        this.scheduleScan();
      }
    },

    bootstrap() {
      Dom.ensurePendingActionInput();
      AdminState.rebaselineAdminFormState();
      AdminState.closeChangesModalIfClean();

      const form = Dom.getCurrentForm();
      if (form) {
        form.addEventListener('submit', () => {
          AdminState.closeChangesModal();
        }, true);
      }

      document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLSelectElement)) {
          return;
        }

        if (target.matches(`${SELECTORS.pluginSelect}, ${SELECTORS.fieldSelect}`)) {
          this.scheduleScan();
        }
      }, true);

      document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
          return;
        }

        if (target.closest('.selectize-dropdown-content .option')) {
          this.scheduleScan();
        }
      }, true);

      this.scheduleScan();
      window.setTimeout(() => this.scheduleScan(), 250);
      window.setTimeout(() => this.scheduleScan(), 1000);
      this.observeRoot();
    },
  };

  document.addEventListener('DOMContentLoaded', () => {
    Runtime.bootstrap();
  });

  runtime.observer = new MutationObserver((mutations) => {
    Runtime.handleMutations(mutations);
  });

  Runtime.observeRoot();
})();
