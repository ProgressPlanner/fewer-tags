<?php
/**
 * Non-AJAX deletion integration coverage.
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

use FewerTags\Admin;
use FewerTags\Helper;
use FewerTags\Option;
use FewerTags\Plugin;

/**
 * Tests the normal admin request used by WordPress bulk deletion.
 */
class Plugin_Deletion_Test extends \WP_UnitTestCase {
	/**
	 * Every bulk-deleted term retains its archive URL and a redirect notice.
	 */
	public function test_bulk_deletion_offers_redirects() {
		set_current_screen( 'edit-post_tag' );
		add_filter( 'wp_doing_ajax', '__return_false' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Option::get_instance()->set( 'terms_to_redirect', [] );

		try {
			$plugin = new Plugin();
			$plugin->init();

			// A real edit-tags.php request fires admin_init before the deletion,
			// which registers the fewer_tags sanitizer on every update_option().
			// Run only the plugin's callback: core's admin_init callbacks send headers.
			( new Admin() )->register_settings();
			$this->assertNotFalse( has_filter( 'sanitize_option_fewer_tags' ) );
			$terms = [];
			foreach ( [ 'first-deleted', 'second-deleted' ] as $slug ) {
				$term           = self::factory()->tag->create( [ 'slug' => $slug ] );
				$terms[ $slug ] = get_term_link( $term, 'post_tag' );
				wp_delete_term( $term, 'post_tag' );
			}

			foreach ( $terms as $slug => $url ) {
				$stored = Helper::get_term_to_redirect( 'post_tag', $slug );
				$this->assertNotNull( $stored );
				$this->assertSame( $url, $stored['permalink'] );
			}

			// The next request reads the database, not the in-memory Option cache.
			$saved = get_option( 'fewer_tags' );
			$this->assertArrayHasKey( 'post_tag', $saved['terms_to_redirect'] ?? [], 'Deleted terms were not saved to the database.' );
			foreach ( $terms as $slug => $url ) {
				$this->assertArrayHasKey( $slug, $saved['terms_to_redirect']['post_tag'] );
				$this->assertSame( $url, $saved['terms_to_redirect']['post_tag'][ $slug ]['permalink'] );
			}

			ob_start();
			$plugin->do_notices();
			$notices = ob_get_clean();
			$this->assertStringContainsString( 'fewer-tags-redirect-first-deleted', $notices );
			$this->assertStringContainsString( 'fewer-tags-redirect-second-deleted', $notices );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_false' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * Rows rendered through admin-ajax.php (Add New Tag, Quick Edit) keep the
	 * plugin's column, hidden View action and Merge action.
	 */
	public function test_ajax_requests_register_list_table_hooks() {
		set_current_screen( 'edit-post_tag' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		try {
			$plugin = new Plugin();
			$plugin->init();

			$this->assertNotFalse( has_filter( 'manage_edit-post_tag_columns' ) );
			$this->assertNotFalse( has_filter( 'manage_post_tag_custom_column' ) );
			$this->assertNotFalse( has_filter( 'post_tag_row_actions' ) );

			// The Merge action waits for admin_init, which admin-ajax.php also fires.
			$this->assertSame( 10, has_action( 'admin_init', [ $plugin, 'register_merge_action_filters' ] ) );
			$plugin->register_merge_action_filters();
			$this->assertSame( 10, has_filter( 'tag_row_actions', [ $plugin, 'add_merge_action' ] ) );
			$this->assertSame( 10, has_filter( 'category_row_actions', [ $plugin, 'add_merge_action' ] ) );

			// Page-only hooks stay off AJAX requests.
			$this->assertFalse( has_action( 'admin_notices', [ $plugin, 'do_notices' ] ) );
			$this->assertFalse( has_action( 'admin_footer', [ $plugin, 'output_modal' ] ) );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * Taxonomies registered after the plugin's init callback still get the Merge action.
	 */
	public function test_merge_action_for_taxonomy_registered_after_init() {
		set_current_screen( 'edit-post_tag' );

		try {
			$plugin = new Plugin();
			$plugin->init();

			register_taxonomy( 'late_genre', 'post', [ 'public' => true ] );
			$this->assertFalse( has_filter( 'late_genre_row_actions', [ $plugin, 'add_merge_action' ] ) );

			$plugin->register_merge_action_filters();
			$this->assertSame( 10, has_filter( 'late_genre_row_actions', [ $plugin, 'add_merge_action' ] ) );
		} finally {
			unregister_taxonomy( 'late_genre' );
			set_current_screen( 'front' );
		}
	}
}
