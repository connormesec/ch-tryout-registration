<?php
/**
 * Self-hosted plugin updates from GitHub Releases.
 *
 * Mirrors the Puck Press update mechanism. Workflow:
 *   1. Bump the version (header + CH_TRYOUT_VERSION) and push.
 *   2. Tag a release on GitHub and tick "Set as the latest release".
 *   3. Live sites poll the GitHub API, see that the latest release's tag is
 *      newer than the installed CH_TRYOUT_VERSION, and show the normal
 *      WordPress "update available" prompt under Plugins — one-click install.
 *
 * We read /releases/latest specifically, so ONLY the release you mark as
 * "latest" triggers the prompt (drafts and pre-releases are ignored). That is
 * the "set it to current" switch.
 *
 * Public repo → no token needed. For a private repo, or to lift GitHub's
 * 60-request/hour unauthenticated rate limit (all sites share one host IP),
 * define a token in wp-config.php:
 *
 *     define( 'CH_TRYOUT_GH_TOKEN', 'github_pat_...' );  // Contents: read
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CH_Tryout_Updater {

	/** @var string Absolute path to the main plugin file. */
	private $file;

	/** @var string e.g. "ch-tryout-registration/ch-tryout-registration.php". */
	private $basename;

	/** @var string Plugin folder slug, e.g. "ch-tryout-registration". */
	private $slug;

	/** @var string GitHub owner. */
	private $username;

	/** @var string GitHub repository. */
	private $repository;

	/** @var array|null Per-request cache of the release payload. */
	private $release;

	const CACHE_KEY = 'ch_tryout_update_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public function __construct( $file, $username, $repository ) {
		$this->file       = $file;
		$this->basename   = plugin_basename( $file );
		$this->slug       = dirname( $this->basename ); // "ch-tryout-registration"
		$this->username   = $username;
		$this->repository = $repository;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );

		if ( $this->token() ) {
			add_filter( 'http_request_args', array( $this, 'authorize_download' ), 10, 2 );
		}
	}

	/** Optional PAT for private repos / higher rate limits. */
	private function token() {
		return defined( 'CH_TRYOUT_GH_TOKEN' ) && CH_TRYOUT_GH_TOKEN ? CH_TRYOUT_GH_TOKEN : '';
	}

	/** Normalize a tag like "v1.2.3" to a comparable "1.2.3". */
	private function normalize_version( $tag ) {
		return ltrim( (string) $tag, 'vV' );
	}

	/**
	 * Fetch the release marked "Latest" on GitHub, cached in a transient to
	 * protect the shared host IP's rate limit. WordPress's "Check again"
	 * (force-check) busts the cache for an immediate re-poll.
	 *
	 * @return array|null Decoded release, or null if none / on error.
	 */
	private function get_release() {
		if ( null !== $this->release ) {
			return $this->release ? $this->release : null;
		}

		if ( ! empty( $_GET['force-check'] ) ) {
			delete_transient( self::CACHE_KEY );
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			$this->release = $cached;
			return $cached ? $cached : null;
		}

		$url     = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $this->username, $this->repository );
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'ch-tryout-registration/' . CH_TRYOUT_VERSION . '; ' . home_url(),
		);
		if ( $this->token() ) {
			$headers['Authorization'] = 'Bearer ' . $this->token();
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );

		$body = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) )
			? json_decode( wp_remote_retrieve_body( $response ), true )
			: null;

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			// Cache the miss briefly so a 404 (no release yet) / rate-limit / outage
			// isn't hammered on every admin page load.
			set_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
			$this->release = array();
			return null;
		}

		set_transient( self::CACHE_KEY, $body, self::CACHE_TTL );
		$this->release = $body;
		return $body;
	}

	/**
	 * Best download URL for a release: a single attached .zip asset if present
	 * (so you can ship a pre-structured zip), otherwise GitHub's source zipball.
	 */
	private function package_url( $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
					return $asset['browser_download_url'];
				}
			}
		}
		return isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
	}

	/**
	 * Inject our release into the update_plugins transient when it's newer than
	 * the installed version, so WordPress shows the standard update prompt.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) || ! isset( $transient->checked[ $this->basename ] ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $transient;
		}

		$installed = $transient->checked[ $this->basename ];
		$remote    = $this->normalize_version( $release['tag_name'] );
		$home      = 'https://github.com/' . $this->username . '/' . $this->repository;

		if ( version_compare( $remote, $installed, 'gt' ) ) {
			$transient->response[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote,
				'url'         => $home,
				'package'     => $this->package_url( $release ),
			);
		} else {
			// Mark as current so the Updates screen / auto-update UI behaves.
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $installed,
				'url'         => $home,
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Supply the "View details" popup (release notes) for our plugin.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data  = get_plugin_data( $this->file, false, false );
		$notes = ! empty( $release['body'] ) ? $release['body'] : 'See GitHub for release notes.';

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $this->slug,
			'version'       => $this->normalize_version( $release['tag_name'] ),
			'author'        => $data['Author'],
			'homepage'      => 'https://github.com/' . $this->username . '/' . $this->repository,
			'download_link' => $this->package_url( $release ),
			'last_updated'  => isset( $release['published_at'] ) ? $release['published_at'] : '',
			'sections'      => array(
				'description' => $data['Description'],
				'changelog'   => wpautop( esc_html( $notes ) ),
			),
		);
	}

	/**
	 * GitHub's source zipball unpacks to "<owner>-<repo>-<sha>/". Rename that
	 * working folder to the plugin slug so WordPress installs it to the right
	 * directory and cleanly replaces the old copy. No-op when a correctly-named
	 * asset zip was used instead.
	 *
	 * @param string $source        Unpacked source folder.
	 * @param string $remote_source Parent working dir.
	 * @param object $upgrader
	 * @param array  $hook_extra
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = null ) {
		global $wp_filesystem;

		$is_ours = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->basename;

		// Fallback (e.g. bulk updates with no per-item hook_extra): match the
		// zipball folder name "<owner>-<repo>-...".
		if ( ! $is_ours ) {
			$prefix = $this->username . '-' . $this->repository . '-';
			if ( 0 !== strpos( basename( untrailingslashit( $source ) ), $prefix ) ) {
				return $source;
			}
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ) ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Attach the token when WordPress downloads the package (private repos).
	 * Scoped to api.github.com — the zipball_url lives there and 302-redirects
	 * to codeload with a one-time token already in the URL, so we must not leak
	 * our header onto the redirect target.
	 *
	 * @param array  $args
	 * @param string $url
	 * @return array
	 */
	public function authorize_download( $args, $url ) {
		if ( $this->token() && false !== strpos( $url, 'api.github.com' ) ) {
			$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . $this->token();
		}
		return $args;
	}
}

/*
 * Boot the updater only where WordPress actually checks for plugin updates:
 * admin requests and cron. Keeps it off the front end entirely.
 */
if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	new CH_Tryout_Updater( CH_TRYOUT_FILE, 'connormesec', 'ch-tryout-registration' );
}
