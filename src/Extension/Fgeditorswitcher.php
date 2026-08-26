<?php
/**
 * @package       Joomla.Plugin
 * @subpackage    Editors.fgeditorswitcher
 * @version       2.3.0
 *
 * @copyright     (C) 2026 Fero
 * @license       https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 *
 * @author        Fero
 * @link          https://ferino75.github.io/
 *
 * Implementation notes (the "why" behind a few non-obvious decisions):
 *  - Nothing is resolved in the constructor. PluginHelper::importPlugin('editors')
 *    boots EVERY plugin of the "editors" group, not just the active one, so this
 *    class is constructed on every request where any editor is loaded - even when
 *    the active editor is TinyMCE and this switcher is never displayed. All the
 *    real work (reading the cookie, resolving/instantiating the underlying
 *    editor, enqueueing messages) therefore happens lazily in initEditor(),
 *    called from onDisplay(). This also keeps Editor::getInstance() out of the
 *    middle of an in-progress importPlugin('editors') call.
 *  - getApp() falls back through CMSPlugin::getApplication() to
 *    Factory::getApplication() explicitly, because the service provider calls
 *    setApplication() only AFTER construction, and this class declares its own
 *    typed properties (so CMSPlugin's magic __get() fallback never fires).
 *  - Delegation to the underlying editor goes through the official
 *    Joomla\CMS\Editor\Editor wrapper (getInstance()/display()), not by
 *    calling onDisplay() directly on a raw plugin instance obtained via
 *    bootPlugin(). Some editors have migrated to the newer
 *    EditorProviderInterface architecture and no longer expose a public
 *    onDisplay() method at all - the Editor wrapper transparently supports
 *    both, so delegating through it is the forward-compatible approach.
 *    Editor::initialise() is deliberately not called from onInit(): it is
 *    deprecated in current Joomla versions in favour of Editor::display()
 *    loading its own assets.
 *  - If no usable editor can be resolved at all, onDisplay() renders a plain
 *    <textarea> instead of an empty string. Returning '' would leave the form
 *    with no input at all for that field, which not only blocks editing but
 *    can also submit the record with an empty value.
 *  - Option labels come from each editor's own installed sys.ini
 *    ("PLG_EDITORS_<ELEMENT>", e.g. "Editor - TinyMCE"), not from
 *    ucfirst($element) - that produced wrong brand spellings such as
 *    "Tinymce"/"Codemirror"/"Jce". The leading "Editor - " qualifier is
 *    stripped so the dropdown stays narrow enough for a toolbar.
 *  - The plugin rows returned by PluginHelper::getPlugin() come out of a
 *    static cache and are shared objects; they are therefore only READ here,
 *    never written to. An earlier version set ->text on them, which silently
 *    mutated Joomla's own cached rows for the rest of the request.
 *  - The selector <select> is styled with the same "xtd-button btn
 *    btn-secondary" classes as the standard editor-xtd buttons and, as a
 *    progressive enhancement, is relocated by JS to sit directly inside the
 *    ".editor-xtd-buttons" toolbar (if the active editor renders one) so it
 *    visually matches its neighbours. It deliberately does NOT override its
 *    own margin/align-self: admin templates commonly apply an ambient
 *    ".editor-xtd-buttons .btn { margin-bottom: ... }" rule that keeps
 *    flex-aligned buttons in visual lockstep, and overriding it just for this
 *    selector reintroduces a vertical offset against its siblings. If no such
 *    toolbar exists for the active editor (e.g. "Editor - None"), the
 *    selector simply stays in its default inline position - never hidden.
 *  - getEditorSelector() builds a unique id/name suffix for every instance
 *    (a sanitised version of the editor field's own control name, plus a
 *    per-request counter that actually guarantees uniqueness - the sanitised
 *    name alone can collide), so multiple editor fields on the same admin
 *    page each get their own valid, non-duplicated selector instead of only
 *    the first one working. The JS/CSS assets are registered once per page
 *    via WebAssetManager regardless of how many fields exist. Both the
 *    counter and the "assets already registered" flag are instance
 *    properties, not statics: the plugin is a per-request singleton from the
 *    DI container, so instance state is equivalent here but does not leak
 *    across requests in a long-running (Swoole/RoadRunner) or test context.
 *  - Per-instance behaviour (confirmation text, the cookie name, debug
 *    logging) is passed to the static media/js/fgeditorswitcher.js via
 *    data-* attributes rather than being templated into inline JavaScript,
 *    so the JS is a plain cacheable asset and PHP only needs to do
 *    HTML-attribute escaping.
 *  - No style="" attribute is rendered at all: the markup only carries the
 *    "fg-switcher-wrap" / "fg-switcher-select" classes and the stylesheet
 *    does the rest. A strict Content Security Policy (Joomla ships a CSP
 *    plugin) without 'unsafe-inline' in style-src drops style attributes
 *    silently, which used to break the selector's layout on exactly the
 *    sites that are configured most carefully. The ids are kept, but only
 *    as stable hooks - nothing styles by them any more.
 *  - The cookie is written with "path=/" and "Secure" (on HTTPS) so the
 *    remembered editor choice does not depend on which admin URL it was set
 *    from. It is deliberately a session cookie (no persistent expiry).
 *  - Switching editors reloads the page, which would normally discard
 *    everything the user had typed but not saved. The content is therefore
 *    handed over from the old editor to the new one entirely on the client
 *    (see media/js/fgeditorswitcher.js). PHP's only part in it is to publish
 *    the id the delegated editor puts on its <textarea>, because that id is
 *    what the JavaScript needs to look the editor instance up:
 *    data-editor-id. The derivation "$id ?: $name" is not a guess - it is
 *    exactly what Joomla's own editor providers do, so the value matches
 *    whatever the delegate actually renders.
 *    A deliberate design choice is that the handover is NOT routed through
 *    the server: an alternative implementation would POST the content to a
 *    com_ajax endpoint and stash it in the session, but the content still has
 *    to be READ on the client either way (only the editor's own JS knows its
 *    unsaved value), so that variant adds a public endpoint, a CSRF token, an
 *    extra round trip and article content in server-side session storage for
 *    no gain over sessionStorage.
 */


