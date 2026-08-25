<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Editors.fgeditorswitcher
 *
 * @copyright    (C) 2026 Fero. Based on the original "Editor - Switcher" plugin
 *               (C) 2007 Yoshiki Kozaki.
 * @license      https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 */

namespace FG\Plugin\Editors\Fgeditorswitcher\Field;


// No direct access
\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\PluginsField;

/**
 * Lists installed/enabled editor plugins for the "Default editor" setting,
 * excluding this plugin itself (switching to itself would be meaningless).
 *
 * @package     Joomla.Plugin
 * @subpackage  Editors.fgeditorswitcher
 *
 * @since 2.0.0
 */
class EditorsField extends PluginsField
{
	protected function getOptions()
	{
		$editors = parent::getOptions();

		foreach ($editors as $k => $editor)
		{
			if ($editor->value == 'fgeditorswitcher')
			{
				unset($editors[$k]);
				break;
			}
		}

		return $editors;
	}
}
