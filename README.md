# FG Editor Switcher

![Version](https://img.shields.io/github/v/release/ferino75/plg_fgeditorswitcher?label=version)
![Joomla](https://img.shields.io/badge/Joomla-4%20%7C%205%20%7C%206-orange.svg)
![License](https://img.shields.io/badge/license-GPL--2.0-brightgreen.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)

A Joomla editor plugin that lets you switch which editor (TinyMCE, CodeMirror,
None, JCE, ...) is used for editing fields, directly from a dropdown placed
next to the standard edit-screen toolbar (Article/Image/Pagebreak/Read
More/...) - without going through Global Configuration.

## Features

- Dropdown selector styled to match the admin template's own toolbar buttons
  (colour, height, alignment) instead of a plain, mismatched `<select>`.
- Works with any installed/enabled editor plugin, including third-party ones
  (e.g. JCE) that use a different toolbar markup than Joomla's core editors.
- Supports multiple editor fields on the same admin page.
- Remembers the chosen editor via a cookie, with an optional confirmation
  prompt before switching (unsaved changes are lost on switch).
- Optional debug mode that logs the active editor and cookie value to the
  browser console.
- Native Joomla 6 architecture: PSR-4, dependency injection via
  `services/provider.php`, assets declared in `media/joomla.asset.json` and
  loaded via WebAssetManager.

## Requirements

- Joomla 4.2, 5.x or 6.x
- PHP 7.4+

## Installation

1. Download the latest release ZIP from the
   [Releases](https://github.com/ferino75/plg_fgeditorswitcher/releases) page.
2. Install it via Joomla's Extensions → Manage → Install.
3. Enable the plugin under **Plugins → FG Editor Switcher**, and set your
   preferred default editor and other options.
4. In **System → Global Configuration → Site → Default Editor**, select
   **FG Editor Switcher** as the site's default editor.

## Updates

This plugin ships with a Joomla update server pointing at this repository's
`updates.xml`, so new releases are offered automatically through Joomla's
Extension Manager once installed.

## License

[GNU General Public License v2.0](LICENSE)