namespace FG\Plugin\Editors\Fgeditorswitcher\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Editor\Editor;

// no direct access
\defined('_JEXEC') or die;

/**
 * FG Editor Switcher plugin.
 *
 * Lets an admin switch which underlying editor (TinyMCE, CodeMirror, JCE,
 * None, ...) is used for editor fields, via a dropdown placed next to the
 * standard editor-xtd buttons, without going through Global Configuration.
 *
 * @package   fgeditorswitcher
 * @since     2.0.0
 */
final class Fgeditorswitcher extends CMSPlugin
{
	/**
	 * Plugin version, used for the WebAssetManager cache-busting query
	 * string and the diagnostic HTML comment. Kept as a single constant so a
	 * version bump only has to touch this line (plus the header docblock and
	 * the manifest's own <version> tag, which is a separate XML file and
	 * can't reference a PHP constant).
	 *
	 * @var    string
	 * @since  2.1.0
	 */
	private const VERSION = '2.3.0';

	/**
	 * Editors preferred as a fallback, in order, when the requested editor is
	 * not usable. "none" first (always safe and cheap), then a lightweight
	 * code editor, and only then whatever else happens to be enabled - so the
	 * fallback does not land on an unexpectedly heavy editor just because it
	 * comes first in the database.
	 *
	 * @var    string[]
	 * @since  2.3.0
	 */
	private const FALLBACK_EDITORS = ['none', 'codemirror'];

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  2.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Editor wrapper for the currently active underlying editor.
	 *
	 * @var \Joomla\CMS\Editor\Editor|null
	 * @since 2.0.0
	 */
	protected ?Editor $switchereditor = null;

	/**
	 * Name of the currently active underlying editor plugin (e.g. "none", "tinymce").
	 *
	 * @var string
	 * @since 2.0.0
	 */
	protected string $switchereditorName = 'none';

