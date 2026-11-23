<?php
/**
 * Non-AJAX deletion integration coverage.
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

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
}
