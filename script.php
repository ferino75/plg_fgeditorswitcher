<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Editors.fgeditorswitcher
 *
 * @copyright    (C) 2026 Fero
 * @license      GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;

return new class () implements InstallerScriptInterface {
	/**
	 * The name of this plugin's cookie, kept in sync with
	 * FG\Plugin\Editors\Fgeditorswitcher\Extension\Fgeditorswitcher::$cookiename.
	 * Duplicated here rather than referencing that class directly: install
	 * scripts run in their own isolated bootstrap, before this plugin's own
	 * namespace/autoloading is necessarily set up, and a plain string
	 * constant is simpler and more robust than trying to load that class
	 * just for this one value.
	 *
	 * @var string
	 * @since 2.2.1
	 */
	private const COOKIE_NAME = 'fgeditorswitchercurrent';

	/**
	 * Runs on uninstall. Expires the plugin's own cookie in the browser of
	 * whoever is performing the uninstall, so it doesn't linger as an
	 * orphaned cookie once the plugin (and the class that would otherwise
	 * validate its value) is gone. Purely a cleanliness measure: even if
	 * this never ran, the cookie is harmless once the plugin no longer
	 * exists to read it.
	 *
	 * @param   InstallerAdapter  $adapter  The installer adapter.
	 *
	 * @return  bool
	 * @since   2.2.1
	 */
	public function uninstall(InstallerAdapter $adapter): bool
	{
		if (!\headers_sent())
		{
			\setcookie(self::COOKIE_NAME, '', time() - 3600, '/');
		}

		return true;
	}

	/**
	 * @param   InstallerAdapter  $adapter  The installer adapter.
	 *
	 * @return  bool
	 * @since   2.2.1
	 */
	public function install(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * @param   InstallerAdapter  $adapter  The installer adapter.
	 *
	 * @return  bool
	 * @since   2.2.1
	 */
	public function update(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * @param   string            $type     One of "install", "update" or "discover_install".
	 * @param   InstallerAdapter  $adapter  The installer adapter.
	 *
	 * @return  bool
	 * @since   2.2.1
	 */
	public function preflight(string $type, InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * @param   string            $type     One of "install", "update" or "discover_install".
	 * @param   InstallerAdapter  $adapter  The installer adapter.
	 *
	 * @return  bool
	 * @since   2.2.1
	 */
	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		return true;
	}
};
