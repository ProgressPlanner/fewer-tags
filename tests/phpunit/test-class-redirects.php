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

	/**
	 * Yoast SEO Premium refuses a second redirect for the same origin; that must be
	 * reported as a failure, so the deleted-term notice stays and can be retried.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_existing_yoast_redirect_is_reported_as_failure() {
		require_once dirname( __DIR__ ) . '/fixtures/class-wpseo-redirect.php';
		define( 'WPSEO_PREMIUM_VERSION', 'test' );

		$redirects = new Redirects();
		$this->assertTrue( $redirects->create_yoast_redirect( '/tag/first/', '/' ) );
		$this->assertSame( [ '/tag/first/' => '/' ], \WPSEO_Redirect_Option::$saved );

		\WPSEO_Redirect_Option::$saved['/tag/old-tag/'] = '/elsewhere/';
		Option::get_instance()->set(
			'terms_to_redirect',
			[
				'post_tag' => [
					'old-tag' => [
						'name'      => 'Old tag',
						'permalink' => home_url( '/tag/old-tag/' ),
					],
				],
			]
		);

		$this->assertFalse( $redirects->create_redirect_from_slug( 'old-tag', 'post_tag', '/' ) );
		$this->assertSame( '/elsewhere/', \WPSEO_Redirect_Option::$saved['/tag/old-tag/'] );
		$this->assertArrayHasKey( 'old-tag', Option::get_instance()->get( 'terms_to_redirect' )['post_tag'] );
	}
}
