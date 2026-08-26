# Changelog — FG Editor Switcher (plg_fgeditorswitcher)

## 2.2.2 — Reverted the joomla.asset.json migration (broke asset loading)
- 2.2.1's migration to `media/joomla.asset.json` + `useStyle()`/`useScript()`
  broke JS/CSS loading in real-world testing.
- Reverted to the previously verified-working `registerAndUseStyle()`/
  `registerAndUseScript()` calls with a direct path (as used through 2.2.0).
  `media/joomla.asset.json` and its manifest reference were removed; the
  `VERSION` constant again drives the cache-busting query string as before.
  Everything else from 2.2.1 (`<php_minimum>`, the `fgeditors` field type
  rename, the uninstall cookie cleanup, the `LICENSE` reference fix) is
  unaffected and kept.

## 2.2.1 — Packaging/manifest hygiene batch
- **`<php_minimum>` in the manifest itself**, not just `updates.xml`: without
  it, Joomla could accept a direct ZIP install on an unsupported PHP version
  (typed properties need 7.4+) even though the update server would have
  refused to offer it. README's PHP badge/requirement also corrected from
  "8.0+" to "7.4+" to match.
- **`type="editors"` → `type="fgeditors"`**, and `EditorsField` renamed to
  `FgeditorsField`: the field type currently only resolves via
  `addfieldprefix`, but a bare `type="editors"` would collide if Joomla core
  ever ships its own class of that name in the future. Prefixing avoids that
  class of problem outright.
- **Cookie cleared on uninstall**: added `script.php` (a modern
  `InstallerScriptInterface` implementation, matching how `services/provider.php`
  is already written) that expires the `fgeditorswitchercurrent` cookie in
  the uninstaller's browser. Purely cosmetic - the cookie was already
  harmless once the plugin no longer exists to read it.
- **Fixed `LICENSE.txt` → `LICENSE`** in a doc comment (the file in this repo
  is named `LICENSE`, not `LICENSE.txt`).
- **Migrated to `media/joomla.asset.json`** for declaring the plugin's CSS/JS,
  the currently recommended Joomla 4+/5+ approach, replacing the ad hoc
  `registerAndUseStyle()`/`registerAndUseScript()` calls with `useStyle()`/
  `useScript()` against names declared in the new JSON file (a plugin's own
  asset registry isn't auto-discovered the way a component's is, so it's
  explicitly added via `$wa->getRegistry()->addRegistryFile(...)` first).
  This is a "cleaner code" change with no functional difference for end
  users, and does *not* reduce the number of places version numbers need
  updating on a release (if anything, `joomla.asset.json` adds two more) -
  kept deliberately for consistency with recommended practice rather than
  for that reason.

## 2.2.0 — CSP-safe layout (no more inline `style="..."` attributes)
- The wrapper `<div>` and `<select>` used PHP-generated inline `style="..."`
  attributes for layout (`display:inline-flex`, `width:auto`, etc). A site
  running Joomla's CSP system plugin with a strict `style-src` (no
  `'unsafe-inline'`) would have those attributes stripped by the browser,
  breaking the layout. JS's own runtime colour/position tweaks (via
  `element.style.setProperty()`) are unaffected either way - CSP only
  restricts inline style markup/`<style>` blocks, not programmatic CSSOM
  writes.
- Fix: moved that layout into two real CSS classes,
  `.fg-switcher-wrap`/`.fg-switcher-select` (in `fgeditorswitcher.css`), used
  on the div/select instead of `style="..."`. As a side effect, this also
  replaces the `[id^="..."]` attribute-prefix selectors previously used in
  both CSS and the relocation JS with plain class selectors - unique `id`s
  are kept on both elements (still needed for HTML validity/uniqueness), but
  no longer required for styling or for JS to find them.

## 2.1.9 — More reliable scroll restoration
- The scroll position was restored on `DOMContentLoaded`, which fires before
  layout has necessarily settled - TinyMCE in particular builds its own
  iframe asynchronously, which can change the page's height and silently
  undo a too-early scroll restoration.
