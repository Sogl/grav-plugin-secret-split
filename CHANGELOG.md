# v1.1.0
## 10/03/2026

1. [](#new)
    * Grav 2 / Admin Next support: the plugin's settings form now hosts the
      full admin1 experience — overview status tiles, migrate/return actions
      and per-field status chips via custom field types
      (`secret-split-overview`, `secret-split-field`). Optional sidebar menu
      item (new `admin_sidebar_item` option) links straight to the settings
      page. Grav 1.7 admin-classic UI is unchanged.
    * Environment scoping: the Admin Next environment switcher selects
      base/env secrets and tracked config via `X-Config-Environment`, so
      statuses, extraction and `protected_fields` writes follow the operator's
      chosen scope (env writes persist deltas only).
2. [](#improved)
    * `protected_fields` saves on Admin Next now keep cleared-field semantics:
      JSON request bodies are detected instead of form POST data.
    * Translations ship dual-target `ICU.*` blocks (EN/RU) for Admin Next.
3. [](#bugfix)
    * Api saves can no longer delete or overwrite an existing secret with a
      round-tripped empty/default/unchanged form value — the Admin Next form
      never shows stored secrets, so those payloads are treated as echoes.
    * Return-to-config writes tracked YAML before removing secrets, and all
      multi-file mutations run under a shared file lock.
    * Secrets files are written with `0600` permissions; `user://` storage
      paths can no longer escape the user directory.
    * The runtime overlay now sets only currently-protected leaf keys instead
      of merging whole secrets files into config.
    * Flex post-save migration honored the changed scope explicitly: secrets
      extracted from a changed base tracked config now land in base storage
      even when an env secrets file exists (`resolveStorageTarget` 'base').
    * Plugin-specific Admin Next endpoints that write config without firing
      `onAdminSave` (e.g. `PATCH /api/v1/algolia-pro/data`) are now covered:
      api requests are detected by the api plugin's route prefix in addition
      to token headers, and every protected plugin's tracked config is
      watched for post-save secret extraction.

# v1.0.2
## 03/14/2026

1. [](#improved)
    * Restore early blueprint/bootstrap loading for Secret Split so GPM and admin metadata paths resolve normally.

# v1.0.1
## 03/14/2026

1. [](#improved)
    * Preserve Grav base/env storage layering during return-to-config and move-to-secrets flows.

# v1.0.0
## 03/13/2026

1. [](#new)
    * First project release.
