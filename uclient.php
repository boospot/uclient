<?php

// exit if file is called directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


if ( ! class_exists( 'Uclient' ) ) {

	class Uclient {
		/**
		 * The API endpoint. Configured through the class's constructor.
		 *
		 * @var String  The API endpoint.
		 */
		private $api_endpoint;

		/**
		 * @var string secret key from SLM License Server
		 */
		private $secret_key;

		private $license_key;

		private $license_email;


		/**
		 * The type of the installation in which this class is being used.
		 *
		 * @var string  'theme' or 'plugin'.
		 */
		private $type;


		/**
		 * @var string  The absolute path to the plugin's main file. Only applicable when using the
		 *              class with a plugin.
		 */
		private $plugin_file;

		private $plugin_slug;

		private $error_code;

		private $error_message;


		/**
		 * Initializes the license manager client.
		 *
		 * @param $product_id   string  The text id (slug) of the product on the license manager site
		 * @param $product_name string  The name of the product, used for menus
		 * @param $text_domain  string  Theme / plugin text domain, used for localizing the settings screens.
		 * @param $api_url      string  The URL to the license manager API (your license server)
		 * @param $type         string  The type of project this class is being used in ('theme' or 'plugin')
		 * @param $plugin_file  string  The full path to the plugin's main file (only for plugins)
		 */

		public function __construct(
			$api_endpoint, $license_key,
			$type = 'theme', $plugin_file = '', $plugin_data = ''
		) {


			// This is for testing only!
			set_site_transient( 'update_plugins', null );


			// Store setup data
//			$this->secret_key    = sanitize_text_field( $secret_key );
			$this->api_endpoint = esc_url_raw( $api_endpoint );
			$this->license_key  = sanitize_key( $license_key );
//			$this->license_email = sanitize_email( $license_email );
			$this->type        = sanitize_key( $type );
			$this->plugin_file = $plugin_file;
			$this->plugin_slug = plugin_basename( $this->plugin_file );

			if ( $type === 'theme' ) {
				// Check for updates (for themes)
				add_filter( 'pre_set_site_transient_update_themes', array( $this, 'check_for_update' ) );
			} elseif ( $type === 'plugin' ) {
				// Check for updates (for plugins)
				add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
				// Showing plugin information
				add_filter( 'plugins_api', array( $this, 'plugins_api_handler' ), 10, 3 );


			}


		}

		public function get_text_domain() {
			switch ( $this->type ) {
				case( 'plugin' ):

					return $this->get_plugin_data_attr( 'TextDomain' );
					break;

				case( 'theme' ):
					// it is a theme

					$my_theme = wp_get_theme();
					if ( $my_theme->exists() ) {
						return $my_theme->get( 'TextDomain' );
					} else {
						return false;
					}
					break;

				default:
//                    it is not a theme or plugin, i don't know what it might be ;)
					return false;
			}
		}


		//
		// API HELPER FUNCTIONS
		//

		/**
		 * Makes a call to the WP License Manager API.
		 *
		 * @param $method   String  The API action to invoke on the license manager site
		 * @param $params   array   The parameters for the API call
		 *
		 * @return          array   The API response
		 */


		private function call_api( $action, $params ) {
			$url = $this->api_endpoint;

			// Append parameters for GET request
			$url .= "action=$action&" . http_build_query( $params );

//			$url .= "action=$action&license=" . $this->license_key;
			// Send the request
			$request = wp_remote_get( $url );


			if ( is_wp_error( $request ) ) {
				return false;
			}

			$response_body = wp_remote_retrieve_body( $request );
			$response      = (object) json_decode( $response_body, true );

//			var_dump_pretty( $response );
//			die();
//			check to see if there is error in api call
			if ( $this->is_api_error( $response ) ) {

//		    If there is error, log error and return false

				$this->log_error();
				add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
//				add_action( "after_plugin_row_{$this->plugin_slug}", array( $this, 'show_plugin_notices' ) ,11 , 2);
				add_action( "in_plugin_update_message-{$this->plugin_slug}", array(
					$this,
					'show_plugin_notices_update'
				), 10, 2 );

//				return false;
			}


			return $response;
		}

		/**
		 * Checks the API response to see if there was an error.
		 *
		 * @param $response mixed|object    The API response to verify
		 *
		 * @return bool     True if there was an error. Otherwise false.
		 */
		private function is_api_error( $response ) {
			if ( $response === false ) {
				return true;
			}


			if ( ! is_object( $response ) ) {
				return true;
			}


			if ( isset( $response->error ) && $response->error ) {
//				rao_var_dump( $response);

				$this->error_code    = $response->message_code;
				$this->error_message = $response->message;

				return true;
			}

			return false;
		}

		/**
		 * Calls the License Manager API to get the license information for the
		 * current product.
		 *
		 * @return object|bool   The product data, or false if API call fails.
		 */
		public function get_license_info() {

			$params = array(
//				'secret_key'        => $this->secret_key,
				'license'           => $this->license_key,
//				'license_email'     => $this->license_email,
				'registered_domain' => $_SERVER['SERVER_NAME']
			);

			/*
			 * Call to API
			 */

			$license_info = $this->call_api( 'get', $params );

//			var_dump( $license_info);
//            die();

			// Its not an error, so we can activate the license for the server
			if ( $this->is_licence_pending( $license_info ) ) {

				$license_activation_bool = $this->activate_pending_license( $params );

				// if license activation is not done, we return false
				if ( ! $license_activation_bool ) {
					return false;
				} else {
					// Re-fetch the data from API after activating the license

					$license_info = $this->call_api( 'get', $params );

				}

			}

//			rao_var_dump( $license_info);


			return $license_info;


		}

		private function activate_pending_license( $params ) {

			$license_activation = $this->call_api( 'slm_activate', $params );

//			var_dump( $license_activation );
//			die();

			if ( $license_activation->result === 'success' ) {
				return true;
			} else {
				return false;
			}
		}


		private function is_licence_pending( $license_info ) {

			return ( isset( $license_info->status ) && $license_info->status === 'pending' ) ? true : false;
		}


		public function get_digital_asset_info() {


//			TODO: Cache API response and then use afterwards
//
//          Try to get License info
			$license_info = $this->get_license_info();

			// If we did not receive the digital asst related info, bail-out
			if ( ! isset( $license_info->digital_asset ) || empty( $license_info->digital_asset ) ) {
				return false;
			}

			// we are sure that we have digital_asset data and
			$digital_asset = $license_info->digital_asset;

			return (object) $digital_asset;


		}

		/**
		 * Checks the license manager to see if there is an update available for this theme.
		 *
		 * @return bool  Check to see if digital_asset information is received and if so, return the bool by checking version numbers
		 */
		public function is_update_available( $digital_asset ) {


			if ( ! version_compare( $digital_asset->version, $this->get_local_version(), '>' ) ) {
				return false;
			}

			return true;
		}

		/**
		 * @return string   The theme / plugin version of the local installation.
		 */
		private function get_local_version() {
			if ( $this->is_theme() ) {
				$theme_data = wp_get_theme();

				return $theme_data->Version;
			} else {
//				$plugin_data = get_plugin_data( $this->plugin_file, false );
				return $this->get_plugin_data_attr( 'Version' );
			}
		}


		private function get_plugin_data_attr( $attr ) {

//			https://developer.wordpress.org/reference/functions/get_plugin_data/
//            Available Attributes
//		  'Name' => 'Plugin Name',
//        'PluginURI' => 'Plugin URI',
//        'Version' => 'Version',
//        'Description' => 'Description',
//        'Author' => 'Author',
//        'AuthorURI' => 'Author URI',
//        'TextDomain' => 'Text Domain',
//        'DomainPath' => 'Domain Path',
//        'Network' => 'Network',
//        // Site Wide Only is deprecated in favor of Network.
//        '_sitewide' => 'Site Wide Only',

			$plugin_data = get_plugin_data( $this->plugin_file, false );

			return $plugin_data[ $attr ];

		}


		private function is_theme() {
			return $this->type == 'theme';
		}


		/**
		 * The filter that checks if there are updates to the theme or plugin
		 * using the WP License Manager API.
		 *
		 * @param $transient    mixed   The transient used for WordPress
		 *                              theme / plugin updates.
		 *
		 * @return mixed        The transient with our (possible) additions.
		 */
		public function check_for_update( $transient ) {
			if ( empty( $transient->checked ) ) {
				return $transient;
			}

			$digital_asset = $this->get_digital_asset_info(); // return false if license not valid

			if ( ! $digital_asset ) {
//
//      	    TODO: Feature: show admin notice that license is not valid or any other error


			}


//			Only work if we have digital_asset available
//			Check if Update available, if not, bail out and return $transient

			if ( $digital_asset && $this->is_update_available( $digital_asset ) ) {


				if ( $this->is_theme() ) {
					// Theme update
					$theme_data = wp_get_theme();
					$theme_slug = $theme_data->get_template();

					$transient->response[ $theme_slug ] = array(
						'new_version' => $digital_asset->version,
						'package'     => $digital_asset->download_url,
						'url'         => $digital_asset->homepage
					);
				} else {
					// Plugin updates will be added here.
					$plugin_slug = plugin_basename( $this->plugin_file );

					$transient->response[ $plugin_slug ] = (object) array(
						'new_version' => $digital_asset->version,
						'package'     => $digital_asset->download_url,
						'slug'        => $plugin_slug
					);
				}
			}

			return $transient;
		}

		/**
		 * A function for the WordPress "plugins_api" filter. Checks if
		 * the user is requesting information about the current plugin and returns
		 * its details if needed.
		 *
		 * This function is called before the Plugins API checks
		 * for plugin information on WordPress.org.
		 *
		 * @param $res      bool|object The result object, or false (= default value).
		 * @param $action   string      The Plugins API action. We're interested in 'plugin_information'.
		 * @param $args     array       The Plugins API parameters.
		 *
		 * @return object   The API response.
		 */
		public function plugins_api_handler( $res, $action, $args ) {
//			rao_var_dump( $res);
			if ( $action == 'plugin_information' ) {

				// If the request is for this plugin, respond to it
				if ( isset( $args->slug ) && $args->slug == plugin_basename( $this->plugin_file ) ) {
					$info = $this->get_digital_asset_info();

					if ( ! $info ) {
						// Digital_asset info could not be retrieved, let WordPress handle this.
						return $res;
					}

					// Insert the 'slug' from $args into the returned api response.
					// If we don do this, wordpress so not understand for which plugin the data was retrieved.
					$info = (object) array_merge( (array) $info, array( 'slug' => $args->slug ) );

					return $info;

				}
			}

			// Not our request, let WordPress handle this.
			return $res;
		}

		/**
		 *
		 */
		public function show_plugin_notices( $currentPluginMetadata, $newPluginMetadata ) {
			$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );

			printf(
				'<tr class="plugin-update-tr%s active" id="%s" data-slug="%s" data-plugin="%s">' .
				'<td colspan="%s" class="plugin-update colspanchange">' .
				'<div class="update-message notice inline %s notice-alt"><p>',
				'',
				esc_attr( $this->plugin_slug . '-update' ),
				esc_attr( $this->plugin_slug ),
				esc_attr( $this->plugin_slug ),
				esc_attr( $wp_list_table->get_column_count() ),
				'notice-error'
			);

			$format = __( "There was an error fetching the updates for <b>%s</b>, Details of error is <b>%s</b>. Please take necessary action to resolve it or contact the Plugin Author: %s at %s.", $this->get_text_domain() );
			printf(
				$format,
				$this->get_plugin_data_attr( 'Name' ),
				$this->get_api_error_text(),
				$this->get_plugin_data_attr( 'Author' ),
				$this->get_plugin_data_attr( 'AuthorURI' )
			);

			echo $format . '</p></div></td></tr>';

		}

		public function show_plugin_notices_update( $plugin_data, $response ) {

            echo '</p></div><div class="update-message notice inline notice-error notice-alt"><p>';
			$format = __( "There was an error fetching the updates for <b>%s</b>, Details of error is <b>%s</b>. Please take necessary action to resolve it or contact the Plugin Author: %s at %s.", $this->get_text_domain() );
			printf(
				$format,
				$this->get_plugin_data_attr( 'Name' ),
				$this->get_api_error_text(),
				$this->get_plugin_data_attr( 'Author' ),
				$this->get_plugin_data_attr( 'AuthorURI' )
			);

		}


		public function show_admin_notices() {

			?>
            <div class="notice notice-error is-dismissible">
                <p>
					<?php

					$format = __( "There was an error fetching the updates for <b>%s</b>, Details of error is <b>%s</b>. Please take necessary action to resolve it or contact the Plugin Author: %s at %s.", $this->get_text_domain() );
					printf(
						$format,
						$this->get_plugin_data_attr( 'Name' ),
						$this->get_api_error_text(),
						$this->get_plugin_data_attr( 'Author' ),
						$this->get_plugin_data_attr( 'AuthorURI' )
					);

					?>
                </p>

            </div>
			<?php
		}

		private function get_api_error_text() {
			return $this->error_message . " - [ Error Code: " . $this->error_code . ' ] ';
		}


		/**
		 * Log Errors to the Options
		 *
		 * @param string $text
		 * @param string $level
		 */
		private function log_error( $text = '', $level = 'i' ) {

			if ( empty( $text ) ) {
				$text = $this->get_api_error_text();
			}

			switch ( strtolower( $level ) ) {
				case 'e':
				case 'error':
					$level = 'ERROR';
					break;
				case 'i':
				case 'info':
					$level = 'INFO';
					break;
				case 'd':
				case 'debug':
					$level = 'DEBUG';
					break;
				default:
					$level = 'INFO';
			}

			$message = date( "[Y-m-d H:i:s]" ) . "\t[" . $level . "]\t[" . basename( __FILE__ ) . "]\t" . $text . "\n";


			//	TODO: Give the option to define the log option key, we may ask for it a instantiation of this class

			$original_stored_log = get_option( 'slm_updater_api_error_log' );

			update_option( 'slm_updater_api_error_log', $original_stored_log . PHP_EOL . $message );

		}


	}
}