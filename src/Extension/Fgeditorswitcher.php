<?php
/**
 * @package       Joomla.Plugin
 * @subpackage    Editors.fgeditorswitcher
 * @version       2.2.2
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
 *    The JS finds each selector's toolbar by walking backwards through DOM
 *    siblings from that selector's own wrapper (which PHP always renders
 *    directly after the editor's own markup) rather than pairing the n-th
 *    selector with the n-th ".editor-xtd-buttons" found anywhere on the
 *    page - a page can have unrelated toolbars (an editor in a modal, a
 *    field from another component, a hidden subform), and pairing by
 *    position alone could move a selector next to the wrong editor.
 *  - getEditorSelector() builds a unique id/name suffix for every instance
 *    (a sanitised version of the editor field's own control name, plus a
 *    static per-request counter that actually guarantees uniqueness - the
 *    sanitised name alone can collide), so multiple editor fields on the
 *    same admin page each get their own valid, non-duplicated selector
 *    instead of only the first one working. The JS/CSS assets are
 *    registered once per page via WebAssetManager regardless of how many
 *    fields exist.
 *  - Per-instance behaviour (confirmation text, the cookie name, debug
 *    logging) is passed to the static media/js/fgeditorswitcher.js via
 *    data-* attributes rather than being templated into inline JavaScript,
 *    so the JS is a plain cacheable asset and PHP only needs to do
 *    HTML-attribute escaping.
 *  - The cookie is written with "path=/" and "Secure" (on HTTPS) so the
 *    remembered editor choice does not depend on which admin URL it was set
 *    from. It is deliberately a session cookie (no persistent expiry).
 *  - The constructor deliberately does no work beyond parent::__construct().
 *    This plugin has to be the site's configured default editor to function
 *    at all, which means Joomla constructs it for every editor field
 *    rendered anywhere (front-end or back-end), not only when this
 *    switcher's own UI actually shows. Reading the cookie, resolving the
 *    underlying editor, and instantiating its Editor::getInstance() wrapper
 *    are deferred to initEditor() (called from onInit()/onDisplay(),
 *    guarded so it only runs once per request) - avoiding unnecessary work
 *    (and a nested Editor::getInstance() call mid-bootstrap) on pages/fields
 *    that never actually call onDisplay() on this plugin, and avoiding a
 *    RuntimeException from resolveEditor() propagating out of the
 *    constructor into Joomla's own plugin-boot machinery.
 *  - If genuinely no usable editor plugin is enabled at all,
 *    onDisplay() renders a plain <textarea> instead of an empty string.
 *    Returning '' would silently drop the field from the form (no input,
 *    nothing submitted, risking existing content being wiped on save) - a
 *    bare textarea keeps the field present and editable, just without any
 *    toolbar/WYSIWYG, in this hopefully-rare misconfigured state.
 *  - getEditorSelector() builds its own fresh array of select.option()
 *    results rather than writing a "text" property directly onto the
 *    objects PluginHelper::getPlugin('editors') returns. Those are
 *    references into PluginHelper's own internal static cache (not copies),
 *    so mutating one persists for the rest of the request and could leak
 *    into any other code (another plugin, PluginsField, EditorsField...)
 *    that reads the same cached plugin list afterwards.
 */


namespace FG\Plugin\Editors\Fgeditorswitcher\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
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
	 * Plugin version, used for the WebAssetManager cache-busting query
	 * string and the diagnostic HTML comment. Kept as a single constant so a
	 * version bump only has to touch this line (plus the header docblock and
	 * the manifest's own <version> tag, which is a separate XML file and
	 * can't reference a PHP constant).
	 *
	 * @var    string
	 * @since  2.1.0
	 */
	private const VERSION = '2.2.2';

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
	 * Whether initEditor() has already run for this instance. The actual
	 * cookie-reading / editor-resolution work is deferred to onInit()/
	 * onDisplay() (see initEditor()) instead of running in the constructor,
	 * so this guards against doing it twice if both get called.
	 *
	 * @var bool
	 * @since 2.1.2
	 */
	private bool $initialised = false;

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
	 * Deliberately does nothing beyond the parent constructor. This plugin,
	 * to work at all, must be configured as the site's default editor - which
	 * means Joomla boots it (constructs this class) for every editor field
	 * rendered anywhere, front-end or back-end, not just when this switcher's
	 * own UI is actually shown. Reading the cookie, resolving which
	 * underlying editor to use, and instantiating that editor's own
	 * Editor::getInstance() wrapper are all deferred to initEditor() (called
	 * from onInit()/onDisplay() instead), so that work only happens for
	 * fields that actually get displayed through this plugin, and only once
	 * each request even if both onInit() and onDisplay() run.
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
	}

	/**
	 * Resolve and set up the underlying editor, once per request.
	 *
	 * See the constructor's docblock for why this isn't done there. A
	 * RuntimeException from resolveEditor() (genuinely no usable editor
	 * plugin enabled at all) is caught here rather than left to propagate:
	 * letting it bubble up out of onInit()/onDisplay() - which Joomla may
	 * call while it is itself in the middle of importing/booting plugins -
	 * would turn an already-unlikely misconfiguration into a fatal error /
	 * blank page. Catching it here just leaves $this->switchereditor null,
	 * which onDisplay() already handles by returning an empty string.
	 *
	 * @return  void
	 * @since   2.1.2
	 */
	private function initEditor(): void
	{
		if ($this->initialised)
		{
			return;
		}

		$this->initialised = true;

		$requested = (string) $this->getApp()->getInput()->cookie->get(
			$this->cookiename, (string) $this->params->get('default_editor', 'none')
		);

		try
		{
			$editor = $this->resolveEditor($requested);
		}
		catch (\RuntimeException $e)
		{
			return;
		}

		// Only warn when a genuine fallback happened (the requested editor
		// existed but wasn't usable) - not for the ordinary case of an empty
		// cookie value resolving to the configured default, which is not an
		// error.
		if ($editor !== $requested && $requested !== '')
		{
			$this->getApp()->enqueueMessage(
				Text::_('PLG_EDITORS_FGEDITORSWITCHER_EDITORWASNOTFOUND'), 'warning');
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
		//
		// Registered ad hoc via registerAndUseStyle()/registerAndUseScript()
		// with a direct path (not via a media/joomla.asset.json registry
		// file + useStyle()/useScript() by name - that was tried in 2.2.1 and
		// reverted: it broke asset loading in real-world testing, and this
		// direct approach is the one that has actually been verified working
		// across every admin template/editor combination tested so far).
		static $assetsRegistered = false;

		if (!$assetsRegistered)
		{
			$assetsRegistered = true;
			$wa               = $this->getApp()->getDocument()->getWebAssetManager();
			$wa->registerAndUseStyle('plg.editors.fgeditorswitcher', 'media/plg_fgeditorswitcher/css/fgeditorswitcher.css', ['version' => self::VERSION]);
			$wa->registerAndUseScript('plg.editors.fgeditorswitcher', 'media/plg_fgeditorswitcher/js/fgeditorswitcher.js', ['version' => self::VERSION], ['defer' => true]);
		}

		// A page can contain more than one editor field (e.g. multiple custom
		// fields using the "Editor" type). The sanitised control name alone
		// is not a reliable enough suffix: two different names can collapse
		// to the same sanitised string (e.g. "jform[a][b]" and "jform_a__b_"
		// both become "jform_a__b_"), and the same control name could
		// conceivably appear twice on one page. A static per-request counter
		// is appended so every instance gets a genuinely unique id/name,
		// regardless of what the sanitised name collides with - the
		// sanitised name itself is kept purely for readability.
		static $instance = 0;
		$instance++;

		$suffix = preg_replace('/[^A-Za-z0-9_-]/', '_', $name !== '' ? $name : 'field') . '-' . $instance;

		// PluginHelper::getPlugin() returns references to objects held in its
		// own internal static cache (not copies) - writing a new property
		// onto one of them, as an earlier version of this method did
		// ("$o->text = ..."), mutates that shared cache for the rest of the
		// request, potentially visible to any other code (another plugin,
		// PluginsField, EditorsField...) that reads it afterwards. Building a
		// fresh array of select.option() results instead never touches the
		// cached objects at all.
		$options = [];

		foreach (PluginHelper::getPlugin('editors') as $o)
		{
			if ($o->name === 'fgeditorswitcher')
			{
				continue;
			}

			$options[] = HTMLHelper::_('select.option', $o->name, ucfirst($o->name));
		}

		$confirmation = (bool) $this->params->get('confirmation', 1);
		$debug        = (bool) $this->params->get('debug', 0);
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
		$editorSelectorLabel = htmlspecialchars(Text::_('PLG_EDITORS_FGEDITORSWITCHER_SELECTEDITOR'), ENT_QUOTES, 'UTF-8');

		$attribs = 'class="xtd-button btn btn-secondary fg-switcher-select"'
			. ' aria-label="' . $editorSelectorLabel . '"'
			. ' title="' . $editorSelectorLabel . '"'
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
		// Layout comes from the "fg-switcher-wrap"/"fg-switcher-select" CSS
		// classes (fgeditorswitcher.css), not inline style="..." attributes -
		// a site running Joomla's CSP system plugin with a strict style-src
		// (no 'unsafe-inline') would otherwise have these attributes
		// stripped, breaking the layout. The many colours/positions set at
		// runtime via JS's element.style API are unaffected either way - CSP
		// only restricts inline style markup and <style> blocks, not
		// programmatic CSSOM writes.
		return '<!-- plg_fgeditorswitcher v' . self::VERSION . ' -->'
			. '<div id="fgeditorswitcherSelector-' . $suffix . '" class="fg-switcher-wrap">'
			. HTMLHelper::_('select.genericlist', $options, 'fgeditorswitcher-' . $suffix
				, $attribs, 'value', 'text', $current, 'fgeditorswitcher-select-' . $suffix)
			. '</div>';
	}

	/**
	 * Initialises the Editor.
	 *
	 * In current Joomla versions, Editor::initialise() is deprecated in favour
	 * of loading assets inside display() itself, which the underlying editor
	 * already does via Editor::display(). This method intentionally no longer
	 * calls it - Joomla core still invokes onInit() on the active editor
	 * plugin as part of the legacy interface, so it is kept, now only to
	 * trigger the lazy initEditor() (see its own docblock for why that isn't
	 * done in the constructor).
	 *
	 * @return  void
	 *
	 * @since 2.0.0
	 */
	public function onInit():void
	{
		$this->initEditor();
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
			// Genuinely no usable editor plugin is enabled at all (see
			// resolveEditor()/initEditor()) - returning an empty string here
			// would silently drop the field from the form entirely: no input
			// means nothing gets submitted, which on save can wipe out
			// existing content for this field. A plain, unstyled <textarea>
			// is a safe degraded mode: the field stays present and editable
			// (as raw HTML, with no toolbar/WYSIWYG) even in this
			// misconfigured, hopefully rare situation.
			if (empty($id))
			{
				$id = $name;
			}

			return sprintf(
				'<textarea name="%s" id="%s" cols="%d" rows="%d" style="width:%s;height:%s;">%s</textarea>',
				htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
				(int) $col,
				(int) $row,
				htmlspecialchars((string) $width, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars((string) $height, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars($content, ENT_QUOTES, 'UTF-8')
			);
		}

		//Display the specified editor and EditorSelector
		return $this->switchereditor->display($name, $content, $width, $height, $col, $row, $buttons, $id, $asset, $author, $params)
			. $this->getEditorSelector($this->switchereditorName, $name);
	}
}
