# Changelog — FG Editor Switcher (plg_fgeditorswitcher)

## 2.0.1 — Fixed: language strings not translating in the plugin admin screen
- After install, the Plugins → FG Editor Switcher edit screen showed raw,
  untranslated `PLG_EDITORS_FGEDITORSWITCHER_...` keys instead of proper
  labels/description.
- Root cause: `CMSPlugin::loadLanguage()` (triggered automatically via
  `autoloadLanguage = true`) tries `administrator/language/<tag>/` first,
  and falls back to the plugin's *own* installed folder
  (`plugins/editors/fgeditorswitcher/language/<tag>/...`) - never the
  site-wide `language/<tag>/` folder that the manifest's root-level
  `<languages>` block installs to.
- Fix: added `<folder>language</folder>` to the manifest's `<files>` section
  so the language files are also copied directly into the plugin's own
  install folder, matching where `loadLanguage()`'s fallback looks. The
  original site-wide `<languages>` declaration was kept as well (harmless,
  and covers other contexts that may still check it).

## 2.0.0 — Rebranded into the FG series

- Renamed from `plg_editors_switcher` to `plg_fgeditorswitcher`; namespace
  moved to `FG\Plugin\Editors\Fgeditorswitcher`, class renamed to
  `Fgeditorswitcher`.
- Author/copyright metadata updated (Fero); update server pointed at
  `ferino75/plg_fgeditorswitcher` on GitHub.
- Cookie renamed to `fgeditorswitchercurrent`; all HTML element ids/JS/CSS
  selectors renamed to the `fgeditorswitcher` prefix.
- Language files consolidated to `en-GB` + `sk-SK` (the outdated third-party
  `fr-FR`/`ja-JP` translations, which referenced long-removed features and a
  different original translator, were dropped rather than carried forward
  incorrectly).
- Fixed the manifest's extension name tag: it used the non-standard `<n>`,
  not the actual Joomla manifest tag `<name>`. This had no visible effect
  since the plugin's `element` was already correctly set via the
  `plugin="..."` attribute on the services folder, but it is the correct tag
  regardless.
- Version numbering restarted at `2.0.0`; the detailed development history
  prior to this point (the original J3/J4 plugin, the Joomla 6 migration, and
  all the toolbar-integration/styling work through v6.20.0 of the
  `plg_editors_switcher` line) is not carried forward into this changelog.

This release is otherwise functionally identical to `plg_editors_switcher`
v6.20.0: editor switching via a dropdown matching the admin template's
toolbar buttons, support for multiple editor fields on one page, an optional
confirmation prompt, a debug-logging mode, and scroll-position preservation
across the switch.
