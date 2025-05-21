<?php
/**
 * Plugin Name:       	GravityView - Gravity Forms Entry Revisions
 * Plugin URI:        	https://gravityview.co/extensions/entry-revisions/
 * Description:       	Track changes to Gravity Forms entries and restore from previous revisions. Requires Gravity Forms 2.0 or higher.
 * Version:          	1.2.1
 * Author:            	GravityView
 * Author URI:        	https://gravityview.co
 * Text Domain:       	gv-entry-revisions
 * License:           	GPLv2 or later
 * License URI: 		http://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path:			/languages
 */

/**
 * Class GWP_GV_Entry_Revisions
 * @todo revision date merge tag
 */
class GWP_GV_Entry_Revisions {

	/**
	 * @var string The storage key used in entry meta storage
	 * @since 1.0
	 * @see gform_update_meta()
	 * @see gform_get_meta()
	 */
	private static $meta_key = 'gv_revisions';

	/**
	 * @var string The name of the meta key used to store revision details in the entry array
	 * @since 1.0
	 */
	private static $entry_key = 'gv_revision';

	/**
	 * Instantiate the class
	 *
	 * @author GravityWP
	 * @since v1.0
	 * @version v1.0.0
	 * @access public static
	 *
	 *
	 * @return void
	 */
	public static function load() {
		if( ! did_action( 'gv_entry_versions_loaded' ) ) {
			new self;
			do_action( 'gv_entry_versions_loaded' );
		}
	}

	/**
	 * GV_Entry_Revisions constructor.
	 *
	 * @author GravityWP
	 * @since v1.0
	 * @version v1.0.0
	 * @access private
	 *
	 *
	 * @return void
	 */
	private function __construct() {
		$this->add_hooks();
	}

