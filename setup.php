<?php

/**
 * Get the name and the version of the plugin - Needed
 */
if (!function_exists('plugin_version_zendesksync')) {
    function plugin_version_zendesksync() {
        return array('name'           => "Zendesk Sync",
            'version'        => '1.0.0',
            'author'         => '<a href="https://isale.pro/">iSalePro</a>',
            'license'        => 'GPLv2+',
            'homepage'       => 'https://isale.pro/',
            'minGlpiVersion' => '9.2.4');
    }
}


/**
 *  Check if the config is ok - Needed
 */
if (!function_exists('plugin_zendesksync_check_config')) {
    function plugin_zendesksync_check_config()
    {
        return true;
    }
}
 
/**
 * Check if the prerequisites of the plugin are satisfied - Needed
 */
if (!function_exists('plugin_zendesksync_check_prerequisites')) {
    function plugin_zendesksync_check_prerequisites()
    {
        // Check that the GLPI version is compatible
        if (version_compare(GLPI_VERSION, '9.2.4', 'lt')) {
            echo "This plugin Requires GLPI >= 9.2.4";
            return false;
        }
        return true;
    }
}

/**
 * Init the hooks of the plugins -Needed
**/
if (!function_exists('plugin_init_zendesksync')) {
    function plugin_init_zendesksync()
    {
        global $PLUGIN_HOOKS;

        $PLUGIN_HOOKS['csrf_compliant']['zendesksync'] = true;
        $PLUGIN_HOOKS['config_page']['zendesksync'] = 'front/config.form.php';
    }
}
