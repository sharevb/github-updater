<?php
/**
 * GitHub Updater
 *
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 * @package   github-updater
 */

namespace Fragen\GitHub_Updater\Traits;

use Fragen\Singleton;
use Fragen\GitHub_Updater\Install;
use Fragen\GitHub_Updater\API\Bitbucket_API;
use Fragen\GitHub_Updater\API\Bitbucket_Server_API;
use Fragen\GitHub_Updater\API\GitHub_API;
use Fragen\GitHub_Updater\API\GitLab_API;
use Fragen\GitHub_Updater\API\Gitea_API;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait Basic_Auth_Loader
 */
trait Basic_Auth_Loader {
	/**
	 * Stores array of git servers requiring Basic Authentication.
	 *
	 * @var array
	 */
	private static $basic_auth_required = [ 'Bitbucket', 'GitHub', 'GitLab', 'Gitea' ];

	/**
	 * Load hooks for authentication headers.
	 *
	 * @access public
	 */
	public function load_authentication_hooks() {
		add_filter( 'http_request_args', [ $this, 'maybe_basic_authenticate_http' ], 5, 2 );
		add_filter( 'http_request_args', [ $this, 'http_release_asset_auth' ], 15, 2 );
	}

	/**
	 * Remove hooks for authentication headers.
	 *
	 * @access public
	 */
	public function remove_authentication_hooks() {
		remove_filter( 'http_request_args', [ $this, 'maybe_basic_authenticate_http' ] );
		remove_filter( 'http_request_args', [ $this, 'http_release_asset_auth' ] );
	}

	/**
	 * Add Basic Authentication $args to http_request_args filter hook.
	 *
	 * @access public
	 *
	 * @param array  $args Args passed to the URL.
	 * @param string $url  The URL.
	 *
	 * @return array $args
	 */
	public function maybe_basic_authenticate_http( $args, $url ) {
		$credentials = $this->get_credentials( $url );
		if ( ! $credentials['isset'] || $credentials['api.wordpress'] ) {
			return $args;
		}
		if ( null !== $credentials['token'] ) {
			if ( 'github' === $credentials['type'] || 'gitea' === $credentials['type'] ) {
				$args['headers']['Authorization'] = 'token ' . $credentials['token'];
			}
			if ( 'bitbucket' === $credentials['type'] ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$args['headers']['Authorization'] = 'Basic ' . base64_encode( $credentials['token'] );
			}
			if ( 'gitlab' === $credentials['type'] ) {
				// https://gitlab.com/gitlab-org/gitlab-foss/issues/63438.
				if ( ! $credentials['enterprise'] ) {
					// Used in GitLab v12.2 or greater.
					$args['headers']['Authorization'] = 'Bearer ' . $credentials['token'];
				} else {
					// Used in versions prior to GitLab v12.2.
					$args['headers']['PRIVATE-TOKEN'] = $credentials['token'];
				}
			}
		}

		remove_filter( 'http_request_args', [ $this, 'maybe_basic_authenticate_http' ] );

		return $args;
	}

	/**
	 * Get credentials for authentication headers.
	 *
	 * @access private
	 *
	 * @param string $url The URL.
	 *
	 * @return array $credentials
	 */
	private function get_credentials( $url ) {
		$options = get_site_option( 'github_updater' );
		$headers = parse_url( $url );
		// TODO: remove after debugging.
		if ( ! isset( $headers['host'] ) ) {
			error_log( $url );
		}
		$username_key = null;
		$password_key = null;
		$credentials  = [
			'api.wordpress' => 'api.wordpress.org' === $headers['host'],
			'isset'         => false,
			'token'         => null,
			'type'          => null,
			'enterprise'    => null,
		];
		$hosts        = [ 'bitbucket.org', 'api.bitbucket.org', 'github.com', 'api.github.com', 'gitlab.com' ];

		if ( $credentials['api.wordpress'] ) {
			return $credentials;
		}

		$repos = array_merge(
			Singleton::get_instance( 'Plugin', $this )->get_plugin_configs(),
			Singleton::get_instance( 'Theme', $this )->get_theme_configs()
		);
		$slug  = $this->get_slug_for_credentials( $headers, $repos, $url, $options );
		$type  = $this->get_type_for_credentials( $slug, $repos, $url );

		if ( false === $slug && ! in_array( $headers['host'], $hosts, true ) ) {
			return $credentials;
		}

		switch ( $type ) {
			case 'bitbucket':
			case $type instanceof Bitbucket_API:
			case $type instanceof Bitbucket_Server_API:
				$bitbucket_org   = in_array( $headers['host'], $hosts, true );
				$bitbucket_token = ! empty( $options['bitbucket_access_token'] ) ? $options['bitbucket_access_token'] : null;
				$bbserver_token  = ! empty( $options['bbserver_access_token'] ) ? $options['bbserver_access_token'] : null;
				$token           = ! empty( $options[ $slug ] ) ? $options[ $slug ] : null;
				$token           = null === $token && $bitbucket_org ? $bitbucket_token : $token;
				$token           = null === $token && ! $bitbucket_org ? $bbserver_token : $token;
				$type            = 'bitbucket';
				break;
			case 'github':
			case $type instanceof GitHub_API:
				$token = ! empty( $options['github_access_token'] ) ? $options['github_access_token'] : null;
				$token = ! empty( $options[ $slug ] ) ? $options[ $slug ] : $token;
				$type  = 'github';
				break;
			case 'gitlab':
			case $type instanceof GitLab_API:
				$token = ! empty( $options['gitlab_access_token'] ) ? $options['gitlab_access_token'] : null;
				$token = ! empty( $options[ $slug ] ) ? $options[ $slug ] : $token;
				$type  = 'gitlab';
				break;
			case 'gitea':
			case $type instanceof Gitea_API:
				$token = ! empty( $options['gitea_access_token'] ) ? $options['gitea_access_token'] : null;
				$token = ! empty( $options[ $slug ] ) ? $options[ $slug ] : $token;
				$type  = 'gitea';
		}

		$credentials['isset']      = true;
		$credentials['type']       = $type;
		$credentials['token']      = isset( $token ) ? $token : null;
		$credentials['enterprise'] = ! in_array( $headers['host'], $hosts, true );

		return $credentials;
	}