	/**
	 * Add hooks on the single entry screen
	 *
	 * @author GravityWP
	 * @since v1.0
	 * @version v1.0.0
	 * @access private
	 *
	 *
	 * @return void
	 */
	private function add_hooks() {

	    // Save entry revision on the front end and back end
		add_action( 'gform_after_update_entry', array( $this, 'save' ), 10, 3 );

		// We only run on the entry detail page
		if( 'entry_detail' !== GFForms::get_page() ) {
			return;
		}

		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'add_meta_box' ) );

		add_action( 'admin_init', array( $this, 'restore' ) );

		// If showing a revision, get rid of all metaboxes and lingering HTML stuff
		if( isset( $_GET['revision'] ) ) {
			add_action( 'gform_entry_detail_sidebar_before', array( $this, 'start_ob_start' ) );
			add_action( 'gform_entry_detail_content_before', array( $this, 'start_ob_start' ) );

			add_action( 'gform_entry_detail', array( $this, 'end_ob_start' ) );
			add_action( 'gform_entry_detail_sidebar_after', array( $this, 'end_ob_start' ) );
		}
	}

	/**
	 * Alias for ob_start(), since output buffering and actions don't get along
	 * @since 1.0
	 * @return void
	 */
	public function start_ob_start() {
		ob_start();
	}

	/**
	 * Alias for ob_clean(), since output buffering and actions don't get along
	 * @since 1.0
	 * @return void
	 */
	public function end_ob_start() {
		ob_clean();
	}

	/**
	 * Fires after the Entry is updated from the entry detail page.
	 *
	 * @since 1.0
	 *
	 * @param array<mixed>   $form           The form object for the entry.
	 * @param integer $entry_id     The entry ID.
	 * @param array<mixed>   $original_entry The entry object before being updated.
	 *
	 * @return void
	 */
	public function save( $form = array(), $entry_id = 0, $original_entry = array() ) {
		$this->add_revision( $form, $entry_id, $original_entry );
	}

	/**
	 * Adds a revision for an entry
	 *
	 * @since 1.0
	 *
     * @param array<mixed> $form The form object for the entry.
	 * @param int|array<mixed> $entry_or_entry_id Current entry ID or current entry array
	 * @param array<mixed> $revision_to_add Previous entry data to add as a revision
	 *
	 * @return bool false: Nothing changed; true: updated
	 */
	private function add_revision( $form, $entry_or_entry_id = 0, $revision_to_add = array() ) {

		if( ! is_array( $entry_or_entry_id ) && is_numeric( $entry_or_entry_id ) ) {
			$current_entry = GFAPI::get_entry( $entry_or_entry_id );
		} else {
			$current_entry = $entry_or_entry_id;
		}

		if ( ! is_array( $current_entry ) ) {
			return false;
		}

		// Find the fields that changed
		$changed_fields = $this->get_modified_entry_fields( $revision_to_add, $current_entry );

		// Nothing changed
		if( empty( $changed_fields ) ) {
			return false;
		}

		$revisions = $this->get_revisions( $entry_or_entry_id );

		$revision_to_add[ self::$entry_key ] = array(
			'date' => current_time( 'timestamp', 0 ),
			'date_gmt' => current_time( 'timestamp', 1 ),
			'user_id' => get_current_user_id(),
			'changed' => $changed_fields,
		);

		if ( empty( $revisions ) ) {
			$revisions = array( $revision_to_add );
		} else {
			$revisions[] = $revision_to_add;
		}

		gform_update_meta( $entry_or_entry_id, self::$meta_key, maybe_serialize( $revisions ) );

		// Add note so we can display the record on the front end
		$user_data = get_userdata( get_current_user_id() );
		$note = '';
		foreach ( $changed_fields as $key => $old_value ) {
		    $field = RGFormsModel::get_field( $form, $key );

			if ( isset( $field->type ) ){
				if ( 'list' == $field->type ) {
					if ( ! empty( $old_value ) ) {
						$old_value_arr = unserialize( $old_value );
						$old_value = "\r\n";

						if ( is_array( $old_value_arr ) ) {
							foreach ( $old_value_arr as $_key => $row ) {
								$old_value .= 'Row ' . ( $_key + 1 ) . ': ';
								$row = array_values( $row );
								$old_value .= implode( ', ', $row ) . "\r\n";
							}
						}
					}

					if ( ! empty( $current_entry[$key] ) ) {
						$new_value_arr = unserialize( $current_entry[$key] );
						$new_value = "\r\n";

						if ( is_array( $new_value_arr ) ) {
							foreach ( $new_value_arr as $_key => $row ) {
								$new_value .= 'Row ' . ( $_key + 1 ) . ': ';
								$row = array_values( $row );
								$new_value .= implode( ', ', $row ) . "\r\n";
							}
						}

						$current_entry[$key] = $new_value;
					}
				} elseif ( 'multiselect' == $field->type ) {
					$choices = (array) $field->choices;
					$choice_labels = wp_list_pluck( $choices, 'text' );
					$choice_values = wp_list_pluck( $choices, 'value' );

					if ( ! empty( $old_value ) ) {
						$old_value_arr = json_decode( $old_value );
						$_old_value_arr = array();

						foreach ( $old_value_arr as $arr_value ) {
							$_key = array_search( $arr_value, $choice_values );
							if ( false !== $_key ) {
								$_old_value_arr[] = $choice_labels[$_key];
							}
						}

						$old_value = json_encode( $_old_value_arr );
					} 

					if ( ! empty( $current_entry[$key] ) ) {
						$new_value_arr = json_decode( $current_entry[$key] );
						$_new_value_arr = array();

						foreach ( $new_value_arr as $arr_value ) {
							$_key = array_search( $arr_value, $choice_values );
							if ( false !== $_key ) {
								$_new_value_arr[] = $choice_labels[$_key];
							}
						}

						$current_entry[$key] = json_encode( $_new_value_arr );
					}
				} elseif ( 'fileupload' == $field->type ) {

					if ( ! empty( $old_value ) ) {
						if ( $field->multipleFiles ) {
							$urls = json_decode( $old_value );
							foreach ( $urls as &$url ) {
								$url = wp_basename( $url );
							}
							$old_value = json_encode( $urls );
						} else {
							$old_value = wp_basename( $old_value );
						}
					} 

					if ( ! empty( $current_entry[$key] ) ) {
						if ( $field->multipleFiles ) {
							$urls = json_decode( $current_entry[$key] );
							foreach ( $urls as &$url ) {
								$url = wp_basename( $url );
							}
							$current_entry[$key] = json_encode( $urls );
						} else {
							$current_entry[$key] = wp_basename( $current_entry[$key] );
						}
					}
				}
			}
			
			if ( $old_value === '' ) {
			$old_value = "[ " . __( 'empty', 'gravityview-entry-revisions' ) . " ]";
			}
			
			if ( $current_entry[$key] === '' ) {
			$current_entry[$key] = "[ " . __( 'empty', 'gravityview-entry-revisions' ) . " ]";
			}

			$field_label = isset( $field->label ) ? $field->label : $key;
	
		    $note .= __( 'Field', 'gravityview-entry-revisions' ) . " " . $field_label . "\n" . '&nbsp;&nbsp;-- ' . __( 'From', 'gravityview-entry-revisions' ) . ": " . $old_value  . "\n" . '&nbsp;&nbsp;-- ' . __( 'To', 'gravityview-entry-revisions' ) . ": " . $current_entry[$key] . "\r\n" . "\n";
        }
		RGFormsModel::add_note( $entry_or_entry_id, get_current_user_id(), $user_data->display_name, $note );

		return true;
	}


	/**
	 * Compares old entry array to new, return array of differences
	 *
	 * @param array<mixed> $old
	 * @param array<mixed> $new
	 *
	 * @return array<mixed> array of differences, with keys preserved
	 */
	private function get_modified_entry_fields( $old = array(), $new = array() ) {

		$return = $old;

		foreach( $old as $key => $old_value ) {
			// Gravity Forms itself uses == comparison
			if( rgar( $new, $key ) == $old_value ) {
				unset( $return[ $key ] );
			}
		}

		return $return;
	}

	/**
	 * Get all revisions connected to an entry
	 *
	 * @since 1.0
	 *
	 * @param int $entry_id
	 *
	 * @return array<mixed> Empty array if none found. Array if found
	 */
	public function get_revisions( $entry_id = 0 ) {

		$return = array();
		$revisions = gform_get_meta( $entry_id, self::$meta_key );

		if( $revisions ) {
			$revisions = maybe_unserialize( $revisions );

			// Single meta? Make it an array
			$return = isset( $revisions['id'] ) ? array( $revisions ) : $revisions;
		}

		krsort( $return );

		return $return;
	}

	/**
	 * Get the latest revision
	 *
	 * @since v0.0.1
	 * @version v1.0.0
	 * @access public
	 *
	 * @param int $entry_id 
	 *
	 * @return array<mixed> Empty array, if no revisions exist. Otherwise, last revision.
	 */
	public function get_last_revision( $entry_id ) {

		$revisions = $this->get_revisions( $entry_id );

		if ( empty( $revisions ) ) {
			return array();
		}

		$revision = array_pop( $revisions );

		return $revision;
	}

	/*
	 * Deletes all revisions for an entry
	 *
	 * @author GravityWP
	 * @since v1.0
	 * @version v1.0.0
	 * @access private
	 *
	 * @param int $entry_id ID of the entry to remove revsions
	 * 
	 * @return int|bool
	private function delete_revisions( $entry_id = 0 ) {
		gform_delete_meta( $entry_id, self::$meta_key );
	}
	*/

	/**
	 * Remove a revision from an entry
	 *
	 * @since 1.0
	 *
	 * @param int $entry_id
	 * @param int $revision_id Revision GMT timestamp
	 *
	 * @return bool False if revision isn't found; true if gform_update_meta called.
	 */
	private function delete_revision( $entry_id = 0, $revision_id = 0 ) {

		$revisions = $this->get_revisions( $entry_id );

		if( empty( $revisions ) ) {
			return false;
		}

		foreach ( $revisions as $key => $revision ) {
			if( intval( $revision_id ) === intval( $revision[self::$entry_key]['date_gmt'] ) ) {
				unset( $revisions["{$key}"] );
				break;
			}
		}

		gform_update_meta( $entry_id, self::$meta_key, maybe_serialize( $revisions ) );

		return true;
	}

	/**
	 * Get a specific revision by the GMT timestamp
	 *
	 * @since 1.0
	 *
	 * @param int $entry_id
	 * @param int $revision_id GMT timestamp of revision
	 *
	 * @return array<mixed>|false Array if found, false if not.
	 */
	private function get_revision( $entry_id = 0, $revision_id = 0 ) {

		$revisions = $this->get_revisions( $entry_id );

		foreach ( $revisions as $revision ) {

			if( intval( $revision_id ) === intval( rgars( $revision, self::$entry_key . '/date_gmt' ) ) ) {
				return $revision;
			}
		}

		return false;
	}

	/**
	 * Restores an entry to a specific revision, if the revision is found
	 *
	 * @param int $entry_id ID of entry
	 * @param int $revision_id ID of revision (GMT timestamp)
	 *
	 * @return bool|WP_Error WP_Error if there was an error during restore. true if success; false if failure
	 */
	public function restore_revision( $entry_id = 0, $revision_id = 0 ) {

		$revision = $this->get_revision( $entry_id, $revision_id );

		// Revision has already been deleted or does not exist
		if( empty( $revision ) ) {
			return new WP_Error( 'not_found', __( 'Revision not found', 'gravityview-entry-revisions' ), array( 'entry_id' => $entry_id, 'revision_id' => $revision_id ) );
		}

		$current_entry = GFAPI::get_entry( $entry_id );

		/**
		 * @param bool $restore_entry_meta Whether to restore entry meta as well as field values. Default: false
		 */
		if( false === apply_filters( 'gv-entry-revisions/restore-entry-meta', false ) ) {

			// Override revision details with current entry details
			foreach ( $current_entry as $key => $value ) {
				if ( ! is_numeric( $key ) ) {
					$revision[ $key ] = $value;
				}
			}
		}

		// Remove all hooks
		remove_all_filters( 'gform_entry_pre_update' );
		remove_all_filters( 'gform_form_pre_update_entry' );
		remove_all_filters( sprintf( 'gform_form_pre_update_entry_%s', $revision['form_id'] ) );
		remove_all_actions( 'gform_post_update_entry' );
		remove_all_actions( sprintf( 'gform_post_update_entry_%s', $revision['form_id'] ) );

		// Remove the entry key data
		unset( $revision[ self::$entry_key ] );

		$updated_result = GFAPI::update_entry( $revision, $entry_id );

		if ( is_wp_error( $updated_result ) ) {

			/** @var WP_Error $updated_result */
			GFCommon::log_error( $updated_result->get_error_message() );

			return $updated_result;

		} else {

			// Store the current entry as a revision, too, so you can revert
			// AJK: commented out for now as this call has the wrong arguments and will do nothin good.
			//$this->add_revision( $entry_id, $current_entry );

			/**
			 * Should the revision be removed after it has been restored? Default: false
			 * @param bool $remove_after_restore [Default: false]
			 */
			if( apply_filters( 'gv-entry-revisions/delete-after-restore', false ) ) {
				$this->delete_revision( $entry_id, $revision_id );
			}

			return true;
		}
	}

	/**
	 * Restores an entry
	 *
	 * @since 1.0
	 *
	 * @return void Redirects to single entry view after completion
	 */
	public function restore() {

		if( rgget('restore') && rgget('view') && rgget( 'lid' ) ) {

			// No access!
			if( ! GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
				GFCommon::log_error( 'Restoring the entry revision failed: user does not have the "gravityforms_edit_entries" capability.' );
				return;
			}

			$revision_id = rgget( 'restore' );
			$entry_id = rgget( 'lid' );
			$nonce = rgget( '_wpnonce' );
			$nonce_action = $this->generate_restore_nonce_action( absint( $entry_id ), absint( $revision_id ) );
			$valid = wp_verify_nonce( $nonce, $nonce_action );

			// Nonce didn't validate
			if( ! $valid ) {
				GFCommon::log_error( 'Restoring the entry revision failed: nonce validation failed.' );
				return;
			}

			// Handle restoring the entry
			$this->restore_revision( absint( $entry_id ), absint( $revision_id ) );

			wp_safe_redirect( remove_query_arg( 'restore' ) );
			exit();
		}
	}

	/**
	 * Allow custom meta boxes to be added to the entry detail page.
	 *
	 * @since 1.0
	 *
	 * @param array<mixed> $meta_boxes The properties for the meta boxes.
	 * @param array<mixed> $entry The entry currently being viewed/edited.
	 * @param array<mixed> $form The form object used to process the current entry.
	 *
	 * @return array<mixed> $meta_boxes, with the Versions box added
	 */
	public function add_meta_box( $meta_boxes = array(), $entry = array(), $form = array() ) {

		$revision_id = rgget('revision');

		if( ! empty( $revision_id )  ) {
			$meta_boxes = array();
			$meta_boxes[ self::$meta_key ] = array(
				'title'    => 'Restore Entry Revision',
				'callback' => array( $this, 'meta_box_restore_revision' ),
				'context'  => 'normal',
			);
		} else {
			$meta_boxes[ self::$meta_key ] = array(
				'title'    => 'Entry Revisions',
				'callback' => array( $this, 'meta_box_entry_revisions' ),
				'context'  => 'normal',
			);
		}

		return $meta_boxes;
	}


	/**
	 * Gets an array of diff table output comparing two entries
	 *
	 * @uses wp_text_diff()
	 *
	 * @param array<mixed> $previous Previous entry
	 * @param array<mixed> $current Current entry
	 * @param array<mixed> $form Entry form
	 *
	 * @return array<mixed> Array of diff output generated by wp_text_diff()
	 */
	private function get_diff( $previous = array(), $current = array(), $form = array() ) {

		$return = array();

		foreach ( $previous as $key => $previous_value ) {

			// Don't compare `gv_revision` data
			if( self::$entry_key === $key ) {
				continue;
			}

			$current_value = rgar( $current, $key );

			$field = GFFormsModel::get_field( $form, $key );

			if( ! $field ) {
				continue;
			}

			$label = GFCommon::get_label( $field );

			$diff = wp_text_diff( $previous_value, $current_value, array(
				'show_split_view' => true,
				'title' => sprintf( esc_html__( '%s (Field %s)', 'gravityview-entry-revisions' ), $label, $key ),
				'title_left' => esc_html__( 'Entry Revision', 'gravityview-entry-revisions' ),
				'title_right' => esc_html__( 'Current Entry', 'gravityview-entry-revisions' ),
			) );

			/**
			 * Fix the issue when using 'title_left' and 'title_right' of TWO extra blank <td></td>s being added. We only want one.
			 * @see wp_text_diff()
			 */
			$diff = str_replace( "<tr class='diff-sub-title'>\n\t<td></td>", "<tr class='diff-sub-title'>\n\t", $diff );

			if ( $diff ) {
				$return[ $key ] = $diff;
			}
		}

		return $return;
	}

	/**
	 * Display entry content comparison and restore button
	 *
	 * @since 1.0
	 *
	 * @param array<mixed> $data Array with entry/form/mode keys.
	 *
	 * @return void
	 */
	public function meta_box_restore_revision( $data = array() ) {

		$mode = rgar( $data, 'mode' );

		if( 'view' !== $mode ) {
			return;
		}

		$entry = rgar( $data, 'entry' );
		$form = rgar( $data, 'form' );
		$revision = $this->get_revision( absint( $entry['id'] ), absint( rgget( 'revision' ) ) );

		$diff_output = '';
		$diffs = $this->get_diff( $revision, $entry, $form );

		if ( empty( $diffs ) ) {
			echo '<h3>' . esc_html__( 'This revision is identical to the current entry.', 'gravityview-entry-revisions' ) . '</h3>';
			?><a href="<?php echo esc_url( remove_query_arg( 'revision' ) ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Return to Entry' ); ?></a><?php
			return;
		}

		echo wpautop( $this->revision_title( $revision, false, 'The entry revision was created by %2$s, %3$s ago (%4$s).' ) );

		echo '<hr />';

		echo '<style>
		table.diff {
			margin-top: 1em;
		}
		table.diff .diff-title th {
			font-weight: normal;
			text-transform: uppercase;
		}
		table.diff .diff-title th {
			font-size: 18px;
			padding-top: 10px;
		}
		table.diff .diff-deletedline { 
			background-color: #edf3ff;
			 border:  1px solid #dcdcdc;
		}
		table.diff .diff-addedline { 
			background-color: #f7fff7; 
			border:  1px solid #ccc;
		}
		 </style>';

		foreach ( $diffs as $diff ) {
			$diff_output .= $diff;
		}

		echo $diff_output;
		?>

		<hr />

		<p class="wp-clearfix">
			<a href="<?php echo $this->get_restore_url( $revision ); ?>" class="button button-primary button-hero alignleft" onclick="return confirm('<?php esc_attr_e( 'Are you sure? The Current Entry data will be replaced with the Entry Revision data shown.' ) ?>');"><?php esc_html_e( 'Restore This Entry Revision' ); ?></a>
			<a href="<?php echo esc_url( remove_query_arg( 'revision' ) ); ?>" class="button button-secondary button-hero alignright"><?php esc_html_e( 'Cancel: Keep Current Entry' ); ?></a>
		</p>
	<?php
	}

	/**
	 * Generate a nonce action to secure the restoring process
	 *
	 * @since 1.0
	 *
	 * @param int $entry_id
	 * @param int $revision_date_gmt
	 *
	 * @return string
	 */
	private function generate_restore_nonce_action( $entry_id = 0, $revision_date_gmt = 0 ) {
		return sprintf( 'gv-restore-entry-%d-revision-%d', intval( $entry_id ), intval( $revision_date_gmt ) );
	}

	/**
	 * Returns nonce URL to restore a revision
	 *
	 * @param array<mixed> $revision Revision entry array
	 *
	 * @return string
	 */
	private function get_restore_url( $revision = array() ) {

		$nonce_action = $this->generate_restore_nonce_action( $revision['id'], $revision[ self::$entry_key ]['date_gmt'] );

		return wp_nonce_url( add_query_arg( array( 'restore' => $revision[ self::$entry_key ]['date_gmt'] ), remove_query_arg( 'revision' ) ), $nonce_action );
	}

	/**
	 * Function: get_revision_details_link.
	 *
	 * @author GravityWP
	 * @since v0.0.1
	 * @version v1.0.0
	 * @access private
	 *
	 * @param array<mixed> $revision Default: array()
	 *
	 * @return mixed
	 */
	private function get_revision_details_link( $revision = array() ) {
		return add_query_arg( array( 'revision' => $revision[ self::$entry_key ]['date_gmt'] ) );
	}

	/**
	 * Retrieve formatted date timestamp of a revision (linked to that revision details page).
	 *
	 * @since 1.0
	 *
	 * @see wp_post_revision_title() for inspiration
	 *
	 * @param array<mixed> $revision Revision entry array
	 * @param bool       $link     Optional, default is true. Link to revision details page?
	 * @param string $format post revision title: 1: author avatar, 2: author name, 3: time ago, 4: date
	 *
	 * @return string HTML of the revision version
	 */
	private function revision_title( $revision, $link = true, $format = '%1$s %2$s, %3$s ago (%4$s)' ) {

		$revision_details = rgar( $revision, self::$entry_key );

		$revision_user_id = rgar( $revision_details, 'user_id' );

		$author = get_the_author_meta( 'display_name', $revision_user_id );
		/* translators: revision date format, see http://php.net/date */
		$datef = _x( 'F j, Y @ H:i:s', 'revision date format' );

		$gravatar = get_avatar( $revision_user_id, 32 );
		$date = date_i18n( $datef, $revision_details['date'] );
		if ( $link ) { //&& current_user_can( 'edit_post', $revision->ID ) && $link = get_edit_post_link( $revision->ID ) )
			$link = $this->get_revision_details_link( $revision );
			$date = "<a href='$link'>$date</a>";
		}

		$revision_date_author = sprintf(
			$format,
			$gravatar,
			$author,
			human_time_diff( $revision_details['date_gmt'], current_time( 'timestamp', true ) ),
			$date
		);

		return $revision_date_author;
	}

	/**
	 * Display the meta box for the list of revisions
	 *
	 * @since 1.0
	 *
	 * @param array<mixed> $data Array of data with entry, form, mode keys
	 *
	 * @return void
	 */
	public function meta_box_entry_revisions( $data ) {

		$mode = rgar( $data, 'mode' );

		if( 'view' !== $mode ) {
			return;
		}

		$entry_id = rgars( $data, 'entry/id' );
		$entry = rgar( $data, 'entry' );
		$form = rgar( $data, 'form' );
		$revisions = $this->get_revisions( $entry_id );

		if( empty( $revisions ) ) {
			echo wpautop( esc_html__( 'This entry has no revisions.', 'gravityview-entry-revisions' ) );
			return;
		}

		$rows = '';
		foreach ( $revisions as $revision ) {
			$diffs = $this->get_diff( $revision, $entry, $form );

			// Only show if there are differences
			if( ! empty( $diffs ) ) {
				$rows .= "\t<li>" . $this->revision_title( $revision ) . "</li>\n";
			}
		}

		echo "<div class='hide-if-js'><p>" . __( 'JavaScript must be enabled to use this feature.', 'gravityview-entry-revisions' ) . "</p></div>\n";

		echo "<ul class='post-revisions hide-if-no-js'>\n";
		echo $rows;
		echo "</ul>";
	}
}