- Fix: set `history.scrollRestoration = 'manual'` (so the browser's own
  automatic restoration doesn't fight with ours) and moved the restore to
  `window.load` (fires once all resources, including editor iframes, have
  finished loading) wrapped in `requestAnimationFrame()` for one more frame
  of margin. The relocation/colour-matching logic stays on
  `DOMContentLoaded` as before - unaffected by this.

## 2.1.8 — Accessible label + debounced change (no more per-arrow-key dialogs)
- **2.1 Accessibility:** the `<select>` had no accessible name for screen
  readers (no `<label>`/`aria-label`/`title`) - a WCAG 4.1.2 gap, even though
  a matching language key (`PLG_EDITORS_FGEDITORSWITCHER_SELECTEDITOR`,
  "Select editor") already existed unused. Added it as both `aria-label` and
  `title`.
- **2.2 Keyboard UX:** in Chrome/Edge, a *closed* `<select>` fires a `change`
  event on every arrow-key press that moves the selection, not only once a
  choice is committed - a keyboard user scanning through the options with
  arrow keys got the confirmation dialog (and a reload once confirmed) on
  every single key press. The `change` handler is now debounced (400ms): the
  actual confirm/cookie/reload logic (moved into a new `commitChange()`) only
  runs for the value the user has settled on for a brief moment, not every
  intermediate value along the way. (The alternative of replacing the
  `<select>` with a full custom Bootstrap dropdown was considered but
  rejected as too invasive a redesign relative to the benefit, given how much
  tuning already went into the current markup/styling.)

## 2.1.7 — Dropdown arrow now matches the toolbar's own text colour
- The chevron drawn via CSS `background-image` was a hard-coded white SVG.
  JS already copies `background-color`/`border-color`/`color` from the
  toolbar's own reference button to match admin templates whose xtd buttons
  aren't the plugin's own default dark colour - but the arrow stayed white
  regardless, making it invisible on a light-coloured toolbar.
- Fix: when JS copies those colours (i.e. the selector was successfully
  relocated into a toolbar), it now also rebuilds the same chevron SVG with
  the button's actual resolved text colour and overrides `background-image`
  with it. The static white CSS chevron remains as the fallback for the
  selector's default (not relocated) inline position.

## 2.1.6 — Avoid resubmitting a POST on switch
- `window.location.reload()` repeats whatever HTTP method loaded the current
  document. Edit screens normally open via GET, but after a failed save/
  validation the current document can be the result of a POST - reload()
  could then trigger the browser's "resubmit form?" prompt, or in the worst
  case silently re-submit that POST.
- Fix: switched to `window.location.replace(window.location.href)`, which
  always performs a fresh GET and replaces the history entry instead of
  adding a new one.

## 2.1.5 — Robust toolbar matching (no longer relies on DOM order)
- The relocation JS previously paired the n-th selector with the n-th
  `.editor-xtd-buttons` toolbar found anywhere on the page. On a page with a
  toolbar this plugin doesn't manage (an editor in a modal, a field from
  another component, a hidden subform) or with DOM order differing from
  render order, a selector could get moved next to the wrong editor's
  toolbar.
- Fix: added `findToolbarFor()`, which instead walks backwards through DOM
  siblings starting from the selector's own wrapper (PHP always renders that
  wrapper directly after the active editor's own markup), falling back to
  the nearest common field container if that search finds nothing. No
  assumption about page-wide DOM order any more.

## 2.1.4 — Stopped mutating Joomla's shared plugin cache
- `getEditorSelector()` wrote `$o->text = ucfirst($o->name);` directly onto
  the objects returned by `PluginHelper::getPlugin('editors')` - but those
  are references into `PluginHelper`'s own internal static cache, not
  copies, so the write persisted for the rest of the request and could leak
  into any other code (another plugin, `PluginsField`, `EditorsField`...)
  reading the same cached plugin list afterwards.
- Fix: build a fresh array of `HTMLHelper::_('select.option', ...)` results
  instead, never touching the cached plugin objects at all.

## 2.1.3 — Safe degraded fallback instead of an empty field
- If genuinely no usable editor plugin was enabled at all (`$switchereditor`
  stayed `null`), `onDisplay()` returned `''` - silently dropping the field
  from the form entirely. No input means nothing gets submitted, which on
  save could wipe out existing content for that field.
- Fix: render a plain, unstyled `<textarea>` (properly HTML-escaped) in that
  case instead. The field stays present and editable - no toolbar/WYSIWYG,
  but content is safe - in this hopefully-rare misconfigured state.

## 2.1.2 — Lazy editor initialisation (moved out of the constructor)
- Because this plugin must be the site's configured default editor to work
  at all, Joomla constructs it for *every* editor field rendered anywhere
  (front-end or back-end) - not only when its own UI is shown. The
  constructor previously did all the real work there: reading the cookie,
  resolving the underlying editor, and instantiating its
  `Editor::getInstance()` wrapper - meaning that ran on every such render,
  including a nested `Editor::getInstance()` call in the middle of Joomla's
  own editor-plugin bootstrap.
