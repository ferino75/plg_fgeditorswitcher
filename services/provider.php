<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Editors.fgeditorswitcher
 *
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use FG\Plugin\Editors\Fgeditorswitcher\Extension\Fgeditorswitcher;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	public function register(Container $container)
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$dispatcher = $container->get(DispatcherInterface::class);
				// There is an environment where namespace autoloading does not work.
				class_exists(Fgeditorswitcher::class) or require_once(JPATH_PLUGINS . '/editors/fgeditorswitcher/src/Extension/Fgeditorswitcher.php');

				$plugin     = new Fgeditorswitcher(
					$dispatcher,
					(array) PluginHelper::getPlugin('editors', 'fgeditorswitcher')
				);
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