add_action( 'gform_loaded', array( 'GWP_GV_Entry_Revisions', 'load' ) );

// Translation files of the plugin
add_action('plugins_loaded', 'gv_entry_revisions_load_textdomain');
	/**
	 * Function: gv_entry_revisions_load_textdomain.
	 *
	 * @author GravityWP
	 * @since v0.0.1
	 * @version v1.0.0
	 *
	 * @return void
	 */
	function gv_entry_revisions_load_textdomain() {
		load_plugin_textdomain( 'gravityview-entry-revisions', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
}

/**
 * Updates the changelog when an entry is updated with Inline Edit.
 *
 * This function is hooked to the `gravityview-inline-edit/entry-updated` filter and logs changes made to form entries.
 * It compares the old and new field values and adds a note to the entry with details of the change.
 *
 * @param bool  $update_result  Whether the update was successful.
 * @param array $entry          The updated entry data.
 * @param int   $form_id        The ID of the form being updated.
 * @param array $gf_field       The Gravity Forms field data.
 * @param array $original_entry The original entry data before update.
 *
 * @return bool Returns the result of the update operation.
 */
function aiwos_update_changelog_on_inline_edit( $update_result, $entry, $form_id, $gf_field, $original_entry ) {

	if ( $update_result ) {

		$user      = wp_get_current_user();
		$field_id  = $gf_field['id'] ?? '';
		$old_value = $original_entry[ $field_id ] ?? '';
		$new_value = $entry[ $field_id ] ?? '';

		$note = __( 'Field', 'gv-entry-revisions' ) . ' ' . $gf_field['label'] . "\n" . '&nbsp;&nbsp;-- ' . __( 'From', 'gv-entry-revisions' ) . ': ' . $old_value . "\n" . '&nbsp;&nbsp;-- ' . __( 'To', 'gv-entry-revisions' ) . ': ' . $new_value . "\r\n\n";

		RGFormsModel::add_note( $entry['id'], $user->ID, $user->display_name, $note );
	}
	return $update_result;
}
add_filter( 'gravityview-inline-edit/entry-updated', 'aiwos_update_changelog_on_inline_edit', 10, 5 );

/**
 * Updates the changelog when an entry is updated by a workflow step of another form.
 *
 * This function is triggered on the `gform_post_update_entry` action.
 * It compares the original entry with the updated entry and logs any field changes.
 * An entry note is added for each changed field, detailing the old and new values.
 *
 * @param array $entry          The updated entry data.
 * @param array $original_entry The original entry data before update.
 *
 * @return array Returns the updated entry data.
 */
function aiwos_update_changelog_on_workflow_step_other_form( $entry, $original_entry ) {

	// Duplicate original entry to check for changes.
	$changed_fields = $original_entry;

	foreach ( $changed_fields as $key => $old_value ) {

		if ( rgar( $entry, $key ) === $old_value ) {
			unset( $changed_fields[ $key ] );
		} elseif ( empty( $entry[ $key ] ) && empty( $old_value ) ) {
			unset( $changed_fields[ $key ] );
		}
	}
	if ( count( $changed_fields ) > 0 ) {

		// Log changes.
		$note_title = __( 'Workflow step', 'aiwos-default' );
		$note       = '';

		foreach ( $changed_fields as $key => $new_value ) {

			$field = RGFormsModel::get_field( $entry['form_id'], $key ) ?? '';

			$note .= __( 'Field', 'gv-entry-revisions' ) . ' ' . $field['label'] . "\n" . '&nbsp;&nbsp;-- ' . __( 'From', 'gv-entry-revisions' ) . ': ' . $entry[ $key ] . "\n" . '&nbsp;&nbsp;-- ' . __( 'To', 'gv-entry-revisions' ) . ': ' . $new_value . "\r\n\n";
		}
		RGFormsModel::add_note( $entry['id'], 0, $note_title, $note );
	}
	return $entry;
}
add_action( 'gform_post_update_entry', 'aiwos_update_changelog_on_workflow_step_other_form', 10, 2 );