- A more serious side effect: the "editor not found" warning could be queued
  once per editor field on a page (duplicate messages), and a `RuntimeException`
  from `resolveEditor()` inside the constructor could propagate into Joomla's
  plugin-boot machinery instead of degrading gracefully.
- Fix: the constructor now does nothing beyond `parent::__construct()`. The
  actual work moved to a new `initEditor()`, called from `onInit()`/
  `onDisplay()` and guarded to run only once per request. The
  `RuntimeException` case is now caught there, leaving `$switchereditor`
  `null` (already handled by `onDisplay()` returning `''`) instead of
  propagating. The "editor not found" message also no longer fires for the
  ordinary case of an empty cookie resolving to the configured default (only
  for a genuine fallback), and its severity changed from `error` to
  `warning`.

## 2.1.1 — More natural confirmation dialog text
- The confirmation dialog text ("The data which is not saved does not
  return." / "Is it all right?") read like an old, awkward translation.
- Updated to "Switch editor?" / "Unsaved changes will be lost." (en-GB) and
  "Prepnúť editor?" / "Neuložené zmeny sa pri prepnutí stratia." (sk-SK) -
  the question now shows first, matching how the dialog actually reads.

## 2.1.0 — Single VERSION constant for asset cache-busting
- The version string was hardcoded in three separate PHP spots
  (`registerAndUseStyle()`, `registerAndUseScript()`, and the diagnostic HTML
  comment) plus the manifest's `<version>` tag - a release meant editing it
  in four places.
- Fix: added `private const VERSION`, referenced by all three PHP spots. The
  manifest's `<version>` tag is a separate XML file and can't reference a PHP
  constant, so it still needs its own edit on release (as does this
  changelog) - down from four manual edits to two.

## 2.0.9 — Use `$this->params` instead of re-fetching the plugin
- The constructor and `getEditorSelector()` each looked up this plugin's own
  row again via `PluginHelper::getPlugin('editors', 'fgeditorswitcher')` just
  to read its params - even though `CMSPlugin`'s own constructor already
  populates `$this->params` (a `Registry`) from the same row, since
  `services/provider.php` passes it into `$config`.
- Fix: use `$this->params` directly in both places; removed the now-unused
  `use Joomla\Registry\Registry;` import. No behaviour change - purely
  cleaner code, fewer dependencies, one less repeated plugin lookup.

## 2.0.8 — Guaranteed-unique selector ids
- The id/name suffix was built purely from the sanitised control name, which
  is not actually guaranteed unique: two different names can sanitise to the
  same string (e.g. `jform[a][b]` and `jform_a__b_` both become
  `jform_a__b_`), and the same control name could in principle appear twice
  on one page.
- Fix: appended a static per-request counter to the suffix, which genuinely
  guarantees uniqueness regardless of what the sanitised name collides with.
  The sanitised name is kept for readability, the counter is what actually
  prevents duplicate ids.

## 2.0.7 — Removed dead hidden-input code
- The `<input type="hidden" id="fgeditorswitcher-currentvalue-...">` (and its
  `data-hidden-id` attribute) was a leftover from an earlier implementation.
  Nothing read it, and it had an inconsistency of its own: initialised to the
  editor's name (e.g. `"tinymce"`) but overwritten with a numeric option
  index (e.g. `"2"`) once a switch was confirmed.
- Removed the hidden input, `data-hidden-id`, and the corresponding
  `hiddenId`/`hidden` handling in `fgeditorswitcher.js`.

## 2.0.6 — More robust fallback when the "None" editor is disabled
- Previously, whenever the requested editor was invalid, the plugin fell back
  straight to `PluginHelper::getPlugin('editors', 'none')` unconditionally -
  assuming "Editor - None" exists and is enabled. True in practice, but an
  admin could disable it, leaving `$plugin` empty and `$plugin->name` unsafe
  to read.
- Fix: added `resolveEditor()`, trying the requested editor, then "None" (if
  enabled), then the first other enabled editor plugin, only throwing if
  genuinely no usable editor plugin is enabled at all.

## Repo-only: added `<php_minimum>` to `updates.xml`
- `updates.xml` declared `<targetplatform>` matching Joomla 4/5/6, but had no
  `<php_minimum>`. The plugin uses typed properties (`protected ?Editor
  $switchereditor = null;`), which require PHP 7.4+ - Joomla 4's own official
  minimum is PHP 7.2.5, so without this the update server could in theory
  offer the plugin to a Joomla 4 site still running PHP 7.2/7.3, resulting in
  a PHP parse error on install.
- Fix: added `<php_minimum>7.4.0</php_minimum>` to `updates.xml`. Not a code
  change - `updates.xml` isn't part of the installed package, so no plugin
  version bump for this.

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
