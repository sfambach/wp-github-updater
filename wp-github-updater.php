<?php
/**
 * Plugin Name:       WP GitHub Updater
 * Plugin URI:        https://github.com/sfambach/wp-github-updater
 * Description:       Updates plugins with a "GitHub Plugin URI" header from their latest GitHub release, via the normal WordPress update screen. Optional abilities let an AI connector check and run the updates.
 * Version:           1.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Stefan Fambach
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-github-updater
 * GitHub Plugin URI: sfambach/wp-github-updater
 * Primary Branch:    main
 *
 * @package WpGithubUpdater
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-abilities.php';

WP_Github_Updater::init();
WP_Github_Updater_Abilities::init();
