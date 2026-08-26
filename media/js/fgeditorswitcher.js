/**
 * plg_fgeditorswitcher
 *
 * Behaviour for the editor switcher selector(s). This is a static, cacheable,
 * minifiable file loaded via Joomla's WebAssetManager. Per-instance
 * configuration (the confirmation dialog text, the cookie name, etc.) is
 * read from data-* attributes on each <select> rather than being templated
 * into the script itself, so the same file works unchanged for any number of
 * editor fields on the page and needs no per-request regeneration.
 *
 * Three assumptions of the earlier versions are gone as of 2.3.0, because an
 * admin form is not the static document they assumed:
 *
 *  1. Selectors are no longer paired with toolbars by their position in the
 *     document. Each selector now looks for the toolbar belonging to its own
 *     editor, starting from its own wrapper.
 *  2. Setup no longer happens only once, at DOMContentLoaded. It is
 *     idempotent and re-runs for subform rows added later, for editors that
 *     build their toolbar asynchronously, and for forms loaded by AJAX.
 *  3. A <select> fires "change" on every arrow key in Chromium-based
 *     browsers, so keyboard changes are now debounced instead of reloading
 *     the page under the user's fingers.
 *
 * Since 2.3.0 the file also carries the unsaved content of every editor field
 * across the reload that a switch triggers. Two things make that work, and
 * both are worth knowing before changing anything here:
 *
 *  1. Reading the live value goes through Joomla.editors.instances[id]. That
 *     is the only lookup available to a classic (non-module) script on every
 *     supported Joomla version: the modern JoomlaEditor API is an ES module
 *     export ("editor-api") with no global counterpart, and importing it would
 *     break the whole file on Joomla versions whose import map does not know
 *     that specifier. Since Joomla 5.2 JoomlaEditor.register() mirrors the
 *     very same decorator object into Joomla.editors.instances for backward
 *     compatibility, so one code path covers Joomla 4, 5 and 6. The cost is a
 *     single deprecation warning in the browser console per switch; migrating
 *     to a proper import belongs with the move to EditorProviderInterface,
 *     where this plugin gets an ES module entry point anyway.
 *
 *  2. Restoring the value writes into the field's <textarea> BEFORE any
 *     editor initialises, which is deliberate rather than accidental: this
 *     asset is registered before the delegated editor's assets, so its
 *     <script> comes first in the head, and deferred classic scripts run in
 *     document order together with module scripts (which is what tinymce.js
 *     and joomla-editor-codemirror.js are) once parsing has finished. At that
 *     point the <textarea> exists but no editor has started, and since every
 *     editor takes its initial content from the textarea, the new editor comes
 *     up already holding the right value - no polling, no waiting for an
 *     instance to appear. setValue() is still attempted as a safety net for
 *     the case where an instance somehow already exists.
 */
(function () {
	'use strict';

	// Take scroll restoration over from the browser, which restores at a point
	// of its own choosing and would then fight with the restore below. Done at
	// the very top of the file, before anything - browser or this script - has
	// had a chance to restore anything.
	try
	{
		if ('scrollRestoration' in history)
		{
			history.scrollRestoration = 'manual';
		}
	}
	catch (e)
	{
		// Some browsers throw here in certain contexts (sandboxed iframes, for
		// one). Losing manual control only means falling back to the browser's
		// own behaviour, not a functional break.
	}

	// One key holds the whole handover: the per-field content and the scroll
	// position, with the URL and a timestamp used to validate it. A single key
	// means a single lifetime and a single point of deletion, instead of
	// separate keys that could get out of sync.
	var HANDOFF_KEY = 'plg_fgeditorswitcher_handoff';

	// A handover is only ever meant to survive one immediate reload. Anything
	// older is stale by definition, so it is discarded rather than applied.
	var HANDOFF_MAX_AGE_MS = 60000;

	// Guard against filling up the tab's storage quota (typically ~5 MB) with
	// one enormous article. Measured on the serialised payload; when it does
	// not fit, the switch falls back to the old confirm-and-lose behaviour
	// rather than failing silently.
	var HANDOFF_MAX_CHARS = 4 * 1024 * 1024;

	// Class-based, not the [id^="fgeditorswitcher-select-"] prefix match used
	// before: the ids are built by PHP and this file should not have to know
	// how.
	var SELECT_QUERY = 'select.fg-switcher-select';
	var WRAP_QUERY = '.fg-switcher-wrap';
	var TOOLBAR_QUERY = '.editor-xtd-buttons';

	// How long to wait for keyboard browsing of the list to settle before
	// treating the selected value as the user's actual choice.
	var KEY_DEBOUNCE_MS = 600;

	// Editors that build their toolbar asynchronously get a grace period in
	// which the observer keeps retrying the relocation. After that the retries
	// stop, so that ordinary DOM churn - TinyMCE mutates its document on every
	// keystroke - does not keep re-scanning the page for the rest of the
	// session. Selectors added later still get set up, because that is
	// triggered by nodes actually being added, not by the retry window.
	var RELOCATE_WINDOW_MS = 10000;

	var startedAt = Date.now();

	// Selectors that are set up but still waiting for a toolbar to appear.
	var awaitingToolbar = [];

	var scheduled = false;

	// Scroll position taken out of the handover payload, applied once the DOM
	// is ready. The payload itself is consumed as early as possible (see
	// restoreHandoff), which is well before it makes sense to scroll.
	var pendingScrollY = null;

	/**
	 * Return the <textarea> (or whatever element) a given editor field uses,
	 * or null.
	 *
	 * @param {string} editorId
	 *
	 * @returns {HTMLElement|null}
	 */
	function getEditorElement(editorId)
	{
		if (!editorId)
		{
			return null;
		}

		var el = document.getElementById(editorId);

		return (el && typeof el.value === 'string') ? el : null;
	}

	/**
	 * Return the editor instance (a JoomlaEditorDecorator on Joomla 5.2+, a
	 * bare object with the same getValue/setValue methods on older versions)
	 * registered for a field id, or null.
	 *
	 * Wrapped in try/catch because Joomla.editors.instances is a Proxy whose
	 * get() handler is core code we do not control.
	 *
	 * @param {string} editorId
	 *
	 * @returns {Object|null}
	 */
	function getEditorInstance(editorId)
	{
		try
		{
			if (window.Joomla && Joomla.editors && Joomla.editors.instances)
			{
				return Joomla.editors.instances[editorId] || null;
			}
		}
		catch (e)
		{
			// Nothing usable - the caller falls back to the textarea.
		}

		return null;
	}

	/**
	 * Read the current content of one editor field.
	 *
	 * The editor instance is asked first: TinyMCE and CodeMirror only write
	 * their content back into the underlying <textarea> when the form is
	 * submitted, so the textarea is stale while the user is typing. It is
	 * still the correct source for "Editor - None", for the fallback textarea,
	 * and for any editor that did not register an instance.
	 *
	 * @param {string} editorId
	 *
	 * @returns {string|null} null when the field could not be read at all.
	 */
	function readEditorValue(editorId)
	{
		var instance = getEditorInstance(editorId);

		if (instance && typeof instance.getValue === 'function')
		{
			try
			{
				var value = instance.getValue();

				if (typeof value === 'string')
				{
					return value;
				}
			}
			catch (e)
			{
				// Fall through to the textarea below.
			}
		}

		var el = getEditorElement(editorId);

		return el ? el.value : null;
	}

	/**
	 * Push content into one editor field.
	 *
	 * The <textarea> is written first and unconditionally, because that is
	 * what a not-yet-initialised editor will pick its starting content up
	 * from. The input/change events are dispatched so that anything watching
	 * the field - character counters, unsaved-changes detectors, other
	 * plugins - sees the new value instead of silently disagreeing with it.
	 *
	 * @param {string} editorId
	 * @param {string} value
	 *
	 * @returns {boolean} Whether anything was actually changed.
	 */
	function writeEditorValue(editorId, value)
	{
		var changed = false;
		var el = getEditorElement(editorId);

		if (el && el.value !== value)
		{
			el.value = value;
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
			changed = true;
		}

		var instance = getEditorInstance(editorId);

		if (instance && typeof instance.setValue === 'function')
		{
			try
			{
				if (typeof instance.getValue !== 'function' || instance.getValue() !== value)
				{
					instance.setValue(value);
					changed = true;
				}
			}
			catch (e)
			{
				// The textarea write above is the one that matters.
			}
		}

		return changed;
	}

	/**
	 * Read the content of every editor field on the page.
	 *
	 * Every field is collected, not just the one being switched: the reload
	 * discards unsaved changes in all of them, so preserving only the switched
	 * field would still lose data on a page with several editor fields.
	 *
	 * Read-only and disabled fields are skipped - their content cannot have
	 * been changed, and the server will render it again anyway.
	 *
	 * If any field cannot be read the whole handover is abandoned (null),
	 * because a partial handover is worse than none: it would silently drop
	 * one field's content while suppressing the warning that says so.
	 *
	 * @returns {Object|null}
	 */
	function collectFields()
	{
		var selects = document.querySelectorAll(SELECT_QUERY);
		var fields = {};
		var n;

		for (n = 0; n < selects.length; n++)
		{
			var editorId = selects[n].getAttribute('data-editor-id');

			if (!editorId)
			{
				return null;
			}

			var el = getEditorElement(editorId);

			if (el && (el.readOnly || el.disabled))
			{
				continue;
			}

			var value = readEditorValue(editorId);

			if (value === null)
			{
				return null;
			}

			fields[editorId] = value;
		}

		return fields;
	}

	/**
	 * Write the handover payload to sessionStorage.
	 *
	 * sessionStorage rather than a cookie or localStorage: the data must
	 * survive exactly one reload, must not be sent to the server with every
	 * request, must not leak into another tab editing another record, and must
	 * disappear when the tab closes.
	 *
	 * @param {Object|null} fields   Per-field content, or null for a scroll-only payload.
	 * @param {number}      scrollY  The scroll position to restore after the reload.
	 *
	 * @returns {boolean} Whether the payload (including its fields) was stored.
	 */
	function storeHandoff(fields, scrollY)
	{
		var payload = {
			url: window.location.href,
			ts: Date.now(),
			scrollY: scrollY,
			fields: fields || {}
		};

		var serialised;

		try
		{
			serialised = JSON.stringify(payload);
		}
		catch (e)
		{
			return false;
		}

		if (serialised.length > HANDOFF_MAX_CHARS)
		{
			return false;
		}

		try
		{
			window.sessionStorage.setItem(HANDOFF_KEY, serialised);
		}
		catch (e)
		{
			// Quota exceeded, or storage unavailable entirely (private
			// browsing modes in some browsers). Either way the content cannot
			// be carried across, and the caller has to say so.
			return false;
		}

		return true;
	}

	/**
	 * Read and immediately delete the handover payload.
	 *
	 * The delete happens before any validation and before anything is applied,
	 * so a malformed or oversized payload can never be retried in a loop, and
	 * a manual reload later cannot resurrect old content.
	 *
	 * @returns {Object|null}
	 */
	function takeHandoff()
	{
		var raw;

		try
		{
			raw = window.sessionStorage.getItem(HANDOFF_KEY);
			window.sessionStorage.removeItem(HANDOFF_KEY);
		}
		catch (e)
		{
			return null;
		}

		if (!raw)
		{
			return null;
		}

		var payload;

		try
		{
			payload = JSON.parse(raw);
		}
		catch (e)
		{
			return null;
		}

		if (!payload || typeof payload !== 'object')
		{
			return null;
		}

		// Only ever applied to the very page it was captured on, and only for
		// as long as an immediate reload could plausibly take.
		if (payload.url !== window.location.href)
		{
			return null;
		}

		if (typeof payload.ts !== 'number' || (Date.now() - payload.ts) > HANDOFF_MAX_AGE_MS)
		{
			return null;
		}

		return payload;
	}

	/**
	 * Tell the user that content was carried over.
	 *
	 * This is not decoration. Without it the user sees their unsaved text
	 * sitting in the new editor and can reasonably conclude that it was
	 * saved - it was not, and losing it to a closed tab would be a far worse
	 * outcome than the old behaviour of refusing to switch.
	 *
	 * @param {string} message
	 *
	 * @returns {void}
	 */
	function announceRestore(message)
	{
		if (!message)
		{
			return;
		}

		try
		{
			if (window.Joomla && typeof Joomla.renderMessages === 'function')
			{
				Joomla.renderMessages({ notice: [message] });
			}
		}
		catch (e)
		{
			// A missing message is not worth breaking the restore over.
		}
	}

	/**
	 * Apply the handover payload left behind by the switch that reloaded this
	 * page: put the content back into every field and remember the scroll
	 * position for later.
	 *
	 * Called at script evaluation time rather than on DOMContentLoaded, so
	 * that the textarea writes land before the editors initialise.
	 *
	 * @returns {void}
	 */
	function restoreHandoff()
	{
		var payload = takeHandoff();

		if (!payload)
		{
			return;
		}

		if (typeof payload.scrollY === 'number')
		{
			pendingScrollY = payload.scrollY;
		}

		var fields = payload.fields;

		if (!fields || typeof fields !== 'object')
		{
			return;
		}

		var selects = document.querySelectorAll(SELECT_QUERY);
		var restored = 0;
		var editorId;

		for (editorId in fields)
		{
			if (!Object.prototype.hasOwnProperty.call(fields, editorId))
			{
				continue;
			}

			if (typeof fields[editorId] !== 'string')
			{
				continue;
			}

			// Only fields that still exist on this page, and only when the
			// value actually differs from what the server rendered - writing
			// an identical value would mark the form as modified for no
			// reason.
			if (writeEditorValue(editorId, fields[editorId]))
			{
				restored++;
			}
		}

		if (restored > 0 && selects.length)
		{
			announceRestore(selects[0].getAttribute('data-restored-msg') || '');
		}
	}

	/**
	 * Find the xtd buttons toolbar belonging to this selector's own editor.
	 *
	 * PHP renders the selector's wrapper immediately AFTER the markup of the
	 * editor it belongs to, so the toolbar is inside one of the wrapper's
	 * preceding siblings. Walking from the selector outwards like this is what
	 * makes several editor fields on one page - and unrelated editors the
	 * plugin does not manage at all, e.g. one inside a modal or a subform
	 * template - land in the right place: matching the n-th selector with the
	 * n-th toolbar on the page moved selectors onto other people's editors as
	 * soon as the document order stopped matching the render order.
	 *
	 * @param {HTMLSelectElement} select
	 *
	 * @returns {HTMLElement|null}
	 */
	function findToolbarFor(select)
	{
		var wrapper = select.closest(WRAP_QUERY);

		if (!wrapper)
		{
			return null;
		}

		var prev = wrapper.previousElementSibling;

		while (prev)
		{
			var found = prev.matches(TOOLBAR_QUERY) ? prev : prev.querySelector(TOOLBAR_QUERY);

			if (found && !found.querySelector(SELECT_QUERY))
			{
				return found;
			}

			prev = prev.previousElementSibling;
		}

		// Some editors wrap their markup so that the toolbar is not a sibling
		// of the wrapper at all. Fall back to the closest container that can
		// reasonably be called "this field" and look inside it - still scoped
		// to the field, never to the whole document.
		var field = wrapper.closest('.control-group, .controls, joomla-field-fancy-select, fieldset, .card-body, joomla-field-subform > *');

		if (!field)
		{
			return null;
		}

		var candidate = field.querySelector(TOOLBAR_QUERY);

		return (candidate && !candidate.querySelector(SELECT_QUERY)) ? candidate : null;
	}

	/**
	 * Progressive enhancement: move one selector into its own editor's xtd
	 * buttons toolbar and match that toolbar's real button colours, so it
	 * looks like one of them regardless of admin template.
	 *
	 * The colours cannot come from the stylesheet - they are whatever the
	 * template computed for a real button - so they are handed to the
	 * stylesheet as custom properties instead of being written as inline
	 * colour declarations. The rule that consumes them lives in
	 * fgeditorswitcher.css and is only enabled by the class added here, so an
	 * unset variable can never strip the selector's own background.
	 *
	 * @param {HTMLSelectElement} select
	 *
	 * @returns {boolean} Whether the selector was relocated.
	 */
	function relocate(select)
	{
		var toolbar = findToolbarFor(select);

		if (!toolbar)
		{
			// No toolbar for this editor (e.g. "Editor - None" renders none).
			// The selector simply stays where PHP put it - visible and
			// working, just not inside a toolbar. It is never hidden.
			return false;
		}

		var refButton = toolbar.children.length ? toolbar.children[0] : null;

		select.classList.add('fg-switcher-select--in-toolbar');
		toolbar.appendChild(select);

		if (refButton)
		{
			var computed = getComputedStyle(refButton);
			select.style.setProperty('--fg-switcher-bg', computed.backgroundColor);
			select.style.setProperty('--fg-switcher-border', computed.borderColor);
			select.style.setProperty('--fg-switcher-fg', computed.color);
			select.classList.add('fg-switcher-select--matched');
		}

		return true;
	}

	/**
	 * Attach the change handler for a single switcher <select>.
	 *
	 * @param {HTMLSelectElement} select
	 */
	function attachSwitcher(select)
	{
		var confirmEnabled = select.getAttribute('data-confirm') === '1';
		// The currently active value, read directly off the select at attach
		// time (it always starts pre-selected to the active editor). Used
		// instead of an index so it stays correct even if the list of
		// editors ever changes, and so a cancelled switch can be reverted to
		// it below.
		var currentValue = select.value;
		var confirmTitle = select.getAttribute('data-confirm-title') || '';
		var confirmMsg = select.getAttribute('data-confirm-msg') || '';
		var cookieName = select.getAttribute('data-cookie-name');
		var preserve = select.getAttribute('data-preserve') === '1';
		var debug = select.getAttribute('data-debug') === '1';

		// Set while the user is browsing the list with the keyboard, which in
		// Chromium-based browsers fires a "change" event for every single step
		// through the options - each of which used to be taken as a decision
		// and reload the page.
		var browsingByKeyboard = false;
		var pending = null;

		if (!cookieName)
		{
			return;
		}

		if (debug)
		{
			var cookiePair = document.cookie.split('; ').filter(function (part)
			{
				return part.indexOf(cookieName + '=') === 0;
			})[0];

			// eslint-disable-next-line no-console
			console.log('[plg_fgeditorswitcher] field="' + select.id + '" editor id="'
				+ (select.getAttribute('data-editor-id') || '(none)') + '" current editor="'
				+ select.value + '" cookie="' + (cookiePair || '(not set)') + '"'
				+ ' preserve=' + (preserve ? 'on' : 'off'));
		}

		/**
		 * Actually perform the switch to the currently selected value.
		 *
		 * @returns {void}
		 */
		function commit()
		{
			pending = null;

			if (select.value === currentValue)
			{
				return;
			}

			// Try to carry the content over first. Whether that succeeded is
			// what decides if the user still needs to be warned: when the
			// content is safe there is nothing to confirm, and asking anyway
			// would be pure noise.
			var preserved = false;

			if (preserve)
			{
				var fields = collectFields();

				if (fields)
				{
					preserved = storeHandoff(fields, window.scrollY);
				}

				if (debug)
				{
					// eslint-disable-next-line no-console
					console.log('[plg_fgeditorswitcher] content handover '
						+ (preserved ? 'stored for ' + Object.keys(fields).length + ' field(s)'
							: 'not possible, falling back to the confirmation dialog'));
				}
			}

			if (!preserved && confirmEnabled && !window.confirm(confirmTitle + '\n' + confirmMsg))
			{
				// The page isn't reloading (nothing was actually switched), so
				// the <select> must be reverted by hand - otherwise it would
				// keep showing the cancelled choice while the real active
				// editor stays whatever it was.
				select.value = currentValue;
				return;
			}

			// "path=/" avoids the cookie being scoped to whichever admin URL
			// it happened to be set from; "secure" is only added when the
			// page itself is loaded over HTTPS.
			document.cookie = cookieName + '=' + select.value + '; path=/; samesite=lax'
				+ (location.protocol === 'https:' ? '; secure' : '');

			if (debug)
			{
				// eslint-disable-next-line no-console
				console.log('[plg_fgeditorswitcher] switching to "' + select.value + '", reloading...');
			}

			if (!preserved)
			{
				// The content could not be carried over (or the feature is off),
				// but the scroll position still can: a switch that loses content
				// should at least not also jump the page back to the top. The
				// same payload is reused with no fields in it, so there is still
				// only one key with one lifetime.
				storeHandoff(null, window.scrollY);
			}

			// replace() rather than reload(): reload() repeats the request
			// method of the current document, so on an edit screen that is the
			// result of a POST (e.g. after a failed save validation) the browser
			// would ask the user to resubmit the form - and could repeat the
			// original action. Replacing the location with the same URL is
			// always a plain GET, and leaves no extra history entry behind.
			window.location.replace(window.location.href);
		}

		/**
		 * Run a pending, debounced switch right now.
		 *
		 * @returns {void}
		 */
		function flush()
		{
			if (pending !== null)
			{
				window.clearTimeout(pending);
				commit();
			}
		}

		// Only key presses that move through the list start the debounce.
		// Enter, Tab and Escape are decisions (or cancellations), and a mouse
		// click on an option is unambiguous, so those act immediately - the
		// delay exists purely to absorb the intermediate values produced by
		// arrow keys, Home/End/PageUp/PageDown and type-ahead.
		select.addEventListener('keydown', function (event)
		{
			if (event.key === 'Enter' || event.key === 'Tab')
			{
				browsingByKeyboard = false;
				flush();

				return;
			}

			if (event.key === 'Escape')
			{
				browsingByKeyboard = false;

				if (pending !== null)
				{
					window.clearTimeout(pending);
					pending = null;
					select.value = currentValue;
				}

				return;
			}

			browsingByKeyboard = true;
		});

		select.addEventListener('mousedown', function ()
		{
			browsingByKeyboard = false;
		});

		// Leaving the control is a decision too: the value showing when focus
		// moves away is the one the user settled on.
		select.addEventListener('blur', flush);

		select.addEventListener('change', function ()
		{
			if (pending !== null)
			{
				window.clearTimeout(pending);
				pending = null;
			}

			if (!browsingByKeyboard)
			{
				commit();

				return;
			}

			pending = window.setTimeout(commit, KEY_DEBOUNCE_MS);
		});
	}

	/**
	 * Restore the scroll position captured just before the reload that follows
	 * switching editors, so the page stays where the user was instead of
	 * jumping back to the top.
	 *
	 * The value comes out of the handover payload, which was consumed much
	 * earlier (before the editors initialised); scrolling that early would be
	 * pointless, since the page is not laid out yet.
	 *
	 * @returns {void}
	 */
	function restoreScrollPosition()
	{
		if (pendingScrollY === null || isNaN(pendingScrollY))
		{
			return;
		}

		window.scrollTo(0, pendingScrollY);
		pendingScrollY = null;
	}

	/**
	 * Set up every selector inside a subtree that has not been set up yet, and
	 * retry the ones still waiting for their toolbar.
	 *
	 * Safe to call any number of times: each selector is flagged once its
	 * handler is attached, and the relocation is tracked separately so that a
	 * toolbar appearing later can still be used.
	 *
	 * @param {Element|Document} [root]
	 *
	 * @returns {void}
	 */
	function init(root)
	{
		var scope = root && root.querySelectorAll ? root : document;
		var found = scope.querySelectorAll(SELECT_QUERY);
		var n;

		// querySelectorAll only looks inside the root, so a root that IS a
		// selector (or the wrapper of one) would otherwise be missed.
		if (root && root.matches && root.matches(SELECT_QUERY))
		{
			setUp(root);
		}

		for (n = 0; n < found.length; n++)
		{
			setUp(found[n]);
		}

		retryRelocations();
	}

	/**
	 * Set up one selector, exactly once.
	 *
	 * @param {HTMLSelectElement} select
	 *
	 * @returns {void}
	 */
	function setUp(select)
	{
		if (select.dataset.fgAttached === '1')
		{
			return;
		}

		select.dataset.fgAttached = '1';
		attachSwitcher(select);

		if (!relocate(select))
		{
			awaitingToolbar.push(select);
		}
	}

	/**
	 * Give the selectors whose toolbar did not exist yet another go.
	 *
	 * @returns {void}
	 */
	function retryRelocations()
	{
		if (!awaitingToolbar.length)
		{
			return;
		}

		var still = [];
		var n;

		for (n = 0; n < awaitingToolbar.length; n++)
		{
			// A selector removed from the document in the meantime (a deleted
			// subform row) is simply dropped.
			if (!awaitingToolbar[n].isConnected)
			{
				continue;
			}

			if (!relocate(awaitingToolbar[n]))
			{
				still.push(awaitingToolbar[n]);
			}
		}

		awaitingToolbar = still;
	}

	/**
	 * Coalesce several DOM mutations into one setup pass on the next frame.
	 *
	 * @returns {void}
	 */
	function schedule()
	{
		if (scheduled)
		{
			return;
		}

		scheduled = true;

		window.requestAnimationFrame(function ()
		{
			scheduled = false;
			init();
		});
	}

	/**
	 * Whether an added node is, or contains, a selector that still needs
	 * setting up.
	 *
	 * @param {Node} node
	 *
	 * @returns {boolean}
	 */
	function containsSelector(node)
	{
		if (node.nodeType !== 1)
		{
			return false;
		}

		return node.matches(SELECT_QUERY) || !!node.querySelector(SELECT_QUERY);
	}

	function start()
	{
		init();

		// Joomla's own signal that a chunk of form markup was inserted or
		// re-rendered - fired by the subform field when a row is added, and by
		// other core scripts. The cheapest and most reliable of the three
		// hooks, which is why it is not left to the observer alone.
		document.addEventListener('joomla:updated', function (event)
		{
			init(event.target);
		});

		// The backstop for everything that does not announce itself: forms
		// pulled in by AJAX, third-party repeatable fields, and editors that
		// build their toolbar after this point. Only added nodes are examined,
		// and the retry pass for missing toolbars is limited to a grace period
		// so that continuous DOM churn (TinyMCE rewrites its document as the
		// user types) does not keep the page busy for nothing.
		if (!window.MutationObserver)
		{
			return;
		}

		new MutationObserver(function (records)
		{
			var interesting = false;
			var r;
			var n;

			for (r = 0; r < records.length && !interesting; r++)
			{
				for (n = 0; n < records[r].addedNodes.length; n++)
				{
					if (containsSelector(records[r].addedNodes[n]))
					{
						interesting = true;
						break;
					}
				}
			}

			if (!interesting
				&& !(awaitingToolbar.length && (Date.now() - startedAt) < RELOCATE_WINDOW_MS))
			{
				return;
			}

			schedule();
		}).observe(document.documentElement, { childList: true, subtree: true });
	}

	// Deliberately not inside start(): this asset is deferred, so the document
	// is already parsed and the editor fields exist, but the editors' own
	// (module) scripts have not run yet. Writing the carried-over content now
	// means each editor initialises from a textarea that already holds it.
	restoreHandoff();

	// Scrolling is deliberately NOT done on DOMContentLoaded: TinyMCE builds
	// its own iframe asynchronously, which changes the page height and would
	// silently undo a restore attempted that early. "load" fires once every
	// resource - editor iframes included - has finished, and
	// requestAnimationFrame() adds one more frame so the restore lands after
	// the browser's next layout pass.
	window.addEventListener('load', function ()
	{
		requestAnimationFrame(restoreScrollPosition);
	});

	if (document.readyState === 'loading')
	{
		document.addEventListener('DOMContentLoaded', start);
	}
	else
	{
		// The asset is deferred, so this branch normally does not run - but it
		// keeps the script working if it is ever loaded after parsing (e.g.
		// injected with a form).
		start();
	}
})();
