/**
 * plg_fgeditorswitcher
 *
 * Behaviour for the editor switcher selector(s). This is a static, cacheable,
 * minifiable file loaded via Joomla's WebAssetManager. Per-instance
 * configuration (the confirmation dialog text, the cookie name, etc.) is
 * read from data-* attributes on each <select> rather than being templated
 * into the script itself, so the same file works unchanged for any number of
 * editor fields on the page and needs no per-request regeneration.
 */
(function () {
	'use strict';

	var SCROLL_KEY = 'plg_fgeditorswitcher_scroll';

	/**
	 * Attach the change handler for a single switcher <select>.
	 *
	 * @param {HTMLSelectElement} select
	 */
	function attachSwitcher(select)
	{
		var cookieName = select.getAttribute('data-cookie-name');
		var debug = select.getAttribute('data-debug') === '1';

		if (!cookieName)
		{
			return;
		}

		// The currently active value, read directly off the select at attach
		// time (it always starts pre-selected to the active editor) and
		// stored on the element itself (commitChange() is a standalone
		// function, not nested in this closure, so it can't see a local
		// variable here). Used instead of an index so it stays correct even
		// if the list of editors ever changes, and so a cancelled switch can
		// be reverted to it, or a debounced-away intermediate value ignored.
		select.setAttribute('data-fg-current-value', select.value);

		if (debug)
		{
			var cookiePair = document.cookie.split('; ').filter(function (part)
			{
				return part.indexOf(cookieName + '=') === 0;
			})[0];

			// eslint-disable-next-line no-console
			console.log('[plg_fgeditorswitcher] field="' + select.id + '" current editor="'
				+ select.value + '" cookie="' + (cookiePair || '(not set)') + '"');
		}

		var pendingChange = null;

		select.addEventListener('change', function ()
		{
			var self = this;

			// Chrome/Edge fire "change" on a CLOSED <select> for every
			// arrow-key press that moves the selection, not only once a
			// choice is actually committed - a keyboard user "scanning"
			// through the options would otherwise get a confirmation
			// dialog (and a reload, once confirmed) on every single key
			// press. Debouncing here means only the value the user has
			// actually settled on for a brief moment triggers the confirm/
			// switch flow below, instead of every intermediate value along
			// the way.
			clearTimeout(pendingChange);
			pendingChange = setTimeout(function ()
			{
				commitChange(self);
			}, 400);
		});
	}

	/**
	 * The actual confirm/cookie/reload logic for a switcher <select>, run
	 * (debounced) once the user has settled on a value. Split out from
	 * attachSwitcher()'s "change" listener so the debounce wrapper there
	 * stays simple.
	 *
	 * @param {HTMLSelectElement} select
	 */
	function commitChange(select)
	{
		var confirmEnabled = select.getAttribute('data-confirm') === '1';
		var currentValue = select.getAttribute('data-fg-current-value') || '';
		var confirmTitle = select.getAttribute('data-confirm-title') || '';
		var confirmMsg = select.getAttribute('data-confirm-msg') || '';
		var cookieName = select.getAttribute('data-cookie-name');
		var debug = select.getAttribute('data-debug') === '1';

		if (confirmEnabled)
		{
			if (select.value === currentValue)
			{
				return;
			}

			if (!window.confirm(confirmTitle + '\r\n' + confirmMsg))
			{
				// The page isn't reloading (nothing was actually
				// switched), so the <select> must be reverted by hand -
				// otherwise it would keep showing the cancelled choice
				// while the real active editor stays whatever it was.
				select.value = currentValue;
				return;
			}
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

		// Remember the current scroll position across the reload that
		// follows a switch, so the page doesn't jump back to the top -
		// sessionStorage (not a cookie) since it only needs to survive
		// this one reload, for this one tab.
		try
		{
			window.sessionStorage.setItem(SCROLL_KEY, String(window.scrollY));
		}
		catch (e)
		{
			// sessionStorage can be unavailable (e.g. private browsing in
			// some browsers) - losing the scroll position is harmless, so
			// just proceed without it rather than failing the switch.
		}

		// location.reload() repeats whatever HTTP method loaded the
		// current document. Edit screens are normally opened via GET, but
		// after a failed save/validation the current document can be the
		// result of a POST - reload() would then risk the browser's
		// "resubmit form?" prompt, or in the worst case silently
		// re-submitting that POST. location.replace() with the current
		// URL always performs a fresh GET, and replaces the history
		// entry rather than adding a new one.
		window.location.replace(window.location.href);
	}

	/**
	 * Restore the scroll position saved (if any) just before the reload that
	 * follows switching editors, so the page stays where the user was
	 * instead of jumping back to the top.
	 */
	function restoreScrollPosition()
	{
		var saved;

		try
		{
			saved = window.sessionStorage.getItem(SCROLL_KEY);
			window.sessionStorage.removeItem(SCROLL_KEY);
		}
		catch (e)
		{
			return;
		}

		if (saved === null)
		{
			return;
		}

		var y = parseInt(saved, 10);

		if (!isNaN(y))
		{
			window.scrollTo(0, y);
		}
	}

	/**
	 * Find the ".editor-xtd-buttons" toolbar that belongs to a given
	 * selector, without assuming anything about DOM order relative to other
	 * selectors/toolbars on the page (a page can have unrelated toolbars -
	 * an editor in a modal, a field from another component, a hidden
	 * subform - and simply pairing the n-th selector with the n-th toolbar
	 * found anywhere on the page can then move a selector next to the wrong
	 * editor). Instead this walks backwards from the selector's own wrapper
	 * (the PHP side always renders that wrapper directly after the active
	 * editor's own markup, so the toolbar - if any - is somewhere in that
	 * preceding sibling chain, or inside one of those siblings), falling
	 * back to the nearest common field container if that search comes up
	 * empty.
	 *
	 * @param {HTMLSelectElement} select
	 * @return {Element|null}
	 */
	function findToolbarFor(select)
	{
		var wrapper = select.closest('[id^="fgeditorswitcherSelector-"]');

		if (!wrapper)
		{
			return null;
		}

		var prev = wrapper.previousElementSibling;

		while (prev)
		{
			if (prev.matches('.editor-xtd-buttons'))
			{
				return prev;
			}

			var found = prev.querySelector('.editor-xtd-buttons');

			if (found)
			{
				return found;
			}

			prev = prev.previousElementSibling;
		}

		var field = wrapper.closest('.control-group, joomla-field-fancy-select, fieldset, .card-body');

		return field ? field.querySelector('.editor-xtd-buttons') : null;
	}

	/**
	 * Progressive enhancement: relocate each selector next to its own editor's
	 * standard xtd buttons toolbar (".editor-xtd-buttons"), and copy that
	 * toolbar's real button colours onto the selector so it visually matches
	 * regardless of admin template. If a given editor has no such toolbar
	 * (e.g. "Editor - None"), its selector is simply left where it already is
	 * - never hidden or removed.
	 */
	function relocateAndMatch()
	{
		var selects = document.querySelectorAll('select[id^="fgeditorswitcher-select-"]');

		for (var n = 0; n < selects.length; n++)
		{
			var select = selects[n];

			attachSwitcher(select);

			var wrap = findToolbarFor(select);

			if (!wrap)
			{
				continue;
			}

			var refButton = wrap.children.length ? wrap.children[0] : null;

			select.style.marginLeft = 'auto';
			wrap.appendChild(select);

			if (refButton)
			{
				var computed = getComputedStyle(refButton);
				select.style.setProperty('background-color', computed.backgroundColor, 'important');
				select.style.setProperty('border-color', computed.borderColor, 'important');
				select.style.setProperty('color', computed.color, 'important');

				// The chevron drawn by fgeditorswitcher.css is a static
				// white SVG - fine against the plugin's own default dark
				// "btn-secondary" fallback colour, but invisible on an
				// admin template whose xtd buttons are light-coloured.
				// Rebuild the same chevron here with the button's actual
				// text colour (already resolved above) and use "important"
				// to override the CSS default.
				var chevron = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">'
					+ '<path fill="' + computed.color + '" d="M8 11 3 6h10z"/></svg>';
				select.style.setProperty(
					'background-image', 'url("data:image/svg+xml,' + encodeURIComponent(chevron) + '")', 'important'
				);
			}
		}
	}

	document.addEventListener('DOMContentLoaded', function ()
	{
		relocateAndMatch();
		restoreScrollPosition();
	});
})();