	/**
	 * Get $slug for Basic Auth credentials.
	 *
	 * @param array  $headers Array of headers from parse_url().
	 * @param array  $repos   Array of repositories.
	 * @param string $url     URL being called by API.
	 * @param array  $options Array of site options.
	 *
	 * @return bool|string $slug
	 */
	private function get_slug_for_credentials( $headers, $repos, $url, $options ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$slug = isset( $_REQUEST['slug'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['slug'] ) ) : false;
		$slug = ! $slug && isset( $_REQUEST['plugin'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) : $slug;

		// Some installers, like TGMPA, pass an array.
		$slug = is_array( $slug ) ? array_pop( $slug ) : $slug;

		$slug = false !== strpos( $slug, '/' ) ? dirname( $slug ) : $slug;

		// Set for bulk upgrade.
		if ( ! $slug ) {
			$plugins     = isset( $_REQUEST['plugins'] )
				? array_map( 'dirname', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['plugins'] ) ) ) )
				: [];
			$themes      = isset( $_REQUEST['themes'] )
				? explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['themes'] ) ) )
				: [];
			$bulk_update = array_merge( $plugins, $themes );
			if ( ! empty( $bulk_update ) ) {
				$slug = array_filter(
					$bulk_update,
					function ( $e ) use ( $url ) {
						return false !== strpos( $url, $e );
					}
				);
				$slug = array_pop( $slug );
			}
		}
		// phpcs:enable

		// In case $type set from Base::$caller doesn't match.
		if ( ! $slug && isset( $headers['path'] ) ) {
			$path_arr = explode( '/', $headers['path'] );
			foreach ( $path_arr as $key ) {
				$key = basename( rawurldecode( $key ) ); // For GitLab.
				if ( ! empty( $options[ $key ] ) || array_key_exists( $key, $repos ) ) {
					$slug = $key;
					break;
				}
			}
		}

		return $slug;
	}

	/**
	 * Get repo type for Basic Auth credentials.
	 *
	 * @param string $slug  Repository slug.
	 * @param array  $repos Array of repositories.
	 * @param string $url   URL being called by API.
	 *
	 * @return string $slug
	 */
	private function get_type_for_credentials( $slug, $repos, $url ) {
		$type = $this->get_class_vars( 'Base', 'caller' );

		$type = $slug && isset( $repos[ $slug ] ) && property_exists( $repos[ $slug ], 'git' )
			? $repos[ $slug ]->git
			: $type;

		// Set for WP-CLI.
		if ( ! $slug ) {
			foreach ( $repos as $repo ) {
				if ( property_exists( $repo, 'download_link' ) && $url === $repo->download_link ) {
					$type = $repo->git;
					break;
				}
			}
		}

		// Set for Remote Install.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$type = isset( $_POST['github_updater_api'], $_POST['github_updater_repo'] )
				&& false !== strpos( $url, basename( sanitize_text_field( wp_unslash( $_POST['github_updater_repo'] ) ) ) )
			? sanitize_text_field( wp_unslash( $_POST['github_updater_api'] ) )
			: $type;
		// phpcs:enable

		return $type;
	}

	/**
	 * Removes Basic Authentication header for Release Assets.
	 * Storage in AmazonS3 buckets, uses Query String Request Authentication Alternative.
	 *
	 * @access public
	 * @link   http://docs.aws.amazon.com/AmazonS3/latest/dev/RESTAuthentication.html#RESTAuthenticationQueryStringAuth
	 *
	 * @param array  $args The URL arguments passed.
	 * @param string $url  The URL.
	 *
	 * @return array $args
	 */
	public function http_release_asset_auth( $args, $url ) {
		$aws_host        = false !== strpos( $url, 's3.amazonaws.com' );
		$github_releases = false !== strpos( $url, 'releases/download' );

		if ( $aws_host || $github_releases ) {
			unset( $args['headers']['Authorization'] );
		}
		remove_filter( 'http_request_args', [ $this, 'http_release_asset_auth' ] );

		return $args;
	}
}
