<?php
/**
 * REST endpoint a release workflow calls so a new release shows up at once.
 *
 * @package WpGithubUpdater
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /wp-json/github-updater/v1/refresh?repo=owner/repo re-reads that repository's latest release.
 *
 * Public on purpose: it only re-reads public release data of an installed plugin, so a secret
 * in every plugin repository would buy nothing. A per-repository throttle keeps it from being
 * used to burn the GitHub API limit of this host.
 */
final class WP_Github_Updater_Refresh_Endpoint {

	private const NAMESPACE_V1 = 'github-updater/v1';
	private const THROTTLE     = 'wpghu_refresh_';
	private const INTERVAL     = 2 * MINUTE_IN_SECONDS;

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		/** @see https://developer.wordpress.org/reference/hooks/rest_api_init/ */
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Route.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'refresh' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'repo' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( WP_Github_Updater::class, 'parse_repo' ),
					),
				),
			)
		);
	}

	/**
	 * Re-read the latest release of one installed GitHub plugin and fold it into core's update data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function refresh( WP_REST_Request $request ) {
		$repo  = (string) $request->get_param( 'repo' );
		$files = array();

		foreach ( WP_Github_Updater::managed_plugins() as $file => $plugin ) {
			if ( 0 === strcasecmp( $plugin['repo'], $repo ) ) {
				$files[ $file ] = $plugin;
			}
		}

		if ( '' === $repo || array() === $files ) {
			return new WP_Error( 'wpghu_unknown_repo', __( 'No installed plugin is updated from this repository.', 'wp-github-updater' ), array( 'status' => 404 ) );
		}

		$throttle = self::THROTTLE . md5( strtolower( $repo ) );
		if ( false !== get_site_transient( $throttle ) ) {
			return new WP_Error( 'wpghu_too_soon', __( 'This repository was refreshed a moment ago.', 'wp-github-updater' ), array( 'status' => 429 ) );
		}
		set_site_transient( $throttle, 1, self::INTERVAL );

		$release = WP_Github_Updater::latest_release( reset( $files )['repo'], true );
		if ( null === $release ) {
			return new WP_Error( 'wpghu_no_release', __( 'GitHub did not return a release.', 'wp-github-updater' ), array( 'status' => 502 ) );
		}

		// Writing the transient back runs the injection filter, so the Updates screen and its badge show the release without a check against wordpress.org.
		$updates = get_site_transient( 'update_plugins' );
		if ( is_object( $updates ) ) {
			set_site_transient( 'update_plugins', $updates );
		}

		$rows = array();
		foreach ( $files as $file => $plugin ) {
			$rows[] = array(
				'plugin'    => $file,
				'installed' => $plugin['version'],
				'latest'    => $release['version'],
				'update'    => version_compare( $release['version'], $plugin['version'], '>' ),
			);
		}

		return new WP_REST_Response( $rows );
	}
}
