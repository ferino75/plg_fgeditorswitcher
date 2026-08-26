<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Editors.fgeditorswitcher
 *
 * @copyright    (C) 2026 Fero
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
 * Named "FgeditorsField" (form field type "fgeditors"), not the more
 * obvious "EditorsField"/"editors": the field type resolves via
 * addfieldprefix + Ucfirst(type) + "Field", so a type of "editors" would
 * collide if Joomla core ever ships its own EditorsField class under the
 * same short name in a future version. Prefixing with "fg" avoids that
 * class of problem entirely.
 *
 * @package     Joomla.Plugin
 * @subpackage  Editors.fgeditorswitcher
 *
 * @since 2.2.1
 */
class FgeditorsField extends PluginsField
{
	protected function getOptions()
	{
		return array_filter(
			parent::getOptions(),
			static fn ($editor) => $editor->value !== 'fgeditorswitcher'
		);
	}
}
