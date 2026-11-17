<?php
/**
 * REST URL configuration tests.
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

use FewerTags\Plugin;

/**
 * Tests the REST endpoints passed to the merge UI.
 */
class Plugin_Rest_Test extends \WP_UnitTestCase {
	/**
	 * The merge UI uses WordPress URLs for subdirectory sites and both permalink modes.
	 *
	 * @dataProvider data_permalink_structures
	 *
	 * @param string $structure The permalink structure.
	 */
	public function test_enqueue_rest_urls( $structure ) {
		global $wp_rewrite;
		$original_structure = $wp_rewrite->permalink_structure;
		$wp_rewrite->set_permalink_structure( $structure );
		update_option( 'home', 'http://example.org/subsite' );
		update_option( 'siteurl', 'http://example.org/subsite' );
		register_taxonomy(
			'book_topic',
			'post',
			[
				'public'         => true,
				'show_in_rest'   => true,
				'rest_namespace' => 'books/v1',
				'rest_base'      => 'topics',
			]
		);
		set_current_screen( 'edit-post_tag' );
		wp_deregister_script( 'fewer-tags' );

		try {
			$plugin = new Plugin();
			$plugin->enqueue_scripts();
			$before = wp_scripts()->get_data( 'fewer-tags', 'before' );
			$this->assertIsArray( $before, 'REST configuration must be available before the script starts.' );
			$config = json_decode( substr( end( $before ), strlen( 'const fewerTags = ' ) ), true );

			$this->assertSame( add_query_arg( 'context', 'edit', rest_url( 'wp/v2/taxonomies' ) ), $config['taxonomiesUrl'] );
			$this->assertSame( rest_url( 'wp/v2/tags' ), $config['termUrls']['post_tag'] );
			$this->assertSame( rest_url( 'wp/v2/categories' ), $config['termUrls']['category'] );
			$this->assertSame( rest_url( 'books/v1/topics' ), $config['termUrls']['book_topic'] );
			$this->assertStringContainsString( '/subsite/', $config['termUrls']['post_tag'] );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
			unregister_taxonomy( 'book_topic' );
			wp_deregister_script( 'fewer-tags' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * Permalink modes supported by the merge UI.
	 *
	 * @return array[]
	 */
	public function data_permalink_structures() {
		return [
			'pretty' => [ '/%postname%/' ],
			'plain'  => [ '' ],
		];
	}
}
