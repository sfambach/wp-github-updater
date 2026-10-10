<?php
/**
 * Updates plugins that declare a "GitHub Plugin URI" header from their latest GitHub release.
 *
 * @package WpGithubUpdater
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feeds the latest public GitHub release of each such plugin into the core update flow.
 *
 * Works with the normal "Updates" screen; no token needed for public repositories.
 */
final class WP_Github_Updater {

	private const HEADER        = 'GitHub Plugin URI';
	private const CACHE_PREFIX  = 'wpghu_release_';
	private const CACHE_TTL     = 6 * HOUR_IN_SECONDS;
	private const ERROR_TTL     = 30 * MINUTE_IN_SECONDS;
	private const API_RELEASE   = 'https://api.github.com/repos/%s/releases/latest';
	private const RELEASE_PAGE  = 'https://github.com/%s/releases/tag/%s';
	private const LATEST_PAGE   = 'https://github.com/%s/releases/latest';
	private const TAG_ARCHIVE   = 'https://github.com/%s/archive/refs/tags/%s.zip';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		/** @see https://developer.wordpress.org/reference/hooks/extra_plugin_headers/ */
		add_filter( 'extra_plugin_headers', array( self::class, 'add_header' ) );

		/** @see https://developer.wordpress.org/reference/hooks/pre_set_site_transient_transient/ */
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'inject_updates' ) );

		/** @see https://developer.wordpress.org/reference/hooks/plugins_api/ */
		add_filter( 'plugins_api', array( self::class, 'plugin_info' ), 20, 3 );

		/** @see https://developer.wordpress.org/reference/hooks/upgrader_source_selection/ */
		add_filter( 'upgrader_source_selection', array( self::class, 'fix_source_dir' ), 10, 4 );

		/** @see https://developer.wordpress.org/reference/hooks/upgrader_process_complete/ */
		add_action( 'upgrader_process_complete', array( self::class, 'flush_cache' ) );
	}

	/**
	 * Let get_plugins() read the GitHub header.
	 *
	 * @param string[] $headers Extra header names.
	 * @return string[]
	 */
	public static function add_header( array $headers ): array {
		$headers[] = self::HEADER;
		return $headers;
	}

	/**
	 * Installed plugins with a GitHub header, keyed by plugin file.
	 *
	 * @return array<string, array{repo: string, version: string, name: string}>
	 */
	public static function managed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$managed = array();
		foreach ( get_plugins() as $file => $data ) {
			$repo = self::parse_repo( (string) ( $data[ self::HEADER ] ?? '' ) );
			if ( '' === $repo ) {
				continue;
			}
			$managed[ $file ] = array(
				'repo'    => $repo,
				'version' => (string) $data['Version'],
				'name'    => (string) $data['Name'],
			);
		}

		return $managed;
	}

	/**
	 * Normalize "owner/repo" or a github.com URL to "owner/repo".
	 *
	 * @param string $value Header value.
	 * @return string Empty when invalid.
	 */
	public static function parse_repo( string $value ): string {
		$value = trim( (string) preg_replace( '#^https?://github\.com/#i', '', trim( $value ) ), '/' );
		$value = (string) preg_replace( '#\.git$#', '', $value );

		return 1 === preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value ) ? $value : '';
	}

	/**
	 * Latest release of a repository, cached.
	 *
	 * @param string $repo  owner/repo.
	 * @param bool   $fresh Skip the cache (manual "check again").
	 * @return array{version: string, tag: string, package: string, url: string, body: string, published: string}|null
	 */
	public static function latest_release( string $repo, bool $fresh = false ): ?array {
		$key    = self::CACHE_PREFIX . md5( $repo );
		$cached = $fresh ? false : get_site_transient( $key );

		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}

		$release = self::release_from_api( $repo ) ?? self::release_from_redirect( $repo );

		if ( null === $release ) {
			set_site_transient( $key, array(), self::ERROR_TTL );
			return null;
		}

		set_site_transient( $key, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Latest release via the REST API.
	 *
	 * @param string $repo owner/repo.
	 * @return array{version: string, tag: string, package: string, url: string, body: string, published: string}|null
	 */
	private static function release_from_api( string $repo ): ?array {
		$response = wp_remote_get(
			sprintf( self::API_RELEASE, $repo ),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$tag = (string) $data['tag_name'];

		return array(
			'version'   => ltrim( $tag, 'vV' ),
			'tag'       => $tag,
			'package'   => (string) ( $data['zipball_url'] ?? '' ),
			'url'       => sprintf( self::RELEASE_PAGE, $repo, rawurlencode( $tag ) ),
			'body'      => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	/**
	 * Latest release from the redirect of github.com/owner/repo/releases/latest.
	 *
	 * The API allows 60 anonymous requests per hour and IP, which a shared host
	 * may use up; the web redirect has no such limit.
	 *
	 * @param string $repo owner/repo.
	 * @return array{version: string, tag: string, package: string, url: string, body: string, published: string}|null
	 */
	private static function release_from_redirect( string $repo ): ?array {
		$response = wp_remote_head(
			sprintf( self::LATEST_PAGE, $repo ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
			)
		);

		$location = (string) wp_remote_retrieve_header( $response, 'location' );

		if ( 1 !== preg_match( '#/releases/tag/([^/?\#]+)$#', $location, $match ) ) {
			return null;
		}

		$tag = rawurldecode( $match[1] );

		return array(
			'version'   => ltrim( $tag, 'vV' ),
			'tag'       => $tag,
			'package'   => sprintf( self::TAG_ARCHIVE, $repo, rawurlencode( $tag ) ),
			'url'       => sprintf( self::RELEASE_PAGE, $repo, rawurlencode( $tag ) ),
			'body'      => '',
			'published' => '',
		);
	}

	/**
	 * Add available updates to the core update transient.
	 *
	 * @param mixed $transient Value of update_plugins.
	 * @return mixed
	 */
	public static function inject_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by core's "Check again" link.
		$fresh = isset( $_GET['force-check'] );

		foreach ( self::managed_plugins() as $file => $plugin ) {
			$release = self::latest_release( $plugin['repo'], $fresh );
			if ( null === $release || '' === $release['package'] ) {
				continue;
			}

			$item = (object) array(
				'id'          => 'github.com/' . $plugin['repo'],
				'slug'        => dirname( $file ),
				'plugin'      => $file,
				'new_version' => $release['version'],
				'url'         => $release['url'],
				'package'     => $release['package'],
			);

			if ( version_compare( $release['version'], $plugin['version'], '>' ) ) {
				$transient->response[ $file ] = $item;
				unset( $transient->no_update[ $file ] );
			} elseif ( ! isset( $transient->response[ $file ] ) ) {
				$transient->no_update[ $file ] = $item;
			}
		}

		return $transient;
	}

	/**
	 * Details popup ("View version x.y.z details").
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		foreach ( self::managed_plugins() as $file => $plugin ) {
			if ( dirname( $file ) !== $args->slug ) {
				continue;
			}

			$release = self::latest_release( $plugin['repo'] );
			if ( null === $release ) {
				return $result;
			}

			return (object) array(
				'name'          => $plugin['name'],
				'slug'          => $args->slug,
				'version'       => $release['version'],
				'homepage'      => 'https://github.com/' . $plugin['repo'],
				'download_link' => $release['package'],
				'last_updated'  => $release['published'],
				'sections'      => array(
					'changelog' => wpautop( esc_html( $release['body'] ) ) . sprintf(
						'<p><a href="%1$s" target="_blank" rel="noopener">%2$s</a></p>',
						esc_url( $release['url'] ),
						esc_html__( 'Release on GitHub', 'wp-github-updater' )
					),
				),
			);
		}

		return $result;
	}

	/**
	 * GitHub zipballs unpack to "owner-repo-sha"; rename to the installed plugin folder.
	 *
	 * @param string|WP_Error $source        Unpacked source path.
	 * @param string          $remote_source Upgrade working directory.
	 * @param WP_Upgrader     $upgrader      Upgrader instance.
	 * @param array           $hook_extra    Extra data, contains 'plugin' on updates.
	 * @return string|WP_Error
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		$file = is_array( $hook_extra ) ? (string) ( $hook_extra['plugin'] ?? '' ) : '';

		if ( ! is_string( $source ) || '' === $file || ! isset( self::managed_plugins()[ $file ] ) ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . dirname( $file );

		if ( untrailingslashit( $source ) === $target ) {
			return $source;
		}

		if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), $target, true ) ) {
			return new WP_Error( 'wpghu_rename', __( 'Could not rename the downloaded plugin folder.', 'wp-github-updater' ) );
		}

		return trailingslashit( $target );
	}

	/**
	 * Drop cached releases after an update so the new version is checked fresh.
	 */
	public static function flush_cache(): void {
		foreach ( self::managed_plugins() as $plugin ) {
			delete_site_transient( self::CACHE_PREFIX . md5( $plugin['repo'] ) );
		}
	}
}

