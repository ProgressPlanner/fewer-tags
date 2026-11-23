<?php
/**
 * Upgrade coverage for the 2.0 option format.
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

use FewerTags\Plugin;

/**
 * Verifies existing settings and redirect offers survive the upgrade.
 */
class Plugin_Upgrade_Test extends \WP_UnitTestCase {
	/**
	 * Free-plugin settings survive migration, including the disabled threshold.
	 *
	 * @dataProvider data_thresholds
	 *
	 * @param int $threshold The old scalar setting.
	 */
	public function test_migrate_free_setting( $threshold ) {
		delete_option( 'joost_min_posts_count' );
		delete_option( 'fewer_tags_pro' );
		update_option( 'fewer_tags', $threshold );
		$plugin = new Plugin();
		$plugin->migrate_option();

		$this->assertSame( [ 'min_posts_count' => $threshold ], get_option( 'fewer_tags' ) );
		$plugin->migrate_option();
		$this->assertSame( [ 'min_posts_count' => $threshold ], get_option( 'fewer_tags' ) );
	}

	/**
	 * Existing Pro redirect data is retained alongside the free-plugin threshold.
	 */
	public function test_migrate_pro_redirect_data() {
		$redirects = [
			'post_tag' => [
				'old-tag' => [
					'name'      => 'Old tag',
					'permalink' => home_url( '/tag/old-tag/' ),
				],
			],
		];
		delete_option( 'joost_min_posts_count' );
		update_option( 'fewer_tags', 0 );
		update_option(
			'fewer_tags_pro',
			[
				'terms_to_redirect' => $redirects,
				'redirection_group' => 2,
			]
		);

		$plugin = new Plugin();
		$plugin->migrate_option();
		$options = get_option( 'fewer_tags' );
		$this->assertSame( 0, $options['min_posts_count'] );
		$this->assertSame( $redirects, $options['terms_to_redirect'] );
		$this->assertSame( 2, $options['redirection_group'] );
		$this->assertFalse( get_option( 'fewer_tags_pro' ) );
	}

	/**
	 * Existing free-plugin threshold values.
	 *
	 * @return array[]
	 */
	public function data_thresholds() {
		return [ [ 10 ], [ 0 ], [ -5 ] ];
	}
}
