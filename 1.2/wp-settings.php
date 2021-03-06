<?php
$HTTP_HOST = getenv('HTTP_HOST');  /* domain name */
$REMOTE_ADDR = getenv('REMOTE_ADDR'); /* visitor's IP */
$HTTP_USER_AGENT = getenv('HTTP_USER_AGENT'); /* visitor's browser */

// Fix for IIS, which doesn't set REQUEST_URI
$_SERVER['REQUEST_URI'] = ( isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $_SERVER['SCRIPT_NAME'] . (( isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')));

// Change to E_ALL for development/debugging
error_reporting(E_ALL ^ E_NOTICE);

// Table names
$tableposts               = $table_prefix . 'posts';
$tableusers               = $table_prefix . 'users';
$tablesettings            = $table_prefix . 'settings'; // only used during upgrade
$tablecategories          = $table_prefix . 'categories';
$tablepost2cat            = $table_prefix . 'post2cat';
$tablecomments            = $table_prefix . 'comments';
$tablelinks               = $table_prefix . 'links';
$tablelinkcategories      = $table_prefix . 'linkcategories';
$tableoptions             = $table_prefix . 'options';
$tableoptiontypes         = $table_prefix . 'optiontypes';
$tableoptionvalues        = $table_prefix . 'optionvalues';
$tableoptiongroups        = $table_prefix . 'optiongroups';
$tableoptiongroup_options = $table_prefix . 'optiongroup_options';
$tablepostmeta            = $table_prefix . 'postmeta';

define('WPINC', 'wp-includes');

require_once (ABSPATH . WPINC . '/wp-db.php');

$wpdb->hide_errors();
$users = $wpdb->get_results("SELECT * FROM $tableusers");
if (!$users && !strstr($_SERVER['PHP_SELF'], 'install.php')) {
	die("It doesn't look like you've installed WP yet. Try running <a href='wp-admin/install.php'>install.php</a>.");
}
$wpdb->show_errors();

require (ABSPATH . WPINC . '/functions.php');
require (ABSPATH . WPINC . '/functions-formatting.php');
require (ABSPATH . WPINC . '/template-functions.php');
require (ABSPATH . WPINC . '/links.php');
require (ABSPATH . WPINC . '/kses.php');
require_once (ABSPATH . WPINC . '/wp-l10n.php');

if (!strstr($_SERVER['PHP_SELF'], 'install.php') && !strstr($_SERVER['PHP_SELF'], 'wp-admin/import')) {

    $querystring_start = '?';
    $querystring_equal = '=';
    $querystring_separator = '&amp;';
    //}
    // Used to guarantee unique cookies
    $cookiehash = md5(get_settings('siteurl'));

} //end !$_wp_installing

require (ABSPATH . WPINC . '/vars.php');


// Check for hacks file if the option is enabled
if (get_settings('hack_file')) {
	if (file_exists(ABSPATH . '/my-hacks.php'))
		require(ABSPATH . '/my-hacks.php');
}

if (!strstr($_SERVER['PHP_SELF'], 'wp-admin/plugins.php') && get_settings('active_plugins')) {
	$current_plugins = explode("\n", (get_settings('active_plugins')));
	foreach ($current_plugins as $plugin) {
		if (file_exists(ABSPATH . 'wp-content/plugins/' . $plugin))
			include(ABSPATH . 'wp-content/plugins/' . $plugin);
	}
}

function shutdown_action_hook() {
	do_action('shutdown', '');
}
register_shutdown_function('shutdown_action_hook');

?>