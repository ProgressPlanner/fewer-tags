<?php
/**
 * Deleted archive redirect coverage.
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

use FewerTags\Option;
use FewerTags\Redirects;

/**
 * Verifies the URL passed to redirect providers.
 */
class Redirects_Test extends \WP_UnitTestCase {

	/**
	 * A local archive with a custom port keeps its path and identifying query.
	 */
	public function test_deleted_archive_uses_relative_source() {
		Option::get_instance()->set(
			'terms_to_redirect',
			[
				'post_tag' => [
					'old-tag' => [
						'name'      => 'Old tag',
						'permalink' => 'http://example.org:8879/subsite/?tag=old-tag',
					],
				],
			]
		);
		$redirects = $this->getMockBuilder( Redirects::class )
			->onlyMethods( [ 'create_redirect' ] )
			->getMock();
		$redirects->expects( $this->once() )
			->method( 'create_redirect' )
			->with( '/subsite/?tag=old-tag', 'http://example.org:8879/subsite/' )
			->willReturn( true );

		$this->assertTrue( $redirects->create_redirect_from_slug( 'old-tag', 'post_tag', 'http://example.org:8879/subsite/' ) );
		$this->assertSame( [], Option::get_instance()->get( 'terms_to_redirect' )['post_tag'] );
	}
}
