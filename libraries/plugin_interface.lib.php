<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * Generic plugin interface.
 *
 * @package PhpMyAdmin
 */
use PMA\libraries\properties\options\groups\OptionsPropertySubgroup;
use PMA\libraries\properties\options\OptionsPropertyItem;
use PMA\libraries\properties\plugins\ExportPluginProperties;
use PMA\libraries\properties\plugins\PluginPropertyItem;
use PMA\libraries\properties\plugins\SchemaPluginProperties;

/**
 * Includes and instantiates the specified plugin type for a certain format
 *
 * @param string $plugin_type   the type of the plugin (import, export, etc)
 * @param string $plugin_format the format of the plugin (sql, xml, et )
 * @param string $plugins_dir   directory with plugins
 * @param mixed  $plugin_param  parameter to plugin by which they can
 *                              decide whether they can work
 *
 * @return object|null new plugin instance
 */
function PMA_getPlugin(
    $plugin_type,
    $plugin_format,
    $plugins_dir,
    $plugin_param = false
) {
    $GLOBALS['plugin_param'] = $plugin_param;
    $class_name = /*overload*/mb_strtoupper($plugin_type[0])
        . /*overload*/mb_strtolower(/*overload*/mb_substr($plugin_type, 1))
        . /*overload*/mb_strtoupper($plugin_format[0])
        . /*overload*/mb_strtolower(/*overload*/mb_substr($plugin_format, 1));
    $file = $class_name . ".php";
    if (is_file($plugins_dir . $file)) {
        //include_once $plugins_dir . $file;
        $fqnClass = 'PMA\\' . str_replace('/', '\\', $plugins_dir) . $class_name;
        return new $fqnClass;
    }

    return null;
}

/**
 * Reads all plugin information from directory $plugins_dir
 *
 * @param string $plugin_type  the type of the plugin (import, export, etc)
 * @param string $plugins_dir  directory with plugins
 * @param mixed  $plugin_param parameter to plugin by which they can
 *                             decide whether they can work
 *
 * @return array list of plugin instances
 */
function PMA_getPlugins($plugin_type, $plugins_dir, $plugin_param)
{
    $GLOBALS['plugin_param'] = $plugin_param;
    /* Scan for plugins */
    $plugin_list = array();
    if (!($handle = @opendir($plugins_dir))) {
        ksort($plugin_list);
        return $plugin_list;
    }

    $namespace = 'PMA\\' . str_replace('/', '\\', $plugins_dir);
    $class_type = mb_strtoupper($plugin_type[0], 'UTF-8')
        . mb_strtolower(/*overload*/mb_substr($plugin_type, 1), 'UTF-8');

    $prefix_class_name = $namespace . $class_type;

    //@todo Find a way to use PMA_StringMB with UTF-8 instead of mb_*.
    while ($file = @readdir($handle)) {
        // In some situations, Mac OS creates a new file for each file
        // (for example ._csv.php) so the following regexp
        // matches a file which does not start with a dot but ends
        // with ".php"
        if (is_file($plugins_dir . $file)
            && preg_match(
                '@^' . $class_type . '(.+)\.php$@i',
                $file,
                $matches
            )
        ) {
            $GLOBALS['skip_import'] = false;
            include_once $plugins_dir . $file;
            if (! $GLOBALS['skip_import']) {
                $class_name = $prefix_class_name . $matches[1];
                $plugin = new $class_name;
                if (null !== $plugin->getProperties()) {
                    $plugin_list[] = $plugin;
                }
            }
        }
    }

    ksort($plugin_list);
    return $plugin_list;
}

/**
 * Returns locale string for $name or $name if no locale is found
 *
 * @param string $name for local string
 *
 * @return string  locale string for $name
 */
function PMA_getString($name)
{
    return isset($GLOBALS[$name]) ? $GLOBALS[$name] : $name;
}

/**
 * Returns html input tag option 'checked' if plugin $opt
 * should be set by config or request
 *
 * @param string $section name of config section in
 *                        $GLOBALS['cfg'][$section] for plugin
 * @param string $opt     name of option
 *
 * @return string  html input tag option 'checked'
 */
function PMA_pluginCheckboxCheck($section, $opt)
{
    // If the form is being repopulated using $_GET data, that is priority
    if (isset($_GET[$opt])
        || ! isset($_GET['repopulate'])
        && ((! empty($GLOBALS['timeout_passed']) && isset($_REQUEST[$opt]))
        || ! empty($GLOBALS['cfg'][$section][$opt]))
    ) {
        return ' checked="checked"';
    }
    return '';
}

/**
 * Returns default value for option $opt
 *
 * @param string $section name of config section in
 *                        $GLOBALS['cfg'][$section] for plugin
 * @param string $opt     name of option
 *
 * @return string  default value for option $opt
 */
