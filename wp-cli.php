<?php
/**
	Copyright 2015-2018 Mika Epstein (email: ipstenu@halfelf.org)
	
	This file is part of Varnish HTTP Purge, a plugin for WordPress.

	Varnish HTTP Purge is free software: you can redistribute it and/or modify
	it under the terms of the Apache License 2.0 license.

	Varnish HTTP Purge is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
*/

if ( !defined( 'ABSPATH' ) ) die();

// Bail if WP-CLI is not present
if ( !defined( 'WP_CLI' ) ) return;

/**
 * WP-CLI Commands
 */
 
if ( !class_exists( 'WP_CLI_Varnish_Command' ) ) {
	class WP_CLI_Varnish_Command extends WP_CLI_Command {
	
		private $wildcard = false;
	
		public function __construct() {
			$this->varnish_purge = new VarnishPurger();
		}
		
		/**
		 * Forces a full Varnish Purge of the entire site (provided
		 * regex is supported). Alternately you can fluxh the cache
		 * for specific pages or folders (using the --wildcard param)
		 * 
		 * ## EXAMPLES
		 * 
		 *		wp varnish purge
		 *
		 *		wp varnish purge http://example.com/wp-content/themes/twentyeleventy/style.css
		 *
		 *		wp varnish purge http://example.com/wp-content/themes/ --wildcard
		 *
		 */
		
		function purge( $args, $assoc_args ) {	
			
			$wp_version = get_bloginfo( 'version' );
			$cli_version = WP_CLI_VERSION;
			
			// Set the URL/path
			if ( !empty($args) ) { list( $url ) = $args; }
	
			// If wildcard is set, or the URL argument is empty
			// then treat this as a full purge
			$pregex = $wild = '';
			if ( isset( $assoc_args['wildcard'] ) || empty($url) ) {
				$pregex = '/?vhp-regex';
				$wild = ".*";
			}
	
			wp_create_nonce('vhp-flush-cli');
	
			// If the URL is not empty, sanitize. Else use home URL.
			if ( !empty( $url ) ) {
				$url = esc_url( $url );
				
				// If it's a regex, let's make sure we don't have //
				if ( isset( $assoc_args['wildcard'] ) ) $url = rtrim( $url, '/' );
			} else {
				$url = $this->varnish_purge->the_home_url();
			}
			
			if ( version_compare( $wp_version, '4.6', '>=' ) && ( version_compare( $cli_version, '0.25.0', '<' ) || version_compare( $cli_version, '0.25.0-alpha', 'eq' ) ) ) {
				
				WP_CLI::log( sprintf( __( 'This plugin does not work on WP 4.6 and up, unless WP-CLI is version 0.25.0 or greater. You\'re using WP-CLI %s and WordPress %s.', 'varnish-http-purge' ), $cli_version, $wp_version ) );
				WP_CLI::log( __( 'To flush your cache, please run the following command:', 'varnish-http-purge' ) );
				WP_CLI::log( sprintf( '$ curl -X PURGE "%s"' , $url . $wild ) );
				WP_CLI::error( __( 'Your cache must be purged manually.', 'varnish-http-purge' ) );
			}
	
			$this->varnish_purge->purgeUrl( $url . $pregex );
			
			if ( WP_DEBUG == true ) {
				WP_CLI::log( sprintf( __( 'Varnish HTTP Purge is flushing the URL %s with params %s.', 'varnish-http-purge' ), $url, $pregex ) );
			}
	
			WP_CLI::success( __( 'Varnish HTTP Purge has flushed your cache.', 'varnish-http-purge' ) );
		}

		/**
		 * Activate, deactivate, or toggle Development Mode.
		 * 
		 * ## OPTIONS
		 *
		 * [<state>]
		 * : Change the state of Development Mode
		 * ---
		 * options:
		 *   - activate
		 *   - deactivate
		 *   - toggle
		 * ---
		 *
		 * ## EXAMPLES
		 * 
		 *		wp varnish devmode activate
		 *		wp varnish devmode deactivate
		 *		wp varnish devmode toggle
		 *
		 */
		function devmode( $args, $assoc_args ) {
		
			$valid_modes = array( 'activate', 'deactivate', 'toggle' );
			$devmode     = get_site_option( 'vhp_varnish_devmode', VarnishPurger::$devmode );

			// Check for valid arguments
			if ( empty( $args[0] ) ) {
				// No params, echo state
				$state  = ( $devmode['active'] )? __( 'activated', 'varnish-http-purge' ) : __( 'deactivated', 'varnish-http-purge' );
				WP_CLI::success( sprintf( __( 'Varnish HTTP Purge development mode is currently %s.', 'varnish-http-purge' ), $state ) );
			} elseif ( !in_array( $args[0], $valid_modes ) ) {
				// Invalid Params, warn
				WP_CLI::error( sprintf( __( '%s is not a valid subcommand for varnish development mode.', 'varnish-http-purge'), sanitize_text_field( $args[0] ) ) );
			} else {
				// Run the toggle!
				$result = VarnishDebug::devmode_toggle( sanitize_text_field( $args[0] ) );	
				$state  = ( $result )? __( 'activated', 'varnish-http-purge' ) : __( 'deactivated', 'varnish-http-purge' );
				WP_CLI::success( sprintf( __( 'Varnish HTTP Purge development mode has been %s.', 'varnish-http-purge' ), $state ) );
			}
		} // End devmode
	
		/**
		 * Runs a debug check of the site to see if there are any known 
		 * issues that would stop Varnish from caching.
		 *
		 * ## OPTIONS
		 *
		 * [<url>]
		 * : Specify a URL for testing against. Default is the home URL.
		 *
		 * [--include-headers]
		 * : Include headers in debug check output.
		 *
		 * [--include-grep]
		 * : Also grep active theme and plugin directories for common issues.
		 *
		 * [--format=<format>]
		 * : Render output in a particular format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 * 
		 *		wp varnish debug
		 *
		 *		wp varnish debug http://example.com/wp-content/themes/twentyeleventy/style.css
		 *
		 */
		
		function debug( $args, $assoc_args ) {
	
			// Set the URL/path
			if ( !empty($args) ) list( $url ) = $args;

			if ( empty( $url ) ) {
				$url = esc_url( $this->varnish_purge->the_home_url() );;
			}

			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'include-grep' ) ) {
				$pattern = '(PHPSESSID|session_start|start_session|$cookie|setCookie)';
				WP_CLI::log( 'Grepping for: ' . $pattern );
				WP_CLI::log( '' );
				$paths = array(
					get_template_directory(),
					get_stylesheet_directory(),
				);
				foreach ( wp_get_active_and_valid_plugins() as $plugin_path ) {
					// We don't care about our own plugin.
					if ( false !== stripos( $plugin_path, 'varnish-http-purge/varnish-http-purge.php' ) ) {
						continue;
					}
					$paths[] = dirname( $plugin_path );
				}
				$paths = array_unique( $paths );
				foreach ( $paths as $path ) {
					$cmd = sprintf(
						"grep -RE '%s' %s",
						$pattern,
						escapeshellarg( $path )
					);
					passthru( $cmd );
				}
				WP_CLI::log( '' );
				WP_CLI::log( 'Grep complete.' );
			}

			// Include the debug code
			if ( !class_exists( 'VarnishDebug' ) ) include( 'debug.php' );

			// Validate the URL
			$valid_url = VarnishDebug::is_url_valid( $url );

			if ( $valid_url !== 'valid' ) {
				switch ( $valid_url ) {
					case 'empty':
					case 'domain':
						WP_CLI::error( __( 'You must provide a URL on your own domain to scan.', 'varnish-http-purge' ) );
						break;
					case 'invalid':
						WP_CLI::error( __( 'You have entered an invalid URL address.', 'varnish-http-purge' ) );
						break;
					default:
						WP_CLI::error( __( 'An unknown error has occurred.', 'varnish-http-purge' ) );
						break;
				}
			}
			$varnishurl = get_site_option( 'vhp_varnish_url', $url );
	
			// Get the response and headers
			$remote_get = VarnishDebug::remote_get( $varnishurl );
			$headers    = wp_remote_retrieve_headers( $remote_get );

			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'include-headers' ) ) {
				WP_CLI::log( 'Headers:' );
				foreach ( $headers as $key => $value ) {
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					WP_CLI::log( " - {$key}: {$value}" );
				}
			}

			// Preflight checklist
			$preflight = VarnishDebug::preflight( $remote_get );
	
			// Check for Remote IP
			$remote_ip = VarnishDebug::remote_ip( $headers );

			// Get the Varnish IP
			if ( VHP_VARNISH_IP != false ) {
				$varniship = VHP_VARNISH_IP;
			} else {
				$varniship = get_site_option('vhp_varnish_ip');
			}

			if ( $preflight['preflight'] == false ) {
				WP_CLI::error( $preflight['message'] );
			} else {
				$results = VarnishDebug::get_all_the_results( $headers, $remote_ip, $varniship );

				// Generate array
				foreach ( $results as $type => $content ) { 
					$items[] = array(
						'name'    => $type,
						'status'  => ucwords( $content['icon'] ),
						'message' => $content['message'],
					);
				}

				$format = ( isset( $assoc_args['format'] ) )? $assoc_args['format'] : 'table';

				// Output the data
				WP_CLI\Utils\format_items( $format, $items, array( 'name', 'status', 'message' ) );
			}
		} // End Debug

	}
}

WP_CLI::add_command( 'varnish', 'WP_CLI_Varnish_Command' );