	/**
	 * Cookie name used to remember the active underlying editor.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	protected string $cookiename = 'fgeditorswitchercurrent';

	/**
	 * Whether initEditor() has already run for this request.
	 *
	 * @var    boolean
	 * @since  2.3.0
	 */
	private bool $initialised = false;

	/**
	 * Whether this plugin's JS/CSS have already been handed to the
	 * WebAssetManager for the current document.
	 *
	 * @var    boolean
	 * @since  2.3.0
	 */
	private bool $assetsRegistered = false;

	/**
	 * Counter used to guarantee unique selector ids when a page contains more
	 * than one editor field.
	 *
	 * @var    integer
	 * @since  2.3.0
	 */
	private int $instanceCounter = 0;

	/**
	 * Resolved human-readable labels, keyed by editor element name.
	 *
	 * @var    array<string, string>
	 * @since  2.3.0
	 */
	private array $labelCache = [];

	/**
	 * Constructor.
	 *
	 * Deliberately does nothing beyond the parent call: see the "lazy
	 * initialisation" note in this file's header docblock. Everything that
	 * touches the request, the cookie or other plugins happens in
	 * initEditor(), which only runs when this editor is actually displayed.
	 *
	 * @param   object  $subject  The object to observe
	 * @param   array   $config   An array that holds the plugin configuration
	 *
	 * @since       2.0.0
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
	}

	/**
	 * Resolve the application object.
	 *
	 * CMSPlugin::getApplication() already falls back to Factory::getApplication()
	 * when $this->app has not been pushed in yet by the service provider, but
	 * that fallback still relies on the CMS Factory already having an
	 * application registered. This helper adds one more explicit layer of
	 * safety so a null return from getApplication() (e.g. if this plugin is
	 * ever booted in an unusually early/edge-case context) does not turn into
	 * a fatal "Call to a member function ... on null" error.
	 *
	 * @return CMSApplicationInterface
	 * @throws \RuntimeException
	 * @since 2.0.0
	 */
	protected function getApp(): CMSApplicationInterface
	{
		$app = $this->getApplication();

		if ($app === null)
		{
			$app = Factory::getApplication();
		}

		return $app;
	}

	/**
	 * Resolve the underlying editor for this request, once.
	 *
	 * Called from onDisplay() rather than from the constructor so that the
	 * cookie lookup, the fallback chain and the instantiation of another
	 * editor plugin only happen when this plugin is genuinely the active
	 * editor and is about to render something.
	 *
	 * A failure to resolve any editor at all is NOT fatal here: it leaves
	 * $switchereditor as null, and onDisplay() then renders a plain textarea
	 * instead. Throwing (as earlier versions effectively did from the
	 * constructor) meant a site with no enabled editor plugin died with a
	 * fatal error during plugin import.
	 *
	 * @return  void
	 * @since   2.3.0
	 */
	private function initEditor(): void
	{
		if ($this->initialised)
		{
			return;
		}

		$this->initialised = true;

		// $this->params is already populated by the parent constructor from
		// the plugin row (services/provider.php passes it into $config)
		// - no need to look it up again via PluginHelper::getPlugin().
		$requested = (string) $this->getApp()->getInput()->cookie->get(
			$this->cookiename,
			(string) $this->params->get('default_editor', 'none')
		);

		$editor = $this->resolveEditor($requested);

		if ($editor === null)
		{
			$this->getApp()->enqueueMessage(
				Text::_('PLG_EDITORS_FGEDITORSWITCHER_EDITORWASNOTFOUND'), 'warning');

			return;
		}

		// Only complain when something was actually asked for and could not be
		// honoured. An empty cookie/parameter silently resolving to a fallback
		// is normal first-run behaviour, not an error worth a message.
		if ($editor !== $requested && $requested !== '')
		{
			$this->getApp()->enqueueMessage(
				Text::_('PLG_EDITORS_FGEDITORSWITCHER_EDITORWASNOTFOUND'), 'warning');
		}

		$this->setSwitcherEditor($editor);
	}

	/**
	 * Check whether a given editor name is safe to switch to.
	 *
	 * The cookie that decides which underlying editor to use is
	 * client-controlled input (a browser cookie, trivially editable by the
	 * visitor). Nothing else guards against it naming this very plugin's own
	 * element ("fgeditorswitcher"): PluginHelper::isEnabled() would happily
	 * return true for it (it IS enabled, after all), and Editor::getInstance()
	 * would then hand back a wrapper pointing straight back at this class,
	 * so onDisplay() would delegate to itself. Excluding it here stops that
	 * self-reference before it can happen.
	 *
	 * @param   string  $editor  The editor element name to check.
	 *
	 * @return  bool
	 * @since   2.0.4
	 */
	private function isValidEditor(string $editor): bool
	{
		return $editor !== ''
			&& $editor !== 'fgeditorswitcher'
			&& PluginHelper::isEnabled('editors', $editor);
	}

	/**
	 * Resolve the requested editor to one that is actually safe/possible to
	 * use, falling back in stages if it isn't.
	 *
	 * The fallback order is: the requested editor, then each of
	 * self::FALLBACK_EDITORS that is enabled, then the first other enabled
	 * editor plugin found. Returns null - rather than throwing - if genuinely
	 * no usable editor plugin is enabled, so the caller can degrade to a
	 * plain textarea instead of taking the whole page down.
	 *
	 * @param   string  $requested  The editor element name to try first (from
	 *                              the cookie, or the configured default).
	 *
	 * @return  string|null  The name of an editor plugin that is safe to use,
	 *                       or null if there is none.
	 * @since   2.0.6
	 */
	private function resolveEditor(string $requested): ?string
	{
		if ($this->isValidEditor($requested))
		{
			return $requested;
		}

		foreach (self::FALLBACK_EDITORS as $candidate)
		{
			if ($this->isValidEditor($candidate))
			{
				return $candidate;
			}
		}

		foreach (PluginHelper::getPlugin('editors') as $plugin)
		{
			if ($this->isValidEditor((string) $plugin->name))
			{
				return (string) $plugin->name;
			}
		}

		return null;
	}

	/**
	 * Create the selected editor
	 *
	 * @param   string  $editor  The name of the underlying editor plugin to use (e.g. "none", "tinymce").
	 *
	 * @since 2.0.0
	 */
	protected function setSwitcherEditor(string $editor):void
	{
		$this->switchereditorName = $editor;
		$this->switchereditor     = Editor::getInstance($editor);
	}

	/**
	 * Human-readable label for an editor plugin.
	 *
	 * Each editor ships its own administrator sys.ini declaring its proper
	 * name under the conventional "PLG_EDITORS_<ELEMENT>" key (e.g.
	 * "Editor - TinyMCE" for "tinymce"), which is the same string Joomla's own
	 * Plugins manager shows. That is loaded and used here instead of
	 * ucfirst($element), which produced incorrect brand spellings such as
	 * "Tinymce", "Codemirror" or "Jce". "PLG_<ELEMENT>" is tried as a second
	 * key because some third-party editors name their string that way, and
	 * ucfirst() remains the last-resort fallback.
	 *
	 * The leading qualifier of the translated name ("Editor - ...") is
	 * stripped: inside a toolbar-sized dropdown, repeating the word "Editor"
	 * on every option is pure noise.
	 *
	 * @param   string  $element  The editor plugin element name.
	 *
	 * @return  string
	 * @since   2.3.0
	 */
	private function editorLabel(string $element): string
	{
		if (isset($this->labelCache[$element]))
		{
			return $this->labelCache[$element];
		}

		$extension = 'plg_editors_' . $element;
		$language  = $this->getApp()->getLanguage();

		// Editor sys.ini files normally live in administrator/language/<tag>/;
		// the plugin's own folder is checked too, the way CMSPlugin does.
		$language->load($extension . '.sys', JPATH_ADMINISTRATOR)
			|| $language->load($extension . '.sys', JPATH_PLUGINS . '/editors/' . $element);

		$label = '';

		foreach ([strtoupper($extension), 'PLG_' . strtoupper($element)] as $key)
		{
			$translated = Text::_($key);

			if ($translated !== $key)
			{
				$label = $translated;
				break;
			}
		}

		if ($label === '')
		{
			$label = ucfirst($element);
		}

		// "Editor - TinyMCE" -> "TinyMCE" (and the same for translated names).
		$parts = explode(' - ', $label, 2);

		if (isset($parts[1]) && trim($parts[1]) !== '')
		{
			$label = trim($parts[1]);
		}

		return $this->labelCache[$element] = $label;
	}

