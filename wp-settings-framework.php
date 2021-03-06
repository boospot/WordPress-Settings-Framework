<?php
/**
 * WordPress Settings Framework
 *
 * @author  Gilbert Pellegrom, James Kemp
 * @link    https://github.com/gilbitron/WordPress-Settings-Framework
 * @version 1.6.9
 * @license MIT
 */

if ( ! class_exists( 'WordPressSettingsFramework' ) ) {
	/**
	 * WordPressSettingsFramework class
	 */
	class WordPressSettingsFramework {
		/**
		 * @access private
		 * @var array
		 */
		private $settings_wrapper;

		/**
		 * @access private
		 * @var array
		 */
		private $settings;

		/**
		 * @access private
		 * @var array
		 */
		private $tabs;

		/**
		 * @access private
		 * @var string
		 */
		private $option_group;

		/**
		 * @access private
		 * @var array
		 */
		private $settings_page = array();

		/**
		 * @access private
		 * @var string
		 */
		private $options_path;

		/**
		 * @access private
		 * @var string
		 */
		private $options_url;

		/**
		 * @access private
		 * @var array
		 */

		private $configured_field_types;


		/**
		 * @access protected
		 * @var boolean
		 *  true if settings provided as array and not as file
		 */
		protected $is_settings_array;

		/**
		 * @access protected
		 * @var boolean
		 *  true if settings provided as file. backward compatibility
		 */
		protected $is_settings_file;


		/**
		 * @access protected
		 * @var array
		 */
		protected $setting_defaults = array(
			'id'          => 'default_field',
			'title'       => 'Default Field',
			'desc'        => '',
			'std'         => '',
			'type'        => 'text',
			'placeholder' => '',
			'choices'     => array(),
			'class'       => '',
			'subfields'   => array(),
		);

		/**
		 * WordPressSettingsFramework constructor.
		 *
		 * @param string $settings_file
		 * @param bool $option_group
		 */
		public function __construct( $settings_file_or_array, $option_group = false ) {

//			if ( ! is_file( $settings_file )) {
//				return;
//			}
//
			if ( is_string( $settings_file_or_array ) && is_file( $settings_file_or_array )  ) {
				// this is a file, so require it
				require_once( $settings_file_or_array );

				$this->is_settings_file  = true;
				$this->is_settings_array = false;

			} else {

				// $settings_array should be array
				if ( ! is_array( $settings_file_or_array ) ) {
					add_settings_error( 'WPSF', 'wpsf', '! is_array( $settings_file_or_array )', 'error' );

					return;
				}

				// $settings_array should not be empty
				if ( empty( $settings_file_or_array ) ) {
					add_settings_error( 'WPSF', 'wpsf', 'empty( $settings_file_or_array )', 'error' );

					return;
				}

				// We are sure by now that the first parameter is an array and is not empty,
				// so, lets set properties.

				$this->is_settings_file  = false;
				$this->is_settings_array = true;

			}


			if ( $option_group ) {
				// if option_group is set, use it, otherwise, get from config
				$this->option_group = $option_group;

			} else {

				if ( $this->is_settings_file ) {
					$this->option_group = preg_replace( "/[^a-z0-9]+/i", "", basename( $settings_file_or_array, '.php' ) );
				}

				if ( $this->is_settings_array ) {

					$this->option_group = isset( $settings_file_or_array['option_group'] )
						? sanitize_key( $settings_file_or_array['option_group'] )
						: null;


					if ( $this->option_group == null ) {
						// option group is not defined in the settings array, so bail out
						add_settings_error( 'WPSF', 'wpsf', 'undefined $option_group OR undefined $settings_array["option_group"]', 'error' );

						return;
					}
				}



			}



			$this->options_path = plugin_dir_path( __FILE__ );
			$this->options_url  = plugin_dir_url( __FILE__ );

			$this->construct_settings( $settings_file_or_array );

			$this->set_configured_field_types();

			if ( is_admin() ) {
				global $pagenow;

				add_action( 'admin_init', array( $this, 'admin_init' ) );
				add_action( 'wpsf_do_settings_sections_' . $this->option_group, array(
					$this,
					'do_tabless_settings_sections'
				), 10 );

				if ( isset( $_GET['page'] ) && $_GET['page'] === $this->settings_page['slug'] ) {
					if ( $pagenow !== "options-general.php" ) {
						add_action( 'admin_notices', array( $this, 'admin_notices' ) );
					}
					add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
				}

				if ( $this->has_tabs() ) {
					add_action( 'wpsf_before_settings_' . $this->option_group, array( $this, 'tab_links' ) );

					remove_action( 'wpsf_do_settings_sections_' . $this->option_group, array(
						$this,
						'do_tabless_settings_sections'
					), 10 );
					add_action( 'wpsf_do_settings_sections_' . $this->option_group, array(
						$this,
						'do_tabbed_settings_sections'
					), 10 );
				}
			}
		}

		/**
		 * Will loop through the config/settings array to get all field types configured
		 * so that we can conditionally enqueue only required scripts and styles
		 *
		 * Sets property: configured_field_types
		 */
		public function set_configured_field_types() {

			$field_type_array = array();

			$fields = new RecursiveArrayIterator( $this->settings_wrapper );
			$fields = new RecursiveIteratorIterator( $fields );

			foreach ( $fields as $key => $field ) {

				if ( strtolower( $key ) === 'type' ) {
					$field_type_array[] = $field;
				}
			}

			$this->configured_field_types = array_unique( $field_type_array );

		}

		/**
		 * @return array $this->configured_field_type
		 */
		public function get_configured_field_types() {

			return $this->configured_field_types;

		}

		/**
		 * Construct Settings.
		 */
		public function construct_settings( $settings_array = null ) {

			$this->settings_wrapper = apply_filters( 'wpsf_register_settings_' . $this->option_group, array() );

			if ( ! is_array( $this->settings_wrapper ) ) {
				return new WP_Error( 'broke', __( 'WPSF settings must be an array' ) );
			}
			// include the settings array after apply_filters
			if ( $this->is_settings_array && $settings_array != null ) {
				$this->settings_wrapper = array_merge_recursive( $settings_array, $this->settings_wrapper );
			}

			// If "sections" is set, this settings group probably has tabs
			if ( isset( $this->settings_wrapper['sections'] ) ) {
				$this->tabs     = ( isset( $this->settings_wrapper['tabs'] ) ) ? $this->settings_wrapper['tabs'] : array();
				$this->settings = $this->settings_wrapper['sections'];
				// If not, it's probably just an array of settings
			} else {
				$this->settings = $this->settings_wrapper;
			}
			$this->settings_page['slug'] = sprintf( '%s-settings', str_replace( '_', '-', $this->option_group ) );
		}

		/**
		 * Get the option group for this instance
		 *
		 * @return string the "option_group"
		 */
		public function get_option_group() {
			return $this->option_group;
		}

		/**
		 * Registers the internal WordPress settings
		 */
		public function admin_init() {
			register_setting( $this->option_group, $this->option_group . '_settings', array(
				$this,
				'settings_validate'
			) );
			$this->process_settings();
		}

		/**
		 * Add Settings Page
		 *
		 * @param array $args
		 */
		public function add_settings_page( $args ) {
			$defaults = array(
				'parent_slug' => false,
				'page_slug'   => "",
				'page_title'  => "",
				'menu_title'  => "",
				'capability'  => 'manage_options',
			);

			$args = wp_parse_args( $args, $defaults );

			$this->settings_page['title']      = $args['page_title'];
			$this->settings_page['capability'] = $args['capability'];

			if ( $args['parent_slug'] ) {
				add_submenu_page(
					$args['parent_slug'],
					$this->settings_page['title'],
					$args['menu_title'],
					$args['capability'],
					$this->settings_page['slug'],
					array( $this, 'settings_page_content' )
				);
			} else {
				add_menu_page(
					$this->settings_page['title'],
					$args['menu_title'],
					$args['capability'],
					$this->settings_page['slug'],
					array( $this, 'settings_page_content' ),
					apply_filters( 'wpsf_menu_icon_url_' . $this->option_group, '' ),
					apply_filters( 'wpsf_menu_position_' . $this->option_group, null )
				);
			}
		}

		/**
		 * Settings Page Content
		 */

		public function settings_page_content() {
			if ( ! current_user_can( $this->settings_page['capability'] ) ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
			}
			?>
            <div class="wrap">
                <div id="icon-options-general" class="icon32"></div>
                <h2><?php echo $this->settings_page['title']; ?></h2>
				<?php
				// Output your settings form
				$this->settings();
				?>
            </div>
			<?php

		}

		/**
		 * Displays any errors from the WordPress settings API
		 */
		public function admin_notices() {
			settings_errors();
		}

		/**
		 * Enqueue scripts and styles
		 */
		public function admin_enqueue_scripts() {

			// Registering some style and scripts, that we may require
			wp_register_script( 'wpsf', $this->options_url . 'assets/js/main.js', array( 'jquery' ), false, true );
			// styles
			wp_register_style( 'wpsf', $this->options_url . 'assets/css/main.css' );
			wp_register_style( 'jquery-ui-css', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8.21/themes/ui-darkness/jquery-ui.css' );


			// enqueue scripts
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'thickbox' );
			wp_enqueue_script( 'jquery-ui-core' );

			// enqueue styles
			wp_enqueue_style( 'thickbox' );
			wp_enqueue_style( 'jquery-ui-css' );

			// for timestamp
			if ( in_array( 'time', $this->get_configured_field_types() ) ) {
				wp_register_script( 'jquery-ui-timepicker', $this->options_url . 'assets/vendor/jquery-timepicker/jquery.ui.timepicker.js', array(
					'jquery',
					'jquery-ui-core'
				), false, true );
				wp_register_style( 'jquery-ui-timepicker', $this->options_url . 'assets/vendor/jquery-timepicker/jquery.ui.timepicker.css' );

				wp_enqueue_script( 'jquery-ui-timepicker' );
				wp_enqueue_style( 'jquery-ui-timepicker' );
			}

			// for datepicker
			if ( in_array( 'date', $this->get_configured_field_types() ) ) {
				wp_enqueue_script( 'jquery-ui-datepicker' );
				wp_enqueue_style( 'jquery-ui-timepicker' );
			}

			// for file
			if ( in_array( 'file', $this->get_configured_field_types() ) ) {
				wp_enqueue_script( 'media-upload' );
			}

			// for color picker
			if ( in_array( 'color', $this->get_configured_field_types() ) ) {
				wp_enqueue_script( 'farbtastic' );
				wp_enqueue_style( 'farbtastic' );
			}

			// for media upload
			if ( in_array( 'media', $this->get_configured_field_types() ) ) {
				wp_enqueue_media();
			}

			// Framework Specific
			wp_enqueue_script( 'wpsf' );
			wp_enqueue_style( 'wpsf' );
		}

		/**
		 * Adds a filter for settings validation.
		 *
		 * @param $input
		 *
		 * @return array
		 */
		public function settings_validate( $input ) {

//			$this->write_log( 'field_name', var_export( $input, true ) . PHP_EOL . PHP_EOL );

			$sanitized_input = $this->get_sanitized_settings( $input );

//			$this->write_log( 'field_name', var_export( $sanitized_input, true ) . PHP_EOL . PHP_EOL );

			return apply_filters( $this->option_group . '_settings_validate', $sanitized_input );

		}

		/**
		 * @param $posted_data
		 *
		 * @return array sanitized posted data
		 */
		public function get_sanitized_settings( $posted_data ) {


			$sanitized_settings_data = array();

			foreach ( $this->settings as $section ) {
				foreach ( $section['fields'] as $field ) {

					$field_type = ( isset( $field['type'] ) ) ? strtolower( $field['type'] ) : false;
					$field_id   = ( isset( $field['id'] ) ) ? $field['id'] : false;

					// if do not have $field_id or $field_type, we continue to next field
					if ( ! $field_id || ! $field_type ) {
						continue;
					}

					$setting_key =
						$this->has_tabs()
							? sprintf( '%s_%s_%s', $section['tab_id'], $section['section_id'], $field['id'] )
							: sprintf( '%s_%s', $section['section_id'], $field['id'] );


					// For non-group field

					if ( $field_type != 'group' ) {

						// if field is not a group
						$sanitized_settings_data[ $setting_key ] = $this->get_sanitized_field_value_from_posted_data( $field, $setting_key, $posted_data );

					} else {
						// This is a group field

						if ( isset( $field['subfields'] ) && is_array( $field['subfields'] ) && ! empty( $field['subfields'] ) && isset( $posted_data[ $setting_key ] ) ) {
							$sanitized_settings_data[ $setting_key ] = $this->get_sanitized_sub_field_value_from_posted_data( $field, $setting_key, $posted_data );
						}

					}

				}

			}


			return $sanitized_settings_data;

		} //

		/**
		 * @param $field from       settings/config array
		 * @param $setting_key      name of setting
		 * @param $posted_data      posted data on options save
		 *
		 * @return mixed
		 */
		public function get_sanitized_field_value_from_posted_data( $field, $setting_key, $posted_data ) {


			// Get $dirty_value from $posted_data
			$dirty_value = isset( $posted_data[ $setting_key ] ) ? $posted_data[ $setting_key ] : null;

			$clean_value = $this->sanitize( $field, $dirty_value );

			return $clean_value;

		}

		/**
		 * @param $field 'group' type field that have 'subfields'
		 * @param $setting_key      name of setting
		 * @param $posted_data      posted data on options save
		 *
		 * @return array
		 */
		public function get_sanitized_sub_field_value_from_posted_data( $field, $setting_key, $posted_data ) {

			$group_sub_fields_sanitized = array();

			foreach ( $posted_data[ $setting_key ] as $sub_fields_set_index => $sub_fields_set ) {

				$group_sub_fields_sanitized[ $sub_fields_set_index ] = array();

				foreach ( $field['subfields'] as $index => $subfield ) {

					$subfield_type = ( isset( $subfield['type'] ) ) ? strtolower( $subfield['type'] ) : false;
					$subfield_id   = ( isset( $subfield['id'] ) ) ? $subfield['id'] : false;

					// if do not have $field_id or $field_type, we continue to next field
					if ( ! $subfield_id || ! $subfield_type || $subfield_type == 'group' ) {
						// group should not be allowed inside group
						continue;
					}

					$dirty_value = isset( $sub_fields_set[ $subfield_id ] ) ? $sub_fields_set[ $subfield_id ] : null;

					$group_sub_fields_sanitized[ $sub_fields_set_index ][ $subfield_id ] = $this->sanitize( $subfield, $dirty_value );
				}

			}

			return $group_sub_fields_sanitized;
		}

		/**
		 * Validate and sanitize values
		 *
		 * @param $field
		 * @param $value
		 *
		 * @return mixed
		 */
		public function sanitize( $field, $dirty_value ) {

			$dirty_value = isset( $dirty_value ) ? $dirty_value : '';

			if ( empty( $dirty_value ) ) {
				return $dirty_value; // no need to sanitize
			}

			// if $config array has sanitize function, then call it
			if ( isset( $field['sanitize'] ) && ! empty( $field['sanitize'] ) ) {

				if ( strtolower( $field['sanitize'] ) == 'no' ) {
					// user do not want to sanitize, so return the dirty value, user may sanitize out of this class
					return $dirty_value;
				}

				if ( function_exists( $field['sanitize'] ) ) {
					// TODO: in future, we can allow for sanitize functions array as well
					$clean_value = call_user_func( $field['sanitize'], $dirty_value );
				} else {
					// user has entered wrong sanitize function name, so use default as a safety net
					$clean_value = $this->get_sanitized_field_value_by_type( $field, $dirty_value );
				}

			} else {

				// if $field does not have sanitize function, do sanitize on field type basis
				$clean_value = $this->get_sanitized_field_value_by_type( $field, $dirty_value );

			}

			return $clean_value;

		}


		/**
		 * Pass the field and value to run sanitization by type of field
		 *
		 * @param array $field
		 * @param mixed $value
		 *
		 * $return mixed $value after sanitization
		 */
		public function get_sanitized_field_value_by_type( $field, $value ) {

			$field_type = ( isset( $field['type'] ) ) ? $field['type'] : '';

			switch ( $field_type ) {

				case 'time':
					$value = sanitize_text_field( $value );
					break;

				case 'date':
					$value = date( "Y-m-d", strtotime( $value ) );
					break;

				case 'number':
					$value = ( is_numeric( $value ) ) ? $value : 0;
					break;

				case 'password':
					$value = sanitize_text_field( $value );
					break;

				case 'textarea':
					// HTML and array are allowed
					$value = sanitize_textarea_field( $value );
//					 $value = wp_kses_post( $value );
					break;

				case 'select':
					// no break
				case 'radio':
					// no break
					$allowed_choices = isset( $field['choices'] ) && is_array( $field['choices'] )
						? array_keys( $field['choices'] )
						: array();
					$default_values  = isset( $field['default'] ) && is_array( $field['default'] )
						? $field['default']
						: array();

					$value = in_array( $value, $allowed_choices ) ? $value : $default_values;
					unset( $allowed_choices, $default_values ); // free memory
					break;

				case 'checkboxes':
					$allowed_choices = isset( $field['choices'] ) && is_array( $field['choices'] )
						? array_keys( $field['choices'] )
						: array();
					$default_values  = isset( $field['default'] ) && is_array( $field['default'] )
						? $field['default']
						: array();

					if ( is_array( $value ) && ! empty( $allowed_choices ) ) {
						// if the difference is empty, $value is a subset of $allowed_choices
						$value = ( empty( array_diff( $value, $allowed_choices ) ) ) ? $value : $default_values;

					} else {
						$value = $field['default'];
					}

					unset( $allowed_choices, $default_values ); // free memory
					break;

				case 'checkbox':
					$value = ( (int) $value === 1 ) ? 1 : '';
					break;

				case 'color':
					$value = sanitize_hex_color( $value );
					break;

				case 'editor':
					// no break
					$value = wp_kses_post( $value );
					break;


				case 'uploader':
					// We are getting image id posted
					$value = absint( $value );
					break;

				case 'file':
					// no break

				default:
					$value = ( ! empty( $value ) ) ? sanitize_text_field( $value ) : '';


			}

			return $value;

		}


		/**
		 * Displays the "section_description" if specified in $this->settings
		 *
		 * @param array callback args from add_settings_section()
		 */
		public function section_intro( $args ) {
			if ( ! empty( $this->settings ) ) {
				foreach ( $this->settings as $section ) {
					if ( $section['section_id'] == $args['id'] ) {
						if ( isset( $section['section_description'] ) && $section['section_description'] ) {
							echo '<div class="wpsf-section-description wpsf-section-description--' . esc_attr( $section['section_id'] ) . '">' . $section['section_description'] . '</div>';
						}
						break;
					}
				}
			}
		}

		/**
		 * Processes $this->settings and adds the sections and fields via the WordPress settings API
		 */
		private function process_settings() {
			if ( ! empty( $this->settings ) ) {
				usort( $this->settings, array( $this, 'sort_array' ) );

				foreach ( $this->settings as $section ) {
					if ( isset( $section['section_id'] ) && $section['section_id'] && isset( $section['section_title'] ) ) {
						$page_name = ( $this->has_tabs() ) ? sprintf( '%s_%s', $this->option_group, $section['tab_id'] ) : $this->option_group;

						add_settings_section( $section['section_id'], $section['section_title'], array(
							$this,
							'section_intro'
						), $page_name );

						if ( isset( $section['fields'] ) && is_array( $section['fields'] ) && ! empty( $section['fields'] ) ) {
							foreach ( $section['fields'] as $field ) {
								if ( isset( $field['id'] ) && $field['id'] && isset( $field['title'] ) ) {
									$title = ! empty( $field['subtitle'] ) ? sprintf( '%s <span class="wpsf-subtitle">%s</span>', $field['title'], $field['subtitle'] ) : $field['title'];

									add_settings_field( $field['id'], $title, array(
										$this,
										'generate_setting'
									), $page_name, $section['section_id'], array(
										'section' => $section,
										'field'   => $field
									) );
								}
							}
						}
					}
				}
			}
		}

		/**
		 * Usort callback. Sorts $this->settings by "section_order"
		 *
		 * @param $a
		 * @param $b
		 *
		 * @return bool
		 */
		public function sort_array( $a, $b ) {
			if ( ! isset( $a['section_order'] ) ) {
				return false;
			}

			return $a['section_order'] > $b['section_order'];
		}

		/**
		 * @param $type         name of log file
		 * @param $log_line     variable/line/dump to be printed to log
		 */
		public function write_log( $type, $log_line ) {

			$hash        = '';
			$fn          = plugin_dir_path( __FILE__ ) . '/' . $type . '-' . $hash . '.log';
			$log_in_file = file_put_contents( $fn, date( 'Y-m-d H:i:s' ) . ' - ' . $log_line . PHP_EOL, FILE_APPEND );

		}


		/**
		 * Generates the HTML output of the settings fields
		 *
		 * @param array callback args from add_settings_field()
		 */
		public function generate_setting( $args ) {

			$section                = $args['section'];
			$this->setting_defaults = apply_filters( 'wpsf_defaults_' . $this->option_group, $this->setting_defaults );

			$args = wp_parse_args( $args['field'], $this->setting_defaults );

			$options = get_option( $this->option_group . '_settings' );

			$args['id'] = $this->has_tabs()
				? sprintf( '%s_%s_%s', $section['tab_id'], $section['section_id'], $args['id'] )
				: sprintf( '%s_%s', $section['section_id'], $args['id'] );


			$args['value'] = isset( $options[ $args['id'] ] ) ? $options[ $args['id'] ] : ( isset( $args['default'] ) ? $args['default'] : '' );
			$args['name']  = $this->generate_field_name( $args['id'] );

			do_action( 'wpsf_before_field_' . $this->option_group );
			do_action( 'wpsf_before_field_' . $this->option_group . '_' . $args['id'] );

			$this->do_field_method( $args );

			do_action( 'wpsf_after_field_' . $this->option_group );
			do_action( 'wpsf_after_field_' . $this->option_group . '_' . $args['id'] );
		}

		/**
		 * Do field method, if it exists
		 *
		 * @param array $args
		 */
		public function do_field_method( $args ) {
			$generate_field_method = sprintf( 'generate_%s_field', $args['type'] );

			if ( method_exists( $this, $generate_field_method ) ) {
				$this->$generate_field_method( $args );
			}
		}

		/**
		 * Generate: Text field
		 *
		 * @param array $args
		 */
		public function generate_text_field( $args ) {
			$args['value'] = esc_attr( stripslashes( $args['value'] ) );
			echo '<input type="text" name="' . $args['name'] . '" id="' . $args['id'] . '" value="' . $args['value'] . '" placeholder="' . $args['placeholder'] . '" class="regular-text ' . $args['class'] . '" />';
			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Number field
		 *
		 * @param array $args
		 */
		public function generate_number_field( $args ) {
			$args['value'] = esc_attr( stripslashes( $args['value'] ) );

			echo '<input type="number" name="' . $args['name'] . '" id="' . $args['id'] . '" value="' . $args['value'] . '" placeholder="' . $args['placeholder'] . '" class="regular-text ' . $args['class'] . '" />';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Time field
		 *
		 * @param array $args
		 */
		public function generate_time_field( $args ) {
			$args['value'] = esc_attr( stripslashes( $args['value'] ) );

			$timepicker = ! empty( $args['timepicker'] ) ? htmlentities( json_encode( $args['timepicker'] ) ) : null;

			echo '<input name="' . $args['name'] . '" id="' . $args['id'] . '" value="' . $args['value'] . '" class="timepicker regular-text ' . $args['class'] . '" data-timepicker="' . $timepicker . '" />';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Date field
		 *
		 * @param array $args
		 */
		public function generate_date_field( $args ) {
			$args['value'] = esc_attr( stripslashes( $args['value'] ) );

			$datepicker = ! empty( $args['datepicker'] ) ? htmlentities( json_encode( $args['datepicker'] ) ) : null;

			echo '<input name="' . $args['name'] . '" id="' . $args['id'] . '" value="' . $args['value'] . '" class="datepicker regular-text ' . $args['class'] . '" data-datepicker="' . $datepicker . '" />';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Group field
		 *
		 * Generates a table of subfields, and a javascript template for create new repeatable rows
		 *
		 * @param array $args
		 */
		public function generate_group_field( $args ) {
			$row_count = count( $args['value'] );

			echo '<table class="widefat wpsf-group" cellspacing="0">';

			echo "<tbody>";

			for ( $row = 0; $row < $row_count; $row ++ ) {
				echo $this->generate_group_row_template( $args, false, $row );
			}

			echo "</tbody>";

			echo "</table>";

			printf( '<script type="text/html" id="%s_template">%s</script>', $args['id'], $this->generate_group_row_template( $args, true ) );

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate group row template
		 *
		 * @param array $args Field arguments
		 * @param bool $blank Blank values
		 * @param int $row Iterator
		 *
		 * @return string|bool
		 */
		public function generate_group_row_template( $args, $blank = false, $row = 0 ) {
			$row_template = false;

			if ( $args['subfields'] ) {
				$row_class = $row % 2 == 0 ? "alternate" : "";

				$row_template .= sprintf( '<tr class="wpsf-group__row %s">', $row_class );

				$row_template .= sprintf( '<td class="wpsf-group__row-index"><span>%d</span></td>', $row );

				$row_template .= '<td class="wpsf-group__row-fields">';

				foreach ( $args['subfields'] as $subfield ) {
					$subfield = wp_parse_args( $subfield, $this->setting_defaults );

					$subfield['value'] = ( $blank ) ? "" : isset( $args['value'][ $row ][ $subfield['id'] ] ) ? $args['value'][ $row ][ $subfield['id'] ] : "";
					$subfield['name']  = sprintf( '%s[%d][%s]', $args['name'], $row, $subfield['id'] );
					$subfield['id']    = sprintf( '%s_%d_%s', $args['id'], $row, $subfield['id'] );

					$row_template .= '<div class="wpsf-group__field-wrapper">';

					$row_template .= sprintf( '<label for="%s" class="wpsf-group__field-label">%s</label>', $subfield['id'], $subfield['title'] );

					ob_start();
					$this->do_field_method( $subfield );
					$row_template .= ob_get_clean();

					$row_template .= '</div>';
				}

				$row_template .= "</td>";

				$row_template .= '<td class="wpsf-group__row-actions">';

				$row_template .= sprintf( '<a href="javascript: void(0);" class="wpsf-group__row-add" data-template="%s_template"><span class="dashicons dashicons-plus-alt"></span></a>', $args['id'] );
				$row_template .= '<a href="javascript: void(0);" class="wpsf-group__row-remove"><span class="dashicons dashicons-trash"></span></a>';

				$row_template .= "</td>";

				$row_template .= '</tr>';
			}

			return $row_template;
		}

		/**
		 * Generate: Select field
		 *
		 * @param array $args
		 */
		public function generate_select_field( $args ) {
			$args['value'] = esc_html( esc_attr( $args['value'] ) );

			echo '<select name="' . $args['name'] . '" id="' . $args['id'] . '" class="' . $args['class'] . '">';

			foreach ( $args['choices'] as $value => $text ) {
				$selected = $value == $args['value'] ? 'selected="selected"' : '';

				echo sprintf( '<option value="%s" %s>%s</option>', $value, $selected, $text );
			}

			echo '</select>';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Password field
		 *
		 * @param array $args
		 */
		public function generate_password_field( $args ) {
			$args['value'] = esc_attr( stripslashes( $args['value'] ) );

			echo '<input type="password" name="' . $args['name'] . '" id="' . $args['id'] . '" value="' . $args['value'] . '" placeholder="' . $args['placeholder'] . '" class="regular-text ' . $args['class'] . '" />';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Textarea field
		 *
		 * @param array $args
		 */
		public function generate_textarea_field( $args ) {
			$args['value'] = esc_html( esc_attr( $args['value'] ) );

			echo '<textarea name="' . $args['name'] . '" id="' . $args['id'] . '" placeholder="' . $args['placeholder'] . '" rows="5" cols="60" class="' . $args['class'] . '">' . $args['value'] . '</textarea>';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Radio field
		 *
		 * @param array $args
		 */
		public function generate_radio_field( $args ) {
			$args['value'] = esc_html( esc_attr( $args['value'] ) );

			foreach ( $args['choices'] as $value => $text ) {
				$field_id = sprintf( '%s_%s', $args['id'], $value );
				$checked  = $value == $args['value'] ? 'checked="checked"' : '';

				echo sprintf( '<label><input type="radio" name="%s" id="%s" value="%s" class="%s" %s> %s</label><br />', $args['name'], $field_id, $value, $args['class'], $checked, $text );
			}

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Checkbox field
		 *
		 * @param array $args
		 */
		public function generate_checkbox_field( $args ) {
			$args['value'] = esc_attr( stripslashes( $args['value'] ) );
			$checked       = $args['value'] ? 'checked="checked"' : '';

			echo '<input type="hidden" name="' . $args['name'] . '" value="0" />';
			echo '<label><input type="checkbox" name="' . $args['name'] . '" id="' . $args['id'] . '" value="1" class="' . $args['class'] . '" ' . $checked . '> ' . $args['desc'] . '</label>';
		}

		/**
		 * Generate: Checkboxes field
		 *
		 * @param array $args
		 */
		public function generate_checkboxes_field( $args ) {
			echo '<input type="hidden" name="' . $args['name'] . '" value="0" />';

			echo '<ul class="wpsf-list wpsf-list--checkboxes">';

			foreach ( $args['choices'] as $value => $text ) {
				$checked  = is_array( $args['value'] ) && in_array( $value, $args['value'] ) ? 'checked="checked"' : '';
				$field_id = sprintf( '%s_%s', $args['id'], $value );

				echo sprintf( '<li><label><input type="checkbox" name="%s[]" id="%s" value="%s" class="%s" %s> %s</label></li>', $args['name'], $field_id, $value, $args['class'], $checked, $text );
			}

			echo '</ul>';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Color field
		 *
		 * @param array $args
		 */
		public function generate_color_field( $args ) {
			$color_picker_id = sprintf( '%s_cp', $args['id'] );
			$args['value']   = esc_attr( stripslashes( $args['value'] ) );

			echo '<div style="position:relative;">';

			echo sprintf( '<input type="text" name="%s" id="%s" value="%s" class="%s">', $args['name'], $args['id'], $args['value'], $args['class'] );

			echo sprintf( '<div id="%s" style="position:absolute;top:0;left:190px;background:#fff;z-index:9999;"></div>', $color_picker_id );

			$this->generate_description( $args['desc'] );

			echo '<script type="text/javascript">
                jQuery(document).ready(function($){
                    var colorPicker = $("#' . $color_picker_id . '");
                    colorPicker.farbtastic("#' . $args['id'] . '");
                    colorPicker.hide();
                    $("#' . $args['id'] . '").live("focus", function(){
                        colorPicker.show();
                    });
                    $("#' . $args['id'] . '").live("blur", function(){
                        colorPicker.hide();
                        if($(this).val() == "") $(this).val("#");
                    });
                });
                </script>';

			echo '</div>';
		}

		/**
		 * Generate: File field
		 *
		 * @param array $args
		 */
		public function generate_file_field( $args ) {

			$args['value'] = esc_attr( $args['value'] );
			$button_id     = sprintf( '%s_button', $args['id'] );

			echo sprintf( '<input type="text" name="%s" id="%s" value="%s" class="regular-text %s"> ', $args['name'], $args['id'], $args['value'], $args['class'] );

			echo sprintf( '<input type="button" class="button wpsf-browse" id="%s" value="Browse" />', $button_id );

			echo '<script type="text/javascript">
                jQuery(document).ready(function($){
                    $("#' . $button_id . '").click(function() {

                        tb_show("", "media-upload.php?post_id=0&amp;type=image&amp;TB_iframe=true");

                        window.original_send_to_editor = window.send_to_editor;

                        window.send_to_editor = function(html) {
                            var imgurl = $("img",html).attr("src");
                            $("#' . $args['id'] . '").val(imgurl);
                            tb_remove();
                            window.send_to_editor = window.original_send_to_editor;
                        };

                        return false;

                    });
                });
            </script>';
		}

		/**
		 * Generate: Uploader field
		 *
		 * @param array $args
		 *
		 * @source: https://mycyberuniverse.com/integration-wordpress-media-uploader-plugin-options-page.html
		 */
		public function generate_media_field( $args ) {

			// Set variables
			$default_image = isset( $args['default'] ) ? esc_url_raw( $args['default'] ) : 'https://www.placehold.it/115x115';
			$max_width     = isset( $args['max_width'] ) ? absint( $args['max_width'] ) : 400;
			$width         = isset( $args['width'] ) ? absint( $args['width'] ) : '';
			$height        = isset( $args['height'] ) ? absint( $args['height'] ) : '';
			$text          = isset( $args['btn'] ) ? sanitize_text_field( $args['btn'] ) : 'Upload';


			$image_size = ( ! empty( $width ) && ! empty( $height ) ) ? array( $width, $height ) : 'thumbnail';

			if ( ! empty( $args['value'] ) ) {
				$image_attributes = wp_get_attachment_image_src( $args['value'], $image_size );
				$src              = $image_attributes[0];
				$value            = $args['value'];
			} else {
				$src   = $default_image;
				$value = '';
			}

			$image_style = ! is_array( $image_size ) ? "style='max-width:100%; height:auto;'" : "style='width:{$width}px; height:{$height}px;'";

			// Print HTML field
			echo '
                <div class="upload" style="max-width:' . $max_width . 'px;">
                    <img data-src="' . $default_image . '" src="' . $src . '" ' . $image_style . '/>
                    <div>
                        <input type="hidden" name="' . $args['name'] . '" id="' . $args['name'] . '" value="' . $value . '" />
                        <button type="submit" class="wpsf-image-upload button">' . $text . '</button>
                        <button type="submit" class="wpsf-image-remove button">&times;</button>
                    </div>
                </div>
            ';

			$this->generate_description( $args['desc'] );

			// free memory
			unset( $default_image, $max_width, $width, $height, $text, $image_size, $image_style, $value );

		}

		/**
		 * Generate: Editor field
		 *
		 * @param array $args
		 */
		public function generate_editor_field( $args ) {
			wp_editor( $args['value'], $args['id'], array( 'textarea_name' => $args['name'] ) );

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Custom field
		 *
		 * @param array $args
		 */
		public function generate_custom_field( $args ) {
			echo $args['default'];
		}

		/**
		 * Generate: Multi Inputs field
		 *
		 * @param array $args
		 */
		public function generate_multiinputs_field( $args ) {
			$field_titles = array_keys( $args['default'] );
			$values       = array_values( $args['value'] );

			echo '<div class="wpsf-multifields">';

			$i = 0;
			while ( $i < count( $values ) ):

				$field_id = sprintf( '%s_%s', $args['id'], $i );
				$value    = esc_attr( stripslashes( $values[ $i ] ) );

				echo '<div class="wpsf-multifields__field">';
				echo '<input type="text" name="' . $args['name'] . '[]" id="' . $field_id . '" value="' . $value . '" class="regular-text ' . $args['class'] . '" placeholder="' . $args['placeholder'] . '" />';
				echo '<br><span>' . $field_titles[ $i ] . '</span>';
				echo '</div>';

				$i ++; endwhile;

			echo '</div>';

			$this->generate_description( $args['desc'] );
		}

		/**
		 * Generate: Field ID
		 *
		 * @param mixed $id
		 *
		 * @return string
		 */
		public function generate_field_name( $id ) {
			return sprintf( '%s_settings[%s]', $this->option_group, $id );
		}

		/**
		 * Generate: Description
		 *
		 * @param mixed $description
		 */
		public function generate_description( $description ) {
			if ( $description && $description !== "" ) {
				echo '<p class="description">' . $description . '</p>';
			}
		}

		/**
		 * Output the settings form
		 */
		public function settings() {
			do_action( 'wpsf_before_settings_' . $this->option_group );
			?>
            <form action="options.php" method="post" novalidate>
				<?php do_action( 'wpsf_before_settings_fields_' . $this->option_group ); ?>
				<?php settings_fields( $this->option_group ); ?>

				<?php do_action( 'wpsf_do_settings_sections_' . $this->option_group ); ?>

				<?php if ( apply_filters( 'wpsf_show_save_changes_button_' . $this->option_group, true ) ) { ?>
                    <p class="submit">
                        <input type="submit" class="button-primary" value="<?php _e( 'Save Changes' ); ?>"/>
                    </p>
				<?php } ?>
            </form>
			<?php
			do_action( 'wpsf_after_settings_' . $this->option_group );
		}

		/**
		 * Helper: Get Settings
		 *
		 * @return array
		 */
		public function get_settings() {
			$settings_name = $this->option_group . '_settings';

			static $settings = array();

			if ( isset( $settings[ $settings_name ] ) ) {
				return $settings[ $settings_name ];
			}

			$saved_settings             = get_option( $this->option_group . '_settings' );
			$settings[ $settings_name ] = array();

			foreach ( $this->settings as $section ) {
				foreach ( $section['fields'] as $field ) {
					if ( ! empty( $field['default'] ) && is_array( $field['default'] ) ) {
						$field['default'] = array_values( $field['default'] );
					}

					$setting_key = $this->has_tabs() ? sprintf( '%s_%s_%s', $section['tab_id'], $section['section_id'], $field['id'] ) : sprintf( '%s_%s', $section['section_id'], $field['id'] );

					if ( isset( $saved_settings[ $setting_key ] ) ) {
						$settings[ $settings_name ][ $setting_key ] = $saved_settings[ $setting_key ];
					} else {
						$settings[ $settings_name ][ $setting_key ] = ( isset( $field['default'] ) ) ? $field['default'] : false;
					}
				}
			}

			return $settings[ $settings_name ];
		}

		/**
		 * Tabless Settings sections
		 */
		public function do_tabless_settings_sections() {
			?>
            <div class="wpsf-section wpsf-tabless">
				<?php do_settings_sections( $this->option_group ); ?>
            </div>
			<?php
		}

		/**
		 * Tabbed Settings sections
		 */
		public function do_tabbed_settings_sections() {
			$i = 0;
			foreach ( $this->tabs as $tab_data ) {
				?>
                <div id="tab-<?php echo $tab_data['id']; ?>"
                     class="wpsf-section wpsf-tab wpsf-tab--<?php echo $tab_data['id']; ?> <?php if ( $i == 0 ) {
					     echo 'wpsf-tab--active';
				     } ?>">
                    <div class="postbox">
						<?php do_settings_sections( sprintf( '%s_%s', $this->option_group, $tab_data['id'] ) ); ?>
                    </div>
                </div>
				<?php
				$i ++;
			}
		}

		/**
		 * Output the tab links
		 */
		public function tab_links() {
			if ( ! apply_filters( 'wpsf_show_tab_links_' . $this->option_group, true ) ) {
				return;
			}

			do_action( 'wpsf_before_tab_links_' . $this->option_group );
			?>
            <h2 class="nav-tab-wrapper">
				<?php
				$i = 0;
				foreach ( $this->tabs as $tab_data ) {
					$active = $i == 0 ? 'nav-tab-active' : '';
					?>
                    <a class="nav-tab wpsf-tab-link <?php echo $active; ?>"
                       href="#tab-<?php echo $tab_data['id']; ?>"><?php echo $tab_data['title']; ?></a>
					<?php
					$i ++;
				}
				?>
            </h2>
			<?php
			do_action( 'wpsf_after_tab_links_' . $this->option_group );
		}

		/**
		 * Check if this settings instance has tabs
		 */
		public function has_tabs() {
			if ( ! empty( $this->tabs ) ) {
				return true;
			}

			return false;
		}
	}
}

if ( ! function_exists( 'wpsf_get_setting' ) ) {
	/**
	 * Get a setting from an option group
	 *
	 * @param string $option_group
	 * @param string $section_id May also be prefixed with tab ID
	 * @param string $field_id
	 *
	 * @return mixed
	 */
	function wpsf_get_setting( $option_group, $section_id, $field_id ) {
		$options = get_option( $option_group . '_settings' );
		if ( isset( $options[ $section_id . '_' . $field_id ] ) ) {
			return $options[ $section_id . '_' . $field_id ];
		}

		return false;
	}
}

if ( ! function_exists( 'wpsf_delete_settings' ) ) {
	/**
	 * Delete all the saved settings from a settings file/option group
	 *
	 * @param string $option_group
	 */
	function wpsf_delete_settings( $option_group ) {
		delete_option( $option_group . '_settings' );
	}
}
