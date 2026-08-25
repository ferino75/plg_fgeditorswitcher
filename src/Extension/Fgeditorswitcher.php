<?php
/**
 * @package       Joomla.Plugin
 * @subpackage    Editors.fgeditorswitcher
 * @version       2.0.7
 *
 * @copyright     (C) 2026 Fero
 * @license       https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 *
 * @author        Fero
 * @link          https://ferino75.github.io/
 *
 * Implementation notes (the "why" behind a few non-obvious decisions):
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
 *  - getEditorSelector() builds a unique id/name suffix from the editor
 *    field's own control name, so multiple editor fields on the same admin
 *    page each get their own valid, non-duplicated selector instead of only
 *    the first one working. The JS/CSS assets are registered once per page
 *    via WebAssetManager regardless of how many fields exist.
 *  - Per-instance behaviour (confirmation text, the cookie name, debug
 *    logging) is passed to the static media/js/fgeditorswitcher.js via
 *    data-* attributes rather than being templated into inline JavaScript,
 *    so the JS is a plain cacheable asset and PHP only needs to do
 *    HTML-attribute escaping.
 *  - The cookie is written with "path=/" and "Secure" (on HTTPS) so the
 *    remembered editor choice does not depend on which admin URL it was set
 *    from. It is deliberately a session cookie (no persistent expiry).
 */


namespace FG\Plugin\Editors\Fgeditorswitcher\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use Joomla\CMS\Application\CMSApplicationInterface;
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
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  2.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Editor wrapper for the currently active underlying editor.
	 *
	 * @var Joomla\CMS\Editor\Editor|null
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
	 * Previously, an invalid requested editor fell straight back to
	 * PluginHelper::getPlugin('editors', 'none') unconditionally, assuming
	 * that plugin exists and is enabled - true in practice, but an admin can
	 * disable it, in which case $plugin would be a falsy/empty result and
	 * $plugin->name would no longer be safe to read. This widens the fallback
	 * to: the requested editor, then "none" (if enabled), then the first
	 * other enabled editor plugin found, only failing outright if genuinely
	 * none is available at all.
	 *
	 * @param   string  $requested  The editor element name to try first (from
	 *                              the cookie, or the configured default).
	 *
	 * @return  string  The name of an editor plugin that is safe to use.
	 * @throws  \RuntimeException  If no usable editor plugin is enabled at all.
	 * @since   2.0.6
	 */
	private function resolveEditor(string $requested): string
	{
		if ($this->isValidEditor($requested))
		{
			return $requested;
		}

		if ($this->isValidEditor('none'))
		{
			return 'none';
		}

		foreach (PluginHelper::getPlugin('editors') as $plugin)
		{
			if ($plugin->name !== 'fgeditorswitcher')
			{
				return $plugin->name;
			}
		}

		throw new \RuntimeException('FG Editor Switcher: no usable editor plugin is enabled.');
	}

	/**
	 * Constructor
	 *
	 * @param   object  $subject  The object to observe
	 * @param   array   $config   An array that holds the plugin configuration
	 *
	 * @throws Exception
	 * @since       2.0.0
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$reg = new Registry(PluginHelper::getPlugin('editors', 'fgeditorswitcher')->params);
		$requested = $this->getApp()->getInput()->cookie->get($this->cookiename,
			$reg->get('default_editor', 'none'));

		$editor = $this->resolveEditor($requested);

		if ($editor !== $requested)
		{
			$this->getApp()->enqueueMessage(
				Text::_('PLG_EDITORS_FGEDITORSWITCHER_EDITORWASNOTFOUND'), 'error');
		}

		$this->setSwitcherEditor($editor);
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
	 * Create the selector of editors
	 *
	 * @param   string  $current  The name of the currently active underlying editor.
	 * @param   string  $name     The control name of the editor field this selector belongs to
	 *                            (used to build a unique id/name so multiple editor fields on
	 *                            the same page each get their own, valid, non-duplicated selector).
	 *
	 * @return string
	 * @since     2.0.0
	 */
	protected function getEditorSelector(string $current, string $name = ''): string
	{
		// Register (and mark as used) the plugin's own JS/CSS assets only once
		// per page, no matter how many editor fields (and therefore how many
		// calls to this method) exist on it - both files are written generically
		// (attribute-prefix selectors/queries, per-instance config read from
		// data-* attributes) so a single copy of each handles any number of
		// selector instances.
		static $assetsRegistered = false;

		if (!$assetsRegistered)
		{
			$assetsRegistered = true;
			$wa               = $this->getApp()->getDocument()->getWebAssetManager();
			$wa->registerAndUseStyle('plg.editors.fgeditorswitcher', 'media/plg_fgeditorswitcher/css/fgeditorswitcher.css', ['version' => '2.0.7']);
			$wa->registerAndUseScript('plg.editors.fgeditorswitcher', 'media/plg_fgeditorswitcher/js/fgeditorswitcher.js', ['version' => '2.0.7'], ['defer' => true]);
		}

		// A page can contain more than one editor field (e.g. multiple custom
		// fields using the "Editor" type). Build an id/name suffix from this
		// field's own control name so every instance gets its own valid,
		// non-duplicated ids.
		$suffix = preg_replace('/[^A-Za-z0-9_-]/', '_', $name !== '' ? $name : uniqid('fgeditorswitcher_'));

		$params  = new Registry(PluginHelper::getPlugin('editors', 'fgeditorswitcher')->params);
		$editors = PluginHelper::getPlugin('editors');

		foreach ($editors as $k => $o)
		{
			if ($o->name == 'fgeditorswitcher')
			{
				unset($editors[$k]);
				continue;
			}

			$o->text = ucfirst($o->name);
		}

		$confirmation = (bool) $params->get('confirmation', 1);
		$debug        = (bool) $params->get('debug', 0);
		$confirmTitle = '';
		$confirmMsg   = '';

		if ($confirmation)
		{
			$confirmTitle = Text::_('PLG_EDITORS_FGEDITORSWITCHER_CONFIRM_MESSAGE_TITLE');
			$confirmMsg   = Text::_('PLG_EDITORS_FGEDITORSWITCHER_CONFIRM_MESSAGE');
		}

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
		$attribs = 'class="xtd-button btn btn-secondary" style="width:auto;"'
			. ' data-cookie-name="' . htmlspecialchars($this->cookiename, ENT_QUOTES, 'UTF-8') . '"'
			. ' data-confirm="' . ($confirmation ? '1' : '0') . '"'
			. ' data-confirm-title="' . htmlspecialchars($confirmTitle, ENT_QUOTES, 'UTF-8') . '"'
			. ' data-confirm-msg="' . htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8') . '"'
			. ' data-debug="' . ($debug ? '1' : '0') . '"';

		// HTML
		// The selector is always rendered inline, directly after the active
		// editor's own markup, sized to its content (not a full-width block)
		// so it never looks like an oversized standalone form row. It uses
		// the same "xtd-button btn btn-secondary" classes as the standard
		// editor-xtd buttons (Article/Image/Pagebreak/Read More/...) so it
		// automatically matches their height, colour and rounding.
		return '<!-- plg_fgeditorswitcher v2.0.7 -->'
			. '<div id="fgeditorswitcherSelector-' . $suffix . '" style="display:inline-flex;align-items:center;gap:.4rem;max-width:100%;">'
			. HTMLHelper::_('select.genericlist', $editors, 'fgeditorswitcher-' . $suffix
				, $attribs, 'name', 'text', $current, 'fgeditorswitcher-select-' . $suffix)
			. '</div>';
	}

	/**
	 * Initialises the Editor.
	 *
	 * In current Joomla versions, Editor::initialise() is deprecated in favour
	 * of loading assets inside display() itself, which the underlying editor
	 * already does via Editor::display(). This method intentionally no longer
	 * calls it - Joomla core still invokes onInit() on the active editor
	 * plugin as part of the legacy interface, so the method is kept (an empty
	 * implementation), just without forwarding to the deprecated call.
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
		if ($this->switchereditor === null)
		{
			return '';
		}

		//Display the specified editor and EditorSelector
		return $this->switchereditor->display($name, $content, $width, $height, $col, $row, $buttons, $id, $asset, $author, $params)
			. $this->getEditorSelector($this->switchereditorName, $name);
	}
}