	/**
	 * Build the list of selectable editors.
	 *
	 * The rows returned by PluginHelper::getPlugin() come from a static cache
	 * and are shared objects, so they are only read here - never modified.
	 *
	 * @return  array  An array of HTMLHelper select options.
	 * @since   2.3.0
	 */
	private function getEditorOptions(): array
	{
		$options = [];

		foreach (PluginHelper::getPlugin('editors') as $plugin)
		{
			$element = (string) $plugin->name;

			if ($element === '' || $element === 'fgeditorswitcher')
			{
				continue;
			}

			$options[] = HTMLHelper::_('select.option', $element, $this->editorLabel($element));
		}

		return $options;
	}

	/**
	 * Create the selector of editors
	 *
	 * @param   string  $current   The name of the currently active underlying editor.
	 * @param   string  $name      The control name of the editor field this selector belongs to
	 *                             (used to build a unique id/name so multiple editor fields on
	 *                             the same page each get their own, valid, non-duplicated selector).
	 * @param   string  $editorId  The id the delegated editor puts on its own <textarea>, published
	 *                             to the JavaScript so it can read and restore that field's content.
	 *
	 * @return string
	 * @since     2.0.0
	 */
	protected function getEditorSelector(string $current, string $name = '', string $editorId = ''): string
	{
		// Register (and mark as used) the plugin's own JS/CSS assets only once
		// per page, no matter how many editor fields (and therefore how many
		// calls to this method) exist on it - both files are written generically
		// (attribute-prefix selectors/queries, per-instance config read from
		// data-* attributes) so a single copy of each handles any number of
		// selector instances.
		// onDisplay() only ever runs in an actual HTML-rendering web request,
		// so getDocument() returning something other than an HtmlDocument
		// (e.g. in a CLI or JSON/API application context) is not expected in
		// practice - but the guard is one line, and skips the CSS/JS
		// registration cleanly instead of a fatal error calling
		// getWebAssetManager() on something that doesn't have one.
		$document = $this->getApp()->getDocument();

		if (!$this->assetsRegistered && $document instanceof HtmlDocument)
		{
			$this->assetsRegistered = true;
			$wa                     = $document->getWebAssetManager();
			$wa->registerAndUseStyle('plg.editors.fgeditorswitcher', 'media/plg_fgeditorswitcher/css/fgeditorswitcher.css', ['version' => self::VERSION]);
			$wa->registerAndUseScript('plg.editors.fgeditorswitcher', 'media/plg_fgeditorswitcher/js/fgeditorswitcher.js', ['version' => self::VERSION], ['defer' => true]);
		}

		// A page can contain more than one editor field (e.g. multiple custom
		// fields using the "Editor" type). The sanitised control name alone
		// is not a reliable enough suffix: two different names can collapse
		// to the same sanitised string (e.g. "jform[a][b]" and "jform_a__b_"
		// both become "jform_a__b_"), and the same control name could
		// conceivably appear twice on one page. A per-request counter is
		// appended so every instance gets a genuinely unique id/name,
		// regardless of what the sanitised name collides with - the
		// sanitised name itself is kept purely for readability.
		$this->instanceCounter++;

		$suffix = preg_replace('/[^A-Za-z0-9_-]/', '_', $name !== '' ? $name : 'field')
			. '-' . $this->instanceCounter;

		$options      = $this->getEditorOptions();
		$confirmation = (bool) $this->params->get('confirmation', 1);
		$debug        = (bool) $this->params->get('debug', 0);
		$confirmTitle = '';
		$confirmMsg   = '';

		if ($confirmation)
		{
			$confirmTitle = Text::_('PLG_EDITORS_FGEDITORSWITCHER_CONFIRM_MESSAGE_TITLE');
			$confirmMsg   = Text::_('PLG_EDITORS_FGEDITORSWITCHER_CONFIRM_MESSAGE');
		}

		// Handing the unsaved content over to the newly selected editor needs
		// two things from PHP, and nothing else: permission (the parameter) and
		// the id of the field to read from and write back into. The confirmation
		// text is still passed even when the handover is enabled, because the
		// script falls back to asking whenever the handover could not actually
		// be performed (no storage available, content too large, unreadable
		// editor) - in that case data really would be lost, so the question is
		// warranted again.
		$preserve = (bool) $this->params->get('preserve_content', 1);

		// Only sent when the feature is on, so the string is not needlessly
		// embedded in every page.
		$restoredMsg = $preserve ? Text::_('PLG_EDITORS_FGEDITORSWITCHER_CONTENTRESTORED') : '';

		// The <select> carries no visible <label> (there is no room for one
		// next to the toolbar buttons), so it needs an explicit accessible
		// name - otherwise a screen reader announces an unlabelled combo box,
		// which fails WCAG 4.1.2. The same string doubles as the tooltip.
		$selectorLabel = Text::_('PLG_EDITORS_FGEDITORSWITCHER_SELECTEDITOR');

		// All per-instance behaviour (the confirmation text, the cookie
		// name, whether debug logging is on) is passed to the static
		// fgeditorswitcher.js via data-* attributes instead of being
		// templated directly into JavaScript source - this also means
		// everything here only needs plain HTML-attribute escaping, not
		// JS-string escaping. There is no hidden field / "current index"
		// attribute here: the script reads the select's own pre-selected
		// value directly (it already starts on the active editor), which
		// stays correct even if the editor list ever changes and lets a
		// cancelled switch be reverted to the exact right option.
		$attribs = 'class="xtd-button btn btn-secondary fg-switcher-select"'
			. ' aria-label="' . $this->escape($selectorLabel) . '"'
			. ' title="' . $this->escape($selectorLabel) . '"'
			. ' data-cookie-name="' . $this->escape($this->cookiename) . '"'
			. ' data-confirm="' . ($confirmation ? '1' : '0') . '"'
			. ' data-confirm-title="' . $this->escape($confirmTitle) . '"'
			. ' data-confirm-msg="' . $this->escape($confirmMsg) . '"'
			. ' data-debug="' . ($debug ? '1' : '0') . '"'
			. ' data-preserve="' . ($preserve ? '1' : '0') . '"'
			. ' data-editor-id="' . $this->escape($editorId) . '"'
			. ' data-editor-type="' . $this->escape($current) . '"'
			. ' data-restored-msg="' . $this->escape($restoredMsg) . '"';

		// HTML
		// The selector is always rendered inline, directly after the active
		// editor's own markup, sized to its content (not a full-width block)
		// so it never looks like an oversized standalone form row. It uses
		// the same "xtd-button btn btn-secondary" classes as the standard
		// editor-xtd buttons (Article/Image/Pagebreak/Read More/...) so it
		// automatically matches their height, colour and rounding.
		return '<!-- plg_fgeditorswitcher v' . self::VERSION . ' -->'
			. '<div class="fg-switcher-wrap" id="fgeditorswitcherSelector-' . $suffix . '">'
			. HTMLHelper::_('select.genericlist', $options, 'fgeditorswitcher-' . $suffix
				, $attribs, 'value', 'text', $current, 'fgeditorswitcher-select-' . $suffix)
			. '</div>';
	}