function PMA_pluginGetDefault($section, $opt)
{
    if (isset($_GET[$opt])) {
        // If the form is being repopulated using $_GET data, that is priority
        return htmlspecialchars($_GET[$opt]);
    }

    if (isset($GLOBALS['timeout_passed'])
        && $GLOBALS['timeout_passed']
        && isset($_REQUEST[$opt])
    ) {
        return htmlspecialchars($_REQUEST[$opt]);
    }

    if (!isset($GLOBALS['cfg'][$section][$opt])) {
        return '';
    }

    $matches = array();
    /* Possibly replace localised texts */
    if (!preg_match_all(
        '/(str[A-Z][A-Za-z0-9]*)/',
        $GLOBALS['cfg'][$section][$opt],
        $matches
    )) {
        return htmlspecialchars($GLOBALS['cfg'][$section][$opt]);
    }

    $val = $GLOBALS['cfg'][$section][$opt];
    foreach ($matches[0] as $match) {
        if (isset($GLOBALS[$match])) {
            $val = str_replace($match, $GLOBALS[$match], $val);
        }
    }
    return htmlspecialchars($val);
}

/**
 * Returns html select form element for plugin choice
 * and hidden fields denoting whether each plugin must be exported as a file
 *
 * @param string $section name of config section in
 *                        $GLOBALS['cfg'][$section] for plugin
 * @param string $name    name of select element
 * @param array  &$list   array with plugin instances
 * @param string $cfgname name of config value, if none same as $name
 *
 * @return string  html select tag
 */
function PMA_pluginGetChoice($section, $name, &$list, $cfgname = null)
{
    if (! isset($cfgname)) {
        $cfgname = $name;
    }
    $ret = '<select id="plugins" name="' . $name . '">';
    $default = PMA_pluginGetDefault($section, $cfgname);
    $hidden = null;
    foreach ($list as $plugin) {
        $elem = explode('\\', get_class($plugin));
        $plugin_name = array_pop($elem);
        unset($elem);
        $plugin_name = /*overload*/mb_strtolower(
            /*overload*/mb_substr(
                $plugin_name,
                /*overload*/mb_strlen($section)
            )
        );
        $ret .= '<option';
         // If the form is being repopulated using $_GET data, that is priority
        if (isset($_GET[$name])
            && $plugin_name == $_GET[$name]
            || ! isset($_GET[$name])
            && $plugin_name == $default
        ) {
            $ret .= ' selected="selected"';
        }

        /** @var PluginPropertyItem $properties */
        $properties = $plugin->getProperties();
        $text = null;
        if ($properties != null) {
            $text = $properties->getText();
        }
        $ret .= ' value="' . $plugin_name . '">'
           . PMA_getString($text)
           . '</option>' . "\n";

        // Whether each plugin has to be saved as a file
        $hidden .= '<input type="hidden" id="force_file_' . $plugin_name
            . '" value="';
        /** @var ExportPluginProperties|SchemaPluginProperties $properties */
        $properties = $plugin->getProperties();
        if (! strcmp($section, 'Import')
            || ($properties != null && $properties->getForceFile() != null)
        ) {
            $hidden .= 'true';
        } else {
            $hidden .= 'false';
        }
        $hidden .= '" />' . "\n";
    }
    $ret .= '</select>' . "\n" . $hidden;

    return $ret;
}

/**
 * Returns single option in a list element
 *
 * @param string  $section        name of config section in
 *                               $GLOBALS['cfg'][$section] for plugin
 * @param string  $plugin_name    unique plugin name
 * @param array   &$propertyGroup options property main group instance
 * @param boolean $is_subgroup    if this group is a subgroup
 *
 * @return string  table row with option
 */
