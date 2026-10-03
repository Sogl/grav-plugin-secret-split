# Secret Split Admin Integration Notes

This document is for developers working on the plugin internals.

It collects the current Grav/Admin/Flex integration constraints, local workarounds, and relevant upstream references. These notes are intentionally kept out of the main README files.

## Scope

`secret-split` supports two admin generations from one codebase:

- **Admin Classic (Grav 1.7)** — the classic model below still applies.
- **Admin Next (Grav 2)** — a different integration surface; see its own section.

On Admin Classic the default model is still:

- edit form
- dirty state
- click `Save`
- full page reload

The plugin adds:

- live preview of protected field state
- deferred `Migrate` / `Return` actions
- secret extraction/scrubbing on normal plugin saves
- secret extraction/scrubbing on Flex configure saves

That means some glue code is currently needed around Admin and Flex internals.

## Current Admin Model (Grav 1.7, Admin Classic)

Current `secret-split` behavior is intentionally:

- changing protected fields updates the page immediately
- `Migrate` / `Return` only prepare intent in the form
- real YAML writes happen only after the normal Admin `Save`

In practice this means:

- the page preview shows the future post-save state
- the form stays dirty until `Save`
- leaving the page before `Save` should trigger the normal Admin unsaved-changes flow

The pending action is kept client-side in a hidden form field:

- `_secret_split_pending_action = migrate|return|''`

No temporary server-side draft storage is used.

## Admin Next (Grav 2)

Admin Next has none of the classic machinery — no `Save` round-trip, no
dirty-state modal, no `selectunique`. The deferred-action model does not
exist there; the equivalent integration surface is different:

- **Custom field types** via `onApiRegisterFieldTypes`, shipped under
  `admin-next/fields/`:
  - `secret-split-field` — field select filtered by the row's plugin plus a
    live status chip, used for `protected_fields[].fields[].field_key`.
  - `secret-split-overview` — status tiles (Stored/Pending/Duplicated/
    Missing) plus the `Move to secrets` / `Move to config` actions, injected
    into the settings form in place of the `overview` display field.
- **API endpoints** via `onApiRegisterRoutes` (state, `migrate`, `return`),
  served through the `api` plugin's auth (JWT/API key). Migrate/Return apply
  **immediately** — there is no deferred form-save on Admin Next.
- **Sidebar shortcut** via `onApiSidebarItems` linking to the plugin
  settings form; gated by the `admin_sidebar_item` option.
- **`/admin/plugin/secret-split`** is a blueprint-mode page bound to the
  same data/save endpoints, kept as a URL-compatible alias of
  `/admin/plugins/secret-split`.

Environment scoping on Admin Next:

- Only `X-Config-Environment` selects the Secret Split scope — the header
  the admin env switcher sends for the chosen view. `X-Grav-Environment`
  alone (the booted environment) is deliberately ignored so a read under the
  wrong scope cannot happen.
- `default`, `base` and empty values select the base view explicitly.
- A named environment without `user/env/<name>/` consistently degrades to
  base behavior — no scoped-config split-brain.

Save semantics that differ from Admin Classic:

- Request bodies are detected as either the admin1 `data` envelope or raw
  JSON config maps (`isApiRequest` = `X-API-Token` / `Authorization: Bearer`
  headers, or a request path under the api plugin's route prefix).
- **Echo preservation**: the Admin Next form never renders stored secrets,
  so a round-tripped empty/default/unchanged tracked value for a key that
  has a stored secret is treated as a form echo — it is removed from the
  tracked payload but the stored secret is left untouched. A genuinely new
  value replaces the secret. (This is the inverse of the admin1 rule where
  an explicit empty value deletes a stored secret.)
- `protected_fields` writes under an env scope persist only the delta
  against the base config file; a list equal to the parent is removed so
  inheritance keeps working.

Plugin-specific API controllers that bypass `onAdminSave`:

- Some plugins ship their own api endpoints which write their config YAML
  directly — e.g. Algolia Pro's `PATCH /api/v1/algolia-pro/data`
  (`AlgoliaProApiController::save`). These never fire `onAdminSave`, so the
  per-form extraction hook cannot see them.
- Coverage is pipeline-agnostic: on any api request — detected by the api
  plugin's route prefix (`plugins.api.route` + `version_prefix`, default
  `/api/v1`) in addition to `X-API-Token`/`Authorization: Bearer` headers —
  the plugin snapshots every protected plugin's tracked config and runs the
  same post-save migration at shutdown. Whatever the endpoint wrote gets
  scrubbed; unchanged files are left alone.

## Local Workarounds

All items in this section apply to **Admin Classic (Grav 1.7)** — Admin Next
does not share these widgets or the deferred-save model.

### Grav Admin `selectunique`

Dynamic rows are not fully supported by the Admin widget out of the box.

Changing `data-select-unique` on the wrapper alone does not rebuild the widget's internal option cache, so `secret-split` currently resyncs field options on the client side.

This is a local workaround around Admin internals, not a public Grav API.

### Admin unsaved-changes modal (`#changes`)

Grav Admin uses a remodal with `data-remodal-id=\"changes\"` and hash tracking.

In our deferred flow, the following route was problematic:

1. prepare `Return` or `Migrate`
2. click another Admin menu item
3. get the `Changes Detected` modal
4. click `Cancel`
5. click normal `Save`

Without local cleanup, `#changes` could survive in the URL and reopen the modal on the clean page after save.

`secret-split` currently applies a local cleanup in its Admin JS:

- if the form is already clean, close the `changes` remodal if present
- remove `#changes` from the URL
- clear the modal/hash again before form submit

This is intentionally localized in the plugin and does not patch Grav core/Admin files.

### Early Admin detection

Do not wrap early `onPluginsInitialized()` logic in a naive `isAdmin()` guard.

On this installation, some Admin/Flex requests may still report a falsey admin state at that stage, which breaks:

- state injection
- save interception
- Flex configure interception

### Flex configure save path

Flex does not expose a clean pre-persist hook in older Grav versions, so the current path uses pragmatic interception around save and a post-save migration step.

Current local behavior is:

- snapshot tracked plugin config before the Flex save finishes
- wait until shutdown/post-save time
- migrate/scrub only if the tracked config file actually changed

This avoids the earlier class of side effects where secrets could be written before the underlying tracked config save had really succeeded.

Relevant upstream improvement:

- Grav core commit adding `onFlexDirectoryConfigBeforeSave`:
  - [getgrav/grav@53d63e8](https://github.com/getgrav/grav/commit/53d63e8a2ec51c43ef947298891328c259957b1a)
- Request that led to it:
  - [trilbymedia/grav-plugin-flex-objects#194](https://github.com/trilbymedia/grav-plugin-flex-objects/issues/194)

The event exists in Grav 2.2.x core. The snapshot + shutdown path is still
retained deliberately — one codebase must serve 1.7 too, where the event
does not exist. It can only be simplified if Grav 1.7 support is dropped.

### Form nonce differences

Flex configure forms do not always use the same nonce flow as normal Admin plugin config forms.

`secret-split` currently supports:

- `admin-nonce`
- `form-nonce`
- `login-nonce`
- generic fallback `nonce`

This is necessary for mixed Admin/Flex interception.

## Upstream Admin Limitation

There is currently no small public Admin-side API for:

- marking a form clean
- rebaselining dirty-state after plugin-controlled save-like flows
- closing the unsaved-changes modal in a supported way
- clearing `#changes` when the form is already clean

Relevant upstream request:

- [getgrav/grav-plugin-admin#2506](https://github.com/getgrav/grav-plugin-admin/issues/2506)

If Admin gains such an API, `secret-split` should switch to it and drop its local modal/hash glue code.

## Practical Limits

For the Admin Classic (Grav 1.7) surface, current implementation is considered reliable for:

- normal plugin config forms
- supported Flex configure forms
- deferred `Migrate` / `Return` followed by normal `Save`

Current implementation should still be treated as sensitive around:

- new/unfamiliar custom Admin widgets
- third-party blueprints with unusual field structures
- future Admin changes to dirty-state or remodal internals
- future Flex save-path changes

## Maintenance Rule

If a future change makes any of the local workarounds unnecessary, prefer removing them over documenting more complexity.
