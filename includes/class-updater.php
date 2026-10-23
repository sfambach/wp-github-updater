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
	private const CACHE_PREFIX  = 'wpghu_release2_';
	private const CACHE_TTL     = HOUR_IN_SECONDS;
	private const ERROR_TTL     = 30 * MINUTE_IN_SECONDS;
	private const API_RELEASE   = 'https://api.github.com/repos/%s/releases/latest';
	private const RELEASE_PAGE  = 'https://github.com/%s/releases/tag/%s';
	private const LATEST_PAGE   = 'https://github.com/%s/releases/latest';
	private const TAG_ARCHIVE   = 'https://github.com/%s/archive/refs/tags/%s.zip';
	private const ASSET         = 'https://github.com/%s/releases/download/%s/%s';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		/** @see https://developer.wordpress.org/reference/hooks/extra_plugin_headers/ */
		add_filter( 'extra_plugin_headers', array( self::class, 'add_header' ) );

		/** @see https://developer.wordpress.org/reference/hooks/pre_set_site_transient_transient/ */
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'inject_updates' ) );

		// Core refreshes update_plugins only every 12 hours; reading through our own hourly cache shows a new release without waiting for that.
		/** @see https://developer.wordpress.org/reference/hooks/site_transient_transient/ */
		add_filter( 'site_transient_update_plugins', array( self::class, 'inject_updates' ) );

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

		static $headers = array();

		$managed = array();
		foreach ( get_plugins() as $file => $data ) {
			// get_plugins() caches per request; if anything listed the plugins before our header filter was added, the header is missing there, so read it from the file.
			if ( ! array_key_exists( $file, $headers ) ) {
				$headers[ $file ] = (string) ( $data[ self::HEADER ] ?? '' );
				if ( '' === $headers[ $file ] && is_readable( WP_PLUGIN_DIR . '/' . $file ) ) {
					$headers[ $file ] = (string) get_file_data( WP_PLUGIN_DIR . '/' . $file, array( 'repo' => self::HEADER ) )['repo'];
				}
			}
			$repo = self::parse_repo( $headers[ $file ] );
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
		$cached = get_site_transient( $key );

		if ( ! $fresh && is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}

		$release = self::release_from_api( $repo ) ?? self::release_from_redirect( $repo );

		if ( null === $release ) {
			// A failed check (rate limit, timeout) must not hide a release we already know.
			$known = is_array( $cached ) && array() !== $cached ? $cached : null;
			set_site_transient( $key, $known ?? array(), self::ERROR_TTL );
			return $known;
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

		$tag    = (string) $data['tag_name'];
		$source = (string) ( $data['zipball_url'] ?? '' );
		$asset  = self::zip_asset( $repo, is_array( $data['assets'] ?? null ) ? $data['assets'] : array() );

		return array(
			'version'   => ltrim( $tag, 'vV' ),
			'tag'       => $tag,
			'package'   => '' !== $asset ? $asset : $source,
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

		$tag    = rawurldecode( $match[1] );
		$source = sprintf( self::TAG_ARCHIVE, $repo, rawurlencode( $tag ) );
		$asset  = self::guess_zip_asset( $repo, $tag );

		return array(
			'version'   => ltrim( $tag, 'vV' ),
			'tag'       => $tag,
			'package'   => '' !== $asset ? $asset : $source,
			'url'       => sprintf( self::RELEASE_PAGE, $repo, rawurlencode( $tag ) ),
			'body'      => '',
			'published' => '',
		);
	}

	/**
	 * Download URL of the built ZIP attached to a release, if any.
	 *
	 * Plugins with a build step (Composer vendor/, compiled blocks) only work
	 * from that asset; the source archive lacks the generated files.
	 *
	 * @param string                           $repo   owner/repo.
	 * @param array<int, array<string, mixed>> $assets Release assets from the API.
	 * @return string Empty when the release has no ZIP asset.
	 */
	private static function zip_asset( string $repo, array $assets ): string {
		$name  = basename( $repo );
		$first = '';

		foreach ( $assets as $asset ) {
			$file = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );
			if ( '' === $url || ! str_ends_with( strtolower( $file ), '.zip' ) ) {
				continue;
			}
			if ( str_starts_with( $file, $name ) ) {
				return $url;
			}
			if ( '' === $first ) {
				$first = $url;
			}
		}

		return $first;
	}

	/**
	 * Probe the usual asset names when only the web redirect is available.
	 *
	 * @param string $repo owner/repo.
	 * @param string $tag  Release tag.
	 * @return string Empty when none of them exists.
	 */
	private static function guess_zip_asset( string $repo, string $tag ): string {
		$name = basename( $repo );

		foreach ( array_unique( array( $name . '-' . $tag . '.zip', $name . '-' . ltrim( $tag, 'vV' ) . '.zip', $name . '.zip' ) ) as $file ) {
			$url      = sprintf( self::ASSET, $repo, rawurlencode( $tag ), rawurlencode( $file ) );
			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 0,
				)
			);
			$code     = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				return $url;
			}
		}

		return '';
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

		static $forced = false;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by core's "Check again" link.
		$fresh  = ! $forced && isset( $_GET['force-check'] );
		$forced = $forced || $fresh;

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
			} else {
				unset( $transient->response[ $file ] );
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
	 * Rename the unpacked folder ("owner-repo-sha" for zipballs, the repo name for assets) to the installed plugin folder.
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

