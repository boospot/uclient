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

		private $version = 1.0;

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
			$api_endpoint,
			$license_key,
			$asset_identifier,
			$type = 'plugin',
			$plugin_file = '',
			$plugin_settings_page_hook = '',
			$additional_to_error_message = ''
		) {


			// This is for testing only!
			set_site_transient( 'update_plugins', null );


			// Store setup data
			$this->api_endpoint                = trailingslashit( esc_url_raw( $api_endpoint ) );
			$this->license_key                 = sanitize_key( $license_key );
			$this->asset_identifier            = sanitize_key( $asset_identifier );
			$this->type                        = sanitize_key( $type );
			$this->plugin_file                 = $plugin_file;
			$this->plugin_slug                 = plugin_basename( $this->plugin_file );
			$this->plugin_settings_page_hook   = $plugin_settings_page_hook;
			$this->additional_to_error_message = $additional_to_error_message;


			if ( $type === 'theme' ) {
				// Check for updates (for themes)
				add_filter( 'pre_set_site_transient_update_themes', array( $this, 'check_for_update' ) );
			} elseif ( $type === 'plugin' ) {
				// Check for updates (for plugins)
				add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
				// Showing plugin information
				add_filter( 'plugins_api', array( $this, 'plugins_api_handler' ), 10, 3 );

			}

			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		}


		/**
		 * Admin enqueue scripts for license validation fields and ajax
		 */
		public function admin_enqueue_scripts( $hook ) {

			if ( empty( $this->plugin_settings_page_hook ) ) {
				echo '<script>console.info("admin_page_hook: ' . $hook . '")</script>';
			}

			if ( $this->plugin_settings_page_hook !== $hook ) {
				return;
			}

			wp_enqueue_script(
				'uclient-license-validation',
				plugin_dir_url( __FILE__ ) . 'js/license-validation.js',
				array( 'jquery' ),
				$this->version,
				true
			);

			wp_localize_script(
				'uclient-license-validation',
				'uclientLocalize',
				apply_filters(
					'uclient_admin_localize_script',
					array(
						'apiEndPoint'              => $this->api_endpoint,
						'additionalToErrorMessage' => $this->additional_to_error_message,
						'assetIdentifier'          => $this->asset_identifier,
						'messages'                 => array(
							'working'               => 'Working',
							'activate'              => 'Activate',
							'deactivate'            => 'Deactivate',
							'select_vendor'         => 'Please select a fucking vendor',
							'provide_purchase_code' => 'Please provide valid purchase code',
							'provide_license_key'   => 'Please provide license key.'
						)
					)
				)
			);

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

		/**
		 * @param string $license_key acquired from the site options
		 * @param string $text_domain
		 *
		 * @return array
		 */
		public static function get_license_activation_fields_metabox( $text_domain = '' ) {

			$fields = array();

			$fields['license_source'] = array(
				'id'      => 'license_source',
				'name'    => esc_html__( 'I have key from:', $text_domain ),
				'type'    => 'select',
				'options' => array(
					''       => '--' . esc_html__( 'Please select', $text_domain ) . '--',
					'envato' => esc_html__( 'Envato', $text_domain ),
					'author' => esc_html__( 'Plugin Author', $text_domain ),
				),
				'std'     => 'envato',
				'class'   => 'license_source',
				'hidden'  => array( 'license_key', 'match', '(.|\s)*\S(.|\s)*' )
			);


			$fields['envato_key'] = array(
				'id'      => 'envato_key',
				'name'    => esc_html__( 'Envato Purchase Code', $text_domain ),
				'type'    => 'text',
				'size'    => 60,
				'class'   => 'envato_key',
				'visible' => array( 'license_source', '=', 'envato' ),
			);

			$fields['author_key'] = array(
				'id'      => 'author_key',
				'name'    => esc_html__( 'Author Purchase Code', $text_domain ),
				'type'    => 'text',
				'size'    => 60,
				'class'   => 'author_key',
				'visible' => array( 'license_source', '=', 'author' ),
			);

			$fields['validate_purchase_code_button'] = array(
				'id'     => 'validate_purchase_code_button',
				'name'   => esc_html__( '&nbsp;', $text_domain ),
				'save'   => false,
				'type'   => 'button',
				'class'  => 'validate_purchase_code_button',
				'std'    => esc_html__( 'Activate', $text_domain ),
				'hidden' => array( 'license_key', 'match', '(.|\s)*\S(.|\s)*' ),
				'after'  => '<span class="uclient-response-message"></span>'
			);

			$fields[] = array(
				'type'   => 'divider',
				'hidden' => array( 'license_key', 'match', '(.|\s)*\S(.|\s)*' )
			);


			$fields['license_heading'] = array(
				'type'       => 'custom_html',
				'std'        => '<h3>' . esc_html__( 'License Details', $text_domain ) . '</h3>',
				'save_field' => false,
				'visible'    => array( 'license_key', 'match', '(.|\s)*\S(.|\s)*' )
			);

			$fields['purchase_code'] = array(
				'id'         => 'purchase_code',
				'name'       => esc_html__( 'Purchase Code', $text_domain ),
				'type'       => 'text',
				'size'       => 60,
				'attributes' => array(
					'readonly' => true,
				),
				'visible'    => array( 'license_key', 'match', '(.|\s)*\S(.|\s)*' ),
				'class' => 'purchase_code'

			);

			$fields['license_key'] = array(
				'id'         => 'license_key',
				'name'       => esc_html__( 'License Key from Update Server', $text_domain ),
				'type'       => 'text',
				'size'       => 60,
				'desc'       => esc_html__( 'License Key acquired from plugin update server', $text_domain ),
				'attributes' => array(
					'readonly' => true,
				),
                'class' => 'license_key'
			);

			$fields['license_status'] = array(
				'id'   => 'license_status',
				'name' => esc_html__( 'License Status', $text_domain ),
				'type' => 'hidden',
			);

			$fields['license_status_active_html'] = array(
				'id'         => 'license_status_active_html',
				'type'       => 'custom_html',
				'name'       => esc_html__( 'License Status', $text_domain ),
				'desc'       => '<span style="font-size:1.5em; color:green;">' . esc_html__( 'Active', $text_domain ) . '</span>',
				'save_field' => false,
				'visible'    => array( 'license_status', '=', 'active' )
			);

			$fields['deactivate_license_key_button'] = array(
				'id'      => 'deactivate_license_key_button',
				'name'    => esc_html__( '&nbsp;', $text_domain ),
				'save'    => false,
				'type'    => 'button',
				'std'     => esc_html__( 'Deativate', 'uschema' ),
				'class'  => 'deactivate_license_key_button',
				'visible' => array( 'license_key', 'match', '(.|\s)*\S(.|\s)*' ),
				'after'   => '<span class="uclient-response-message"></span>'
			);

			return $fields;
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
		 * @return          object|boolean   The API response
		 */


		private function call_api( $action, $params ) {
			$url = $this->api_endpoint;

			// Append parameters for GET request
			$url .= "$action?" . http_build_query( $params );

			// Send the request
			$request = wp_remote_get( $url );


			if ( is_wp_error( $request ) ) {
				return false;
			}

			$response_body = wp_remote_retrieve_body( $request );
			$response      = (object) json_decode( $response_body, true );

//			check to see if there is error in api call
			if ( $this->is_api_error( $response ) ) {

//		    If there is error, log error and return false
				$this->log_error();
				add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
				add_action( "in_plugin_update_message-{$this->plugin_slug}", array(
					$this,
					'show_plugin_notices_update'
				), 10, 2 );

				return false;
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
				'asset'   => $this->asset_identifier,
				'license' => $this->license_key,
				'domain'  => $_SERVER['SERVER_NAME']
			);

			/*
			 * Call to API
			 */

			$license_info = $this->call_api( 'get', $params );

			return $license_info;


		}

		private function is_licence_pending( $license_info ) {

			return ( isset( $license_info->status ) && $license_info->status === 'pending' ) ? true : false;
		}


		public function get_digital_asset_info() {


//			TODO: Cache API response and then use afterwards

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
			return $this->type === 'theme';
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


		public function show_plugin_notices_update( $plugin_data, $response ) {

			echo '</p></div><div class="update-message notice inline notice-error notice-alt"><p>';
			$format = __( "There was an error fetching the updates for <b>%s</b>, Details of error is <b>%s</b>. Please contact plugin author with the error specified.", $this->get_text_domain() );
			printf(
				$format,
				$this->get_plugin_data_attr( 'Name' ),
				$this->get_api_error_text()
			);

		}


		public function show_admin_notices() {

			?>
            <div class="notice notice-error is-dismissible">
                <p>
					<?php

					$format = __( "There was an error fetching the updates for <b>%s</b>, Details of error is <b>%s</b>. Please contact plugin author with the error specified.", $this->get_text_domain() );
					printf(
						$format,
						$this->get_plugin_data_attr( 'Name' ),
						$this->get_api_error_text()
					);

					?>
                </p>

            </div>
			<?php
		}

		private function get_api_error_text() {
			return $this->error_message . " [" . $this->error_code . "]";
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

			$original_stored_log = get_option( 'uclient_updater_api_error_log' );

			update_option( 'uclient_updater_api_error_log', $original_stored_log . PHP_EOL . $message );

		}


	}
}