	/**
	 * Escape a string for use inside a double-quoted HTML attribute.
	 *
	 * @param   string  $text  The raw string.
	 *
	 * @return  string
	 * @since   2.3.0
	 */
	private function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Render a plain textarea, used when no editor plugin could be resolved.
	 *
	 * Returning an empty string in that situation (as earlier versions did)
	 * left the form without any input for the field: the content could not be
	 * edited and, because nothing was submitted for that control, saving the
	 * record could wipe the stored value. A bare textarea keeps the field
	 * editable and the data intact.
	 *
	 * @param   string       $name     The control name.
	 * @param   string       $content  The contents of the text area.
	 * @param   string       $width    The width of the text area (px or %).
	 * @param   string       $height   The height of the text area (px or %).
	 * @param   integer      $col      The number of columns for the textarea.
	 * @param   integer      $row      The number of rows for the textarea.
	 * @param   string|null  $id       An optional ID for the textarea.
	 *
	 * @return  string  HTML
	 * @since   2.3.0
	 */
	private function getFallbackTextarea(string $name, string $content, string $width, string $height, int $col, int $row, ?string $id): string
	{
		$style = '';

		if ($width !== '')
		{
			$style .= 'width:' . $width . ';';
		}

		if ($height !== '')
		{
			$style .= 'height:' . $height . ';';
		}

		return '<textarea name="' . $this->escape($name) . '"'
			. ' id="' . $this->escape(($id !== null && $id !== '') ? $id : $name) . '"'
			. ' cols="' . max(1, $col) . '" rows="' . max(1, $row) . '"'
			. ' class="form-control"'
			. ($style !== '' ? ' style="' . $this->escape($style) . '"' : '')
			. '>' . htmlspecialchars($content, ENT_COMPAT, 'UTF-8') . '</textarea>';
	}

	/**
	 * Initialises the Editor.
	 *
	 * In current Joomla versions, Editor::initialise() is deprecated in favour
	 * of loading assets inside display() itself, which the underlying editor
	 * already does via Editor::display(). This method intentionally no longer
	 * calls it - Joomla core still invokes onInit() on the active editor
	 * plugin as part of the legacy interface, so the method is kept (an empty
	 * implementation), just without forwarding to the deprecated call. It also
	 * deliberately does not trigger initEditor(): resolving the underlying
	 * editor is left to onDisplay(), the only place that actually needs it.
	 *
	 * @return  void
	 *
	 * @since 2.0.0
	 */
	public function onInit():void
	{
	}


	/**
	 * Display the editor area.
	 *
	 * @param   string   $name     The control name.
	 * @param   string   $content  The contents of the text area.
	 * @param   string   $width    The width of the text area (px or %).
	 * @param   string   $height   The height of the text area (px or %).
	 * @param   int      $col      The number of columns for the textarea.
	 * @param   int      $row      The number of rows for the textarea.
	 * @param   boolean  $buttons  True and the editor buttons will be displayed.
	 * @param   string   $id       An optional ID for the textarea. If not supplied the name is used.
	 * @param   string   $asset    Not used.
	 * @param   object   $author   Not used.
	 * @param   array    $params   Associative array of editor parameters.
	 *
	 * @return  string  HTML
	 * @since 2.0.0
	 */
	public function onDisplay($name, $content, $width, $height, $col, $row, $buttons = true, $id = null, $asset = null, $author = null, $params = array()): string
	{
		$this->initEditor();

		if ($this->switchereditor === null)
		{
			// No editor plugin is usable at all - degrade to a plain textarea
			// rather than rendering nothing. A switcher would be pointless
			// here, since there is nothing to switch between.
			return $this->getFallbackTextarea((string) $name, (string) $content, (string) $width,
				(string) $height, (int) $col, (int) $row, $id);
		}

		// The id the delegated editor will use for its <textarea>. Joomla's own
		// editor providers derive it as "$id ?: $name" and do not sanitise it
		// any further, so the exact same rule is applied here rather than
		// guessed at - the JavaScript looks the editor instance up by this id,
		// and a mismatch would silently disable the content handover.
		$editorId = ($id !== null && $id !== '') ? (string) $id : (string) $name;

		//Display the specified editor and EditorSelector
		return $this->switchereditor->display($name, $content, $width, $height, $col, $row, $buttons, $id, $asset, $author, $params)
			. $this->getEditorSelector($this->switchereditorName, $name, $editorId);
	}
}
