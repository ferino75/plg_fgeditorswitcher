# Changelog — FG Editor Switcher (plg_fgeditorswitcher)

## 2.0.5 — Fixed: Cancel on the confirmation dialog left the wrong option shown
- If a user picked a different editor, the confirmation dialog appeared, and
  they clicked **Cancel**, the switch correctly did not happen (no cookie
  change, no reload) - but the `<select>` kept displaying the newly picked
  (cancelled) option instead of reverting to the actually active editor.
- Fix: track the select's value (not its index - stays correct even if the
  editor list changes) at attach time, and explicitly restore it
  (`this.value = currentValue`) when the confirmation is cancelled. The now
  unused `data-current-index` attribute/index-tracking was removed from both
  the PHP and the JS side.

## 2.0.4 — Prevent switching to itself via the cookie
- The cookie that decides which underlying editor to use is client-controlled
  input. Nothing previously stopped it from naming this very plugin's own
  element (`fgeditorswitchercurrent=fgeditorswitcher`): `PluginHelper::isEnabled()`
  would return true for it (it genuinely is enabled), and `Editor::getInstance()`
  would then hand back a wrapper pointing straight back at this class, so
  `onDisplay()` would delegate to itself.
- Fix: added a private `isValidEditor()` check (excludes an empty value and
  the plugin's own `fgeditorswitcher` element, in addition to the existing
  `PluginHelper::isEnabled()` check) used for both the cookie value and the
  `default_editor` fallback.

## 2.0.3 — Fixed missing dropdown arrow on some admin templates
- On some admin templates, the custom chevron drawn via `background-image`
  was not showing at all - DevTools confirmed the computed `background-image`
  was `none`, i.e. the rule wasn't applying, most likely because the admin
  template applies its own `!important` background/appearance reset to
  `<select>` elements which otherwise wins regardless of load order.
- Fix: added `!important` to every arrow-related declaration
  (`appearance`, `background-image`, `background-repeat`,
  `background-position`, `background-size`) in `fgeditorswitcher.css`, so it
  reliably wins over such template resets.

## 2.0.2 — Fixed for real: language file naming convention
- v2.0.1's fix (adding `<folder>language</folder>` to `<files>`) was based on a
  wrong theory and didn't fix anything. The actual root cause: Joomla derives
  the language extension name a plugin looks for internally as
  `plg_<group>_<element>` (e.g. `Plg_editors_fgeditorswitcher` inside
  `CMSPlugin::loadLanguage()`) - but the language `.ini`/`.sys.ini` files were
  named just `plg_fgeditorswitcher.*`, missing the `editors_` group segment
  that the original `plg_editors_switcher` naming always had. The lookup name
  and the actual filenames never matched, regardless of which folder they
  were installed into - hence v2.0.1 not helping either.
- Fix: renamed all four language files to `plg_editors_fgeditorswitcher.ini` /
  `.sys.ini` (en-GB, sk-SK) and updated the manifest's `<languages>` entries
  and `<name>` to match.

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