function PMA_pluginGetOneOption(
    $section,
    $plugin_name,
    &$propertyGroup,
    $is_subgroup = false
) {
    $ret = "\n";

    if (! $is_subgroup) {
        // for subgroup headers
        if (/*overload*/mb_strpos(get_class($propertyGroup), "PropertyItem")) {
            $properties = array($propertyGroup);
        } else {
            // for main groups
            $ret .= '<div class="export_sub_options" id="' . $plugin_name . '_'
                . $propertyGroup->getName() . '">';

            if (method_exists($propertyGroup, 'getText')) {
                $text = $propertyGroup->getText();
            }

            if ($text != null) {
                $ret .= '<h4>' . PMA_getString($text) . '</h4>';
            }
            $ret .= '<ul>';
        }
    }

    if (! isset($properties)) {
        $not_subgroup_header = true;
        if (method_exists($propertyGroup, 'getProperties')) {
            $properties = $propertyGroup->getProperties();
        }
    }

    if (isset($properties)) {
        /** @var OptionsPropertySubgroup $propertyItem */
        foreach ($properties as $propertyItem) {
            $property_class = get_class($propertyItem);
            // if the property is a subgroup, we deal with it recursively
            if (/*overload*/mb_strpos($property_class, "Subgroup")) {
                // for subgroups
                // each subgroup can have a header, which may also be a form element
                /** @var OptionsPropertyItem $subgroup_header */
                $subgroup_header = $propertyItem->getSubgroupHeader();
                if (isset($subgroup_header)) {
                    $ret .= PMA_pluginGetOneOption(
                        $section,
                        $plugin_name,
                        $subgroup_header
                    );
                }

                $ret .= '<li class="subgroup"><ul';
                if (isset($subgroup_header)) {
                    $ret .= ' id="ul_' . $subgroup_header->getName() . '">';
                } else {
                    $ret .= '>';
                }

                $ret .=  PMA_pluginGetOneOption(
                    $section,
                    $plugin_name,
                    $propertyItem,
                    true
                );
                continue;
            }

            // single property item
            $ret .= PMA_getHtmlForProperty(
                $section, $plugin_name, $propertyItem
            );
        }
    }

    if ($is_subgroup) {
        // end subgroup
        $ret .= '</ul></li>';
    } else {
        // end main group
        if (! empty($not_subgroup_header)) {
            $ret .= '</ul></div>';
        }
    }

    if (method_exists($propertyGroup, "getDoc")) {
        $doc = $propertyGroup->getDoc();
        if ($doc != null) {
            if (count($doc) == 3) {
                $ret .= PMA\libraries\Util::showMySQLDocu(
                    $doc[1],
                    false,
                    $doc[2]
                );
            } elseif (count($doc) == 1) {
                $ret .= PMA\libraries\Util::showDocu('faq', $doc[0]);
            } else {
                $ret .= PMA\libraries\Util::showMySQLDocu(
                    $doc[1]
                );
            }
        }
    }

    // Close the list element after $doc link is displayed
    if (isset($property_class)) {
        if ($property_class == 'PMA\libraries\properties\options\items\BoolPropertyItem'
            || $property_class == 'PMA\libraries\properties\options\items\MessageOnlyPropertyItem'
            || $property_class == 'PMA\libraries\properties\options\items\SelectPropertyItem'
            || $property_class == 'PMA\libraries\properties\options\items\TextPropertyItem'
        ) {
            $ret .= '</li>';
        }
    }
    $ret .= "\n";
    return $ret;
}

/**
 * Get HTML for properties items
 *
 * @param string              $section      name of config section in
 *                                          $GLOBALS['cfg'][$section] for plugin
 * @param string              $plugin_name  unique plugin name
 * @param OptionsPropertyItem $propertyItem Property item
 *
 * @return string
 */
function PMA_getHtmlForProperty(
    $section, $plugin_name, $propertyItem
) {
    $ret = null;
    $property_class = get_class($propertyItem);
    switch ($property_class) {
    case 'PMA\libraries\properties\options\items\BoolPropertyItem':
        $ret .= '<li>' . "\n";
        $ret .= '<input type="checkbox" name="' . $plugin_name . '_'
            . $propertyItem->getName() . '"'
            . ' value="something" id="checkbox_' . $plugin_name . '_'
            . $propertyItem->getName() . '"'
            . ' '
            . PMA_pluginCheckboxCheck(
                $section,
                $plugin_name . '_' . $propertyItem->getName()
            );

        if ($propertyItem->getForce() != null) {
            // Same code is also few lines lower, update both if needed
            $ret .= ' onclick="if (!this.checked &amp;&amp; '
                . '(!document.getElementById(\'checkbox_' . $plugin_name
                . '_' . $propertyItem->getForce() . '\') '
                . '|| !document.getElementById(\'checkbox_'
                . $plugin_name . '_' . $propertyItem->getForce()
                . '\').checked)) '
                . 'return false; else return true;"';
        }
        $ret .= ' />';
        $ret .= '<label for="checkbox_' . $plugin_name . '_'
            . $propertyItem->getName() . '">'
            . PMA_getString($propertyItem->getText()) . '</label>';
        break;
    case 'PMA\libraries\properties\options\items\DocPropertyItem':
        echo 'PMA\libraries\properties\options\items\DocPropertyItem';
        break;
    case 'PMA\libraries\properties\options\items\HiddenPropertyItem':
        $ret .= '<li><input type="hidden" name="' . $plugin_name . '_'
            . $propertyItem->getName() . '"'
            . ' value="' . PMA_pluginGetDefault(
                $section,
                $plugin_name . '_' . $propertyItem->getName()
            )
            . '"' . ' /></li>';
        break;
    case 'PMA\libraries\properties\options\items\MessageOnlyPropertyItem':
        $ret .= '<li>' . "\n";
        $ret .= '<p>' . PMA_getString($propertyItem->getText()) . '</p>';
        break;
    case 'PMA\libraries\properties\options\items\RadioPropertyItem':
        $default = PMA_pluginGetDefault(
            $section,
            $plugin_name . '_' . $propertyItem->getName()
        );
        foreach ($propertyItem->getValues() as $key => $val) {
            $ret .= '<li><input type="radio" name="' . $plugin_name
                . '_' . $propertyItem->getName() . '" value="' . $key
                . '" id="radio_' . $plugin_name . '_'
                . $propertyItem->getName() . '_' . $key . '"';
            if ($key == $default) {
                $ret .= ' checked="checked"';
            }
            $ret .= ' />' . '<label for="radio_' . $plugin_name . '_'
                . $propertyItem->getName() . '_' . $key . '">'
                . PMA_getString($val) . '</label></li>';
        }
        break;
    case 'PMA\libraries\properties\options\items\SelectPropertyItem':
        $ret .= '<li>' . "\n";
        $ret .= '<label for="select_' . $plugin_name . '_'
            . $propertyItem->getName() . '" class="desc">'
            . PMA_getString($propertyItem->getText()) . '</label>';
        $ret .= '<select name="' . $plugin_name . '_'
            . $propertyItem->getName() . '"'
            . ' id="select_' . $plugin_name . '_'
            . $propertyItem->getName() . '">';
        $default = PMA_pluginGetDefault(
            $section,
            $plugin_name . '_' . $propertyItem->getName()
        );
        foreach ($propertyItem->getValues() as $key => $val) {
            $ret .= '<option value="' . $key . '"';
            if ($key == $default) {
                $ret .= ' selected="selected"';
            }
            $ret .= '>' . PMA_getString($val) . '</option>';
        }
        $ret .= '</select>';
        break;
    case 'PMA\libraries\properties\options\items\TextPropertyItem':
    case 'PMA\libraries\properties\options\items\NumberPropertyItem':
        $ret .= '<li>' . "\n";
        $ret .= '<label for="text_' . $plugin_name . '_'
            . $propertyItem->getName() . '" class="desc">'
            . PMA_getString($propertyItem->getText()) . '</label>';
        $ret .= '<input type="text" name="' . $plugin_name . '_'
            . $propertyItem->getName() . '"'
            . ' value="' . PMA_pluginGetDefault(
                $section,
                $plugin_name . '_' . $propertyItem->getName()
            ) . '"'
            . ' id="text_' . $plugin_name . '_'
            . $propertyItem->getName() . '"'
            . ($propertyItem->getSize() != null
                ? ' size="' . $propertyItem->getSize() . '"'
                : '')
            . ($propertyItem->getLen() != null
                ? ' maxlength="' . $propertyItem->getLen() . '"'
                : '')
            . ' />';
        break;
    default:
        break;
    }
    return $ret;
}

/**
 * Returns html div with editable options for plugin
 *
 * @param string $section name of config section in $GLOBALS['cfg'][$section]
 * @param array  &$list   array with plugin instances
 *
 * @return string  html fieldset with plugin options
 */
function PMA_pluginGetOptions($section, &$list)
{
    $ret = '';
    // Options for plugins that support them
    foreach ($list as $plugin) {
        $properties = $plugin->getProperties();
        if ($properties != null) {
            $text = $properties->getText();
            $options = $properties->getOptions();
        }

        $elem = explode('\\', get_class($plugin));
        $plugin_name = array_pop($elem);
        unset($elem);
        $plugin_name = /*overload*/mb_strtolower(
            /*overload*/mb_substr(
                $plugin_name,
                /*overload*/mb_strlen($section)
            )
        );

        $ret .= '<div id="' . $plugin_name
            . '_options" class="format_specific_options">';
        $ret .= '<h3>' . PMA_getString($text) . '</h3>';

        $no_options = true;
        if ($options != null && count($options) > 0) {
            foreach ($options->getProperties()
                as $propertyMainGroup
            ) {
                // check for hidden properties
                $no_options = true;
                foreach ($propertyMainGroup->getProperties() as $propertyItem) {
                    if (strcmp('PMA\libraries\properties\options\items\HiddenPropertyItem', get_class($propertyItem))) {
                        $no_options = false;
                        break;
                    }
                }

                $ret .= PMA_pluginGetOneOption(
                    $section,
                    $plugin_name,
                    $propertyMainGroup
                );
            }
        }

        if ($no_options) {
            $ret .= '<p>' . __('This format has no options') . '</p>';
        }
        $ret .= '</div>';
    }
    return $ret;
}
