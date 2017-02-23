<?php

/**
 * Plugin Name: WDG Postmeta Revisions
 * Plugin URI: https://github.com/kshaner/wdg-postmeta-revisions
 * Version: 0.0.1
 * Description: Adds postmeta revision support
 * Author: Kurtis Shaner, Web Development Group
 * Author URI: https://www.webdevelopmentgroup.com/
 * License: GPL v2 or later
 */

class WDG_Postmeta_Revisions {

	/**
	 * Singleton pattern
	 * @return WDG_Postmeta_Revisions
	 */

	private static $_instance;
	public static function instance() {
		if ( !isset( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 *  A blacklist of keys to never revision 
	 *  - can be modified with the "post_meta_revisions_blacklist" filter
	 */

	private $blacklist = array(
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Add our hooks
	 */

	protected function __construct() {
		add_filter( 'wp_save_post_revision_post_has_changed', [ $this, 'wp_save_post_revision_post_has_changed' ], 10, 3 );
		add_filter( '_wp_post_revision_fields', [ $this, 'wp_post_revision_fields' ], 99, 2);
		add_action( '_wp_put_post_revision', [ $this, 'wp_put_post_revision' ], 10, 1 );
		add_action( 'wp_restore_post_revision', [ $this, 'wp_restore_post_revision' ], 10, 2 );
		add_action( 'wp_creating_autosave', [ $this, 'wp_creating_autosave' ] );
		add_filter( 'get_post_metadata', [ $this, 'get_post_metadata' ], 10, 4 );

		$this->blacklist = apply_filters( 'wdg_post_meta_revisions_blacklist', $this->blacklist );
	}

	/**
	 * Singleton for getting the current screen 
	 * @return WP_Screen
	 */

	private $screen;
	private function get_current_screen() {
		if ( empty( $this->screen ) || !$this->screen instanceof WP_Screen ) {
			$this->screen = get_current_screen();
		}
		return $this->screen;
	}

	/**
	 * Are we on the current screen arg?
	 * @var $screen 
	 * @return bool
	 */

	private function is_screen( $screen ) {
		global $pagenow;

		switch( $screen ) {
			case 'postedit':
				return $pagenow === 'post.php' && !empty( $_POST['action'] ) && $_POST['action'] === 'editpost';
			case 'revision':
				return $pagenow === 'revision.php';
		}

		return false;
	}

	/**
	 * Let serialized data be revisioned
	 * 
	 */

	public function get_post_metadata( $check, $post_id, $meta_key, $single ) {
		global $wpdb, $pagenow;
		$this->get_current_screen();

		// return if not on revisions page or the post edit page
		if ( !$this->is_screen( 'postedit' ) && !$this->is_screen( 'revision' ) ) {
			return $check;
		}

		$result = $wpdb->get_var( $wpdb->prepare( 
			"SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s", 
			$post_id, 
			$meta_key 
		) );

		if ( $result !== null ) {
			return $result;
		}

		// return something
		return $check;
	}

	/**
	 * Check to see if any post meta fields have changed
	 *
	 * @action wp_save_post_revision_post_has_changed
	 * @var $post_has_changed (bool)
	 * @var $last_revision (WP_Post)
	 * @var $post (array)
	 * @return bool
	 */

	public function wp_save_post_revision_post_has_changed( $post_has_changed, $last_revision, $post ) {
		if ( false === $post_has_changed ) {
			
			$post_meta = $this->get_post_custom($post->ID);
			$revision_meta = $this->get_post_custom($last_revision->ID);

			if ( json_encode( $post_meta ) !== json_encode( $revision_meta ) ) {
				$post_has_changed = true;
			}
		}

		return $post_has_changed;
	}

	/**
	 * Get metadata for a specific post while removing blacklisted keys and sorting by key
	 *
	 * @param (int|string) post id to get values for
	 * @return (array) array of metadata entries 
	 */

	private function get_post_custom( $post_id ) {
		$data = get_post_custom( $post_id );

		$data = array_filter( $data, function( $key ) {
			return !in_array( $key, $this->blacklist );
		}, ARRAY_FILTER_USE_KEY );

		$data = array_map( function( $val ) {
			return maybe_unserialize( ( is_array( $val ) && count( $val ) === 1 ) ? $val[0] : $val );
		}, $data );

		// sort data so it's the same order so we're more likely to have a equivalent match
		ksort($data);

		return $data;
	}

	/**
	 * get a list of meta keys for a post id
	 * @var (int|str) post_id
	 * @return (array) post meta keys
	 */

	private function get_post_custom_keys( $post_id ) {
		return (array) array_keys( $this->get_post_custom( $post_id ) );
	}

	/**
	 * Add our defined fields to the fields to check for differences
	 */

	public function wp_post_revision_fields( $revision_fields, $post ) {
		global $pagenow;
		$post_id = null;

		if ( $pagenow === 'post.php' && !empty( $_POST['action'] ) && $_POST['action'] === 'editpost' ) {
			$post_id = $_POST['post_ID'];
		} else if ( $pagenow === 'revision.php' && !empty( $_GET['revision'] ) ) {
			$post_id = $_GET['revision'];
		}

		if ( !empty( $post_id ) ) {
			$keys = $this->get_post_custom_keys( $post_id );
			$revision_fields = array_merge( $revision_fields, array_combine($keys, $keys) );

			foreach( $keys as $key ) {
				add_filter('_wp_post_revision_field_' . $key, [ $this, 'format_revision_field' ], 10, 4 );
			}
		}

		return $revision_fields;
	}

	/**
	 * Format a field for the revision viewer
	 * @action _wp_post_revision_field_{{$key}}
	 */

	public function format_revision_field( $value ) {
		$value = maybe_unserialize( $value );
		return is_array($value) ? implode( PHP_EOL, $value ) : (string) $value;
	}

	/**
	 * Save the meta data values against the revision
	 */

	public function wp_put_post_revision( $revision_id ) {
		$revision = get_post( $revision_id );
		$post_meta = $this->get_post_custom( $revision->post_parent );

		if ( !empty( $post_meta ) ) {
			foreach( $post_meta as $key => $value ) {
				add_metadata( 'post', $revision_id, $key, $value );
			}
		}
	}

	/**
	 * Restore revisioned post meta values
	 *
	 * @action wp_restore_post_revision
	 */

	public function wp_restore_post_revision( $post_id, $revision_id ) {
		$post_meta = $this->get_post_custom( $revision_id );

		if ( false !== $post_meta ) {
			foreach( $post_meta as $meta_key => $meta_value ) {
				update_metadata( 'post', $post_id, $meta_key, $meta_value );
			}
		}
	}
}

add_action( 'admin_init', ['WDG_Postmeta_Revisions', 'instance'], 99 );
