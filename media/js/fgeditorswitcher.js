/**
 * plg_fgeditorswitcher
 *
 * Behaviour for the editor switcher selector(s). This is a static, cacheable,
 * minifiable file loaded via Joomla's WebAssetManager. Per-instance
 * configuration (the confirmation dialog text, which hidden field to update,
 * the cookie name, etc.) is read from data-* attributes on each <select>
 * rather than being templated into the script itself, so the same file works
 * unchanged for any number of editor fields on the page and needs no
 * per-request regeneration.
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
		var hiddenId = select.getAttribute('data-hidden-id');
		var hidden = hiddenId ? document.getElementById(hiddenId) : null;
		var confirmEnabled = select.getAttribute('data-confirm') === '1';
		var currentIndex = parseInt(select.getAttribute('data-current-index'), 10) || 0;
		var confirmTitle = select.getAttribute('data-confirm-title') || '';
		var confirmMsg = select.getAttribute('data-confirm-msg') || '';
		var cookieName = select.getAttribute('data-cookie-name');
		var debug = select.getAttribute('data-debug') === '1';

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
			console.log('[plg_fgeditorswitcher] field="' + select.id + '" current editor="'
				+ select.value + '" cookie="' + (cookiePair || '(not set)') + '"');
		}

		select.addEventListener('change', function ()
		{
			if (confirmEnabled)
			{
				if (this.options.selectedIndex === currentIndex)
				{
					return;
				}

				if (!window.confirm(confirmTitle + '\r\n' + confirmMsg))
				{
					return;
				}

				if (hidden)
				{
					hidden.value = this.options.selectedIndex;
				}
			}

			// "path=/" avoids the cookie being scoped to whichever admin URL
			// it happened to be set from; "secure" is only added when the
			// page itself is loaded over HTTPS.
			document.cookie = cookieName + '=' + this.value + '; path=/; samesite=lax'
				+ (location.protocol === 'https:' ? '; secure' : '');

			if (debug)
			{
				// eslint-disable-next-line no-console
				console.log('[plg_fgeditorswitcher] switching to "' + this.value + '", reloading...');
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

			window.location.reload();
		});
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
	 * Progressive enhancement: relocate each selector next to its own editor's
	 * standard xtd buttons toolbar (".editor-xtd-buttons"), and copy that
	 * toolbar's real button colours onto the selector so it visually matches
	 * regardless of admin template. If a given editor has no such toolbar
	 * (e.g. "Editor - None"), its selector is simply left where it already is
	 * - never hidden or removed. On a page with multiple editor fields,
	 * selectors and toolbars are paired up by their order in the DOM.
	 */
	function relocateAndMatch()
	{
		var wraps = document.querySelectorAll('.editor-xtd-buttons');
		var selects = document.querySelectorAll('select[id^="fgeditorswitcher-select-"]');

		for (var n = 0; n < selects.length; n++)
		{
			var select = selects[n];

			attachSwitcher(select);

			var wrap = wraps[n];

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
			}
		}
	}

	document.addEventListener('DOMContentLoaded', function ()
	{
		relocateAndMatch();
		restoreScrollPosition();
	});
})();
