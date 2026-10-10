<?php
/**
 * Abilities so an AI connector (MCP) can check and run plugin updates.
 *
 * @package WpGithubUpdater
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers "github-updater/status" and "github-updater/update" with the Abilities API.
 */
final class WP_Github_Updater_Abilities {

	private const CATEGORY = 'github-updater';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		/** @see https://developer.wordpress.org/reference/hooks/wp_abilities_api_categories_init/ */
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );

		/** @see https://developer.wordpress.org/reference/hooks/wp_abilities_api_init/ */
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Ability category.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'GitHub Updater', 'wp-github-updater' ),
				'description' => __( 'Check and install updates of plugins hosted on GitHub.', 'wp-github-updater' ),
			)
		);
	}

	/**
	 * Abilities.
	 */
	public static function register_abilities(): void {
		$plugin_list = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'plugin'    => array( 'type' => 'string' ),
					'repo'      => array( 'type' => 'string' ),
					'installed' => array( 'type' => 'string' ),
					'latest'    => array( 'type' => 'string' ),
					'update'    => array( 'type' => 'boolean' ),
					'release'   => array( 'type' => 'string' ),
					'result'    => array( 'type' => 'string' ),
				),
			),
		);

		wp_register_ability(
			self::CATEGORY . '/status',
			array(
				'label'               => __( 'GitHub plugin update status', 'wp-github-updater' ),
				'description'         => __( 'Lists plugins with a GitHub Plugin URI header, their installed and latest released version. Always checks GitHub fresh.', 'wp-github-updater' ),
				'category'            => self::CATEGORY,
				'output_schema'       => $plugin_list,
				'execute_callback'    => array( self::class, 'status' ),
				'permission_callback' => array( self::class, 'can_update' ),
				'meta'                => array(
					'show_in_rest' => true,
					'public'       => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			self::CATEGORY . '/update',
			array(
				'label'               => __( 'Update GitHub plugins', 'wp-github-updater' ),
				'description'         => __( 'Installs the latest GitHub release of one plugin (slug or plugin file, e.g. wp-usefull-blocks) or of all GitHub plugins that have an update. Active plugins stay active.', 'wp-github-updater' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin slug or file; empty or "all" updates every GitHub plugin with a newer release.',
							'default'     => 'all',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => $plugin_list,
				'execute_callback'    => array( self::class, 'update' ),
				'permission_callback' => array( self::class, 'can_update' ),
				'meta'                => array(
					'show_in_rest' => true,
					'public'       => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Only users who may update plugins.
	 */
	public static function can_update(): bool {
		return current_user_can( 'update_plugins' );
	}

	/**
	 * Installed vs. latest version of every GitHub plugin.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function status(): array {
		$rows = array();

		foreach ( WP_Github_Updater::managed_plugins() as $file => $plugin ) {
			$release = WP_Github_Updater::latest_release( $plugin['repo'], true );
			$rows[]  = array(
				'plugin'    => $file,
				'repo'      => $plugin['repo'],
				'installed' => $plugin['version'],
				'latest'    => null === $release ? '' : $release['version'],
				'update'    => null !== $release && version_compare( $release['version'], $plugin['version'], '>' ),
				'release'   => null === $release ? '' : $release['url'],
			);
		}

		return $rows;
	}

	/**
	 * Run updates.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function update( $input = null ) {
		$wanted = is_array( $input ) ? trim( (string) ( $input['plugin'] ?? '' ) ) : '';
		$wanted = 'all' === $wanted ? '' : $wanted;

		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$rows  = self::status();
		$files = array();

		foreach ( $rows as $row ) {
			if ( '' !== $wanted && $wanted !== $row['plugin'] && dirname( $row['plugin'] ) !== $wanted ) {
				continue;
			}
			if ( $row['update'] ) {
				$files[] = $row['plugin'];
			}
		}

		if ( '' !== $wanted && array() === array_filter( $rows, static fn( $row ) => $wanted === $row['plugin'] || dirname( $row['plugin'] ) === $wanted ) ) {
			return new WP_Error( 'wpghu_unknown_plugin', __( 'No GitHub plugin with this slug is installed.', 'wp-github-updater' ) );
		}

		// Refresh core's update data so the upgrader sees the new packages.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$results = array();
		if ( array() !== $files ) {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$results  = (array) $upgrader->bulk_upgrade( $files );
		}

		foreach ( $rows as &$row ) {
			if ( ! in_array( $row['plugin'], $files, true ) ) {
				$row['result'] = $row['update'] ? 'skipped' : 'up to date';
				continue;
			}
			$result        = $results[ $row['plugin'] ] ?? null;
			$row['result'] = is_wp_error( $result ) ? 'error: ' . $result->get_error_message() : ( $result ? 'updated' : 'failed' );
		}
		unset( $row );

		return $rows;
	}
}
