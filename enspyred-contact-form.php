<?php
/*
Plugin Name: Enspyred Contact Form
Description: React + REST contact form with spam controls.
Version: 1.2.3
Author: Enspyred
Author URI: https://enspyred.com
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: enspyred-contact-form
*/

// Plugin Update Checker
require plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5p6\PucFactory;

$enspyredContactFormUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/enspyred/wp-plugin-enspyred-contact-form',
	__FILE__,
	'enspyred-contact-form'
);
$enspyredContactFormUpdateChecker->getVcsApi()->enableReleaseAssets();

// general
require_once plugin_dir_path(__FILE__) . 'inc/helpers.php';
require_once plugin_dir_path(__FILE__) . 'inc/router.php';
require_once plugin_dir_path(__FILE__) . 'inc/shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'inc/menus.php';

// tools
require_once plugin_dir_path(__FILE__) . 'inc/react.php';
require_once plugin_dir_path(__FILE__) . 'inc/api.php';
require_once plugin_dir_path(__FILE__) . 'inc/email.php';

// pages
require_once plugin_dir_path(__FILE__) . 'inc/pages/contact-forms.php';
require_once plugin_dir_path(__FILE__) . 'inc/pages/edit-config.php';
require_once plugin_dir_path(__FILE__) . 'inc/pages/settings.php';

// start her up
require_once plugin_dir_path(__FILE__) . 'inc/init.php';
