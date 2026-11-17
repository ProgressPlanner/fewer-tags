<?php
/**
 * Class Admin_Ajax_Test
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

use FewerTags\Admin_Ajax;
use FewerTags\Helper;
use FewerTags\Option;

/**
 * Tests the permission and validation checks of the AJAX handlers.
 */
class Admin_Ajax_Test extends \WP_Ajax_UnitTestCase {

	/**
	 * Set up a fresh handler and an Editor for each test.
	 */
	public function set_up() {
		parent::set_up();

		Option::get_instance()->set( 'terms_to_redirect', [] );
		new Admin_Ajax();

		$this->_setRole( 'editor' );
	}

	/**
	 * Runs an AJAX action and returns the decoded JSON response.
	 *
	 * @param string $action The AJAX action.
	 * @param array  $data   The POST data.
	 *
	 * @return array The decoded response.
	 */
	private function do_ajax( $action, $data ) {
		// WordPress slashes request data, so mimic that.
		$_POST                = wp_slash( $data );
		$_POST['_ajax_nonce'] = wp_create_nonce( $action === 'fewer_tags_get_just_deleted_term' ? 'fewer_tags_just_deleted_term' : $action );
		$_REQUEST             = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test set-up.

		$this->_last_response = '';
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Runs the merge action.
	 *
	 * @param int    $source_id       The source term ID.
	 * @param string $source_taxonomy The source taxonomy.
	 * @param int    $target_id       The target term ID.
	 * @param string $target_taxonomy The target taxonomy.
	 *
	 * @return array The decoded response.
	 */
	private function merge( $source_id, $source_taxonomy, $target_id, $target_taxonomy ) {
		return $this->do_ajax(
			'fewer_tags_merge_terms',
			[
				'source_id'       => (string) $source_id,
				'source_taxonomy' => $source_taxonomy,
				'target_id'       => (string) $target_id,
				'target_taxonomy' => $target_taxonomy,
			]
		);
	}

	/**
	 * Stores a deleted term we can redirect, and returns its slug.
	 *
	 * @return string The slug.
	 */
	private function store_term_to_redirect() {
		Option::get_instance()->set(
			'terms_to_redirect',
			[
				'post_tag' => [
					'gone' => [
						'name'      => 'Gone',
						'permalink' => home_url( '/tag/gone/' ),
					],
				],
			]
		);
		return 'gone';
	}

	/**
	 * Runs the redirect action for the stored term.
	 *
	 * @param string $target The redirect target.
	 *
	 * @return array The decoded response.
	 */
	private function redirect( $target ) {
		return $this->do_ajax(
			'fewer_tags_redirect_url',
			[
				'slug'     => $this->store_term_to_redirect(),
				'taxonomy' => 'post_tag',
				'target'   => $target,
			]
		);
	}

	/**
	 * Editors can merge tags, which moves the posts and deletes the source.
	 */
	public function test_merge_terms() {
		$source  = self::factory()->tag->create();
		$target  = self::factory()->tag->create();
		$post_id = self::factory()->post->create();
		wp_set_object_terms( $post_id, [ $source ], 'post_tag' );

		$response = $this->merge( $source, 'post_tag', $target, 'post_tag' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $response['data']['target_count'] );
		$this->assertNull( get_term( $source, 'post_tag' ) );
		$this->assertTrue( has_term( $target, 'post_tag', $post_id ) );
	}

	/**
	 * Taxonomies without a public admin UI, such as nav_menu and post_format, cannot be merged.
	 *
	 * @dataProvider data_unsupported_taxonomies
	 *
	 * @param string $taxonomy The taxonomy to test.
	 */
	public function test_merge_rejects_unsupported_source_taxonomy( $taxonomy ) {
		$source = self::factory()->term->create( [ 'taxonomy' => $taxonomy ] );
		$target = self::factory()->tag->create();

		$response = $this->merge( $source, $taxonomy, $target, 'post_tag' );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $source, $taxonomy ) );

		// And not as a target either.
		$response = $this->merge( $target, 'post_tag', $source, $taxonomy );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $target, 'post_tag' ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_unsupported_taxonomies() {
		return [
			[ 'nav_menu' ],
			[ 'post_format' ],
		];
	}

	/**
	 * Taxonomies that don't exist are rejected.
	 */
	public function test_merge_rejects_unknown_taxonomy() {
		$source = self::factory()->tag->create();
		$target = self::factory()->tag->create();

		$response = $this->merge( $source, 'does_not_exist', $target, 'post_tag' );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $source, 'post_tag' ) );
	}

	/**
	 * The capabilities of the taxonomy itself are checked.
	 */
	public function test_merge_checks_taxonomy_capabilities() {
		register_taxonomy(
			'restricted',
			'post',
			[
				'public'       => true,
				'show_ui'      => true,
				'capabilities' => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				],
			]
		);
		$source = self::factory()->term->create( [ 'taxonomy' => 'restricted' ] );
		$target = self::factory()->tag->create();

		$response = $this->merge( $source, 'restricted', $target, 'post_tag' );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $source, 'restricted' ) );

		$this->_setRole( 'administrator' );
		$response = $this->merge( $source, 'restricted', $target, 'post_tag' );

		$this->assertTrue( $response['success'] );
		$this->assertNull( get_term( $source, 'restricted' ) );

		unregister_taxonomy( 'restricted' );
	}

	/**
	 * Merging a term into itself must not delete it.
	 */
	public function test_merge_rejects_self_merge() {
		$term = self::factory()->tag->create();

		$response = $this->merge( $term, 'post_tag', $term, 'post_tag' );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $term, 'post_tag' ) );
	}

	/**
	 * Missing terms give an error instead of a fatal.
	 */
	public function test_merge_rejects_missing_terms() {
		$term = self::factory()->tag->create();

		$response = $this->merge( $term, 'post_tag', 999999, 'post_tag' );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $term, 'post_tag' ) );
	}

	/**
	 * Terms cannot move to a taxonomy that is used for other content types.
	 */
	public function test_merge_rejects_taxonomies_without_shared_object_type() {
		register_taxonomy(
			'page_topic',
			'page',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);
		$source = self::factory()->tag->create();
		$target = self::factory()->term->create( [ 'taxonomy' => 'page_topic' ] );

		$response = $this->merge( $source, 'post_tag', $target, 'page_topic' );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $source, 'post_tag' ) );

		unregister_taxonomy( 'page_topic' );
	}

	/**
	 * A partially compatible taxonomy must not lose assignments or change any posts.
	 */
	public function test_merge_rejects_incompatible_objects_before_changing_terms() {
		register_taxonomy( 'shared_topic', [ 'post', 'page' ], [ 'public' => true ] );
		$source  = self::factory()->term->create( [ 'taxonomy' => 'shared_topic' ] );
		$target  = self::factory()->tag->create();
		$post_id = self::factory()->post->create();
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_set_object_terms( $post_id, [ $source ], 'shared_topic' );
		wp_set_object_terms( $page_id, [ $source ], 'shared_topic' );

		try {
			$response = $this->merge( $source, 'shared_topic', $target, 'post_tag' );

			$this->assertFalse( $response['success'] );
			$this->assertInstanceOf( \WP_Term::class, get_term( $source, 'shared_topic' ) );
			$this->assertTrue( has_term( $source, 'shared_topic', $post_id ) );
			$this->assertTrue( has_term( $source, 'shared_topic', $page_id ) );
			$this->assertFalse( has_term( $target, 'post_tag', $post_id ) );
			$this->assertFalse( has_term( $target, 'post_tag', $page_id ) );
		} finally {
			unregister_taxonomy( 'shared_topic' );
		}
	}

	/**
	 * Partially overlapping taxonomies can merge when every actual object is compatible.
	 */
	public function test_merge_allows_compatible_objects_in_overlapping_taxonomies() {
		register_taxonomy( 'shared_topic', [ 'post', 'page' ], [ 'public' => true ] );
		$source  = self::factory()->term->create( [ 'taxonomy' => 'shared_topic' ] );
		$target  = self::factory()->tag->create();
		$post_id = self::factory()->post->create();
		wp_set_object_terms( $post_id, [ $source ], 'shared_topic' );

		try {
			$response = $this->merge( $source, 'shared_topic', $target, 'post_tag' );

			$this->assertTrue( $response['success'] );
			$this->assertNull( get_term( $source, 'shared_topic' ) );
			$this->assertTrue( has_term( $target, 'post_tag', $post_id ) );
		} finally {
			unregister_taxonomy( 'shared_topic' );
		}
	}

	/**
	 * Editors cannot redirect to another site.
	 *
	 * @dataProvider data_external_targets
	 *
	 * @param string $target The redirect target.
	 */
	public function test_redirect_rejects_external_target_for_editor( $target ) {
		$response = $this->redirect( $target );

		$this->assertFalse( $response['success'] );
		$this->assertStringNotContainsString( 'Redirection plugin', $response['data']['msg'] );
		$this->assertNotNull( Helper::get_term_to_redirect( 'post_tag', 'gone' ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_external_targets() {
		return [
			[ 'https://evil.example/' ],
			[ '//evil.example/' ],
			[ 'javascript:alert(1)' ],
			[ 'evil.example' ],
			[ '' ],
		];
	}

	/**
	 * Whatever sanitizing does to a path, the result never points to another site.
	 *
	 * @dataProvider data_tricky_paths
	 *
	 * @param string $target The redirect target.
	 */
	public function test_sanitized_path_never_points_to_another_site( $target ) {
		$method = new \ReflectionMethod( Admin_Ajax::class, 'sanitize_redirect_target' );
		$method->setAccessible( true );

		$result = $method->invoke( new Admin_Ajax(), $target );

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'fewer_tags_invalid_target', $result->get_error_code() );
			return;
		}
		$this->assertMatchesRegularExpression( '#^/(?![/\\\\])#', $result );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_tricky_paths() {
		return [
			[ '//evil.example/' ],
			[ '/\\evil.example/' ],
			[ '/^/evil.example/' ],
			[ "/\t/evil.example/" ],
			[ "/\n/evil.example/" ],
			[ '/%0a/evil.example/' ],
			[ '/%00/evil.example/' ],
			[ '/%0d%0a\\evil.example/' ],
			[ '/^\\evil.example/' ],
			[ '/ /evil.example/' ],
			[ '/<b>/evil.example/' ],
		];
	}

	/**
	 * Targets on this site pass validation. Without a redirect tool, we then get the "no tool" error.
	 *
	 * @dataProvider data_internal_targets
	 *
	 * @param string $target The redirect target.
	 */
	public function test_redirect_accepts_internal_target( $target ) {
		$response = $this->redirect( str_replace( '{home}', home_url(), $target ) );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Redirection plugin', $response['data']['msg'] );
		$this->assertSame( 'gone', $response['data']['slug'] );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_internal_targets() {
		return [
			[ '/' ],
			[ '/tag/other/' ],
			[ '{home}/tag/other/' ],
		];
	}

	/**
	 * Administrators may redirect to another site.
	 */
	public function test_redirect_accepts_external_target_for_administrator() {
		$this->_setRole( 'administrator' );

		$response = $this->redirect( 'https://other.example/' );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Redirection plugin', $response['data']['msg'] );
	}

	/**
	 * An unknown term gives an error instead of PHP warnings.
	 */
	public function test_redirect_rejects_unknown_term() {
		$response = $this->do_ajax(
			'fewer_tags_redirect_url',
			[
				'slug'     => 'unknown',
				'taxonomy' => 'post_tag',
				'target'   => '/',
			]
		);

		$this->assertFalse( $response['success'] );
	}

	/**
	 * Deleting a term stores plain data, and the notice goes to the user who deleted it.
	 */
	public function test_deleted_term_is_stored_as_plain_data_per_user() {
		$term = self::factory()->tag->create(
			[
				'name' => 'Deleted <b>tag</b>',
				'slug' => 'deleted-tag',
			]
		);
		wp_delete_term( $term, 'post_tag' );

		$stored = Option::get_instance()->get( 'terms_to_redirect' );
		$this->assertSame( [ 'name', 'permalink' ], array_keys( $stored['post_tag']['deleted-tag'] ) );
		$this->assertNull( Option::get_instance()->get( 'just_deleted_term' ) );

		// Another user does not get the notice.
		$deleter = get_current_user_id();
		$this->_setRole( 'editor' );
		$response = $this->do_ajax( 'fewer_tags_get_just_deleted_term', [] );
		$this->assertFalse( $response['success'] );

		wp_set_current_user( $deleter );
		$response = $this->do_ajax( 'fewer_tags_get_just_deleted_term', [] );
		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'fewer-tags-redirect-deleted-tag', $response['data'] );
		$this->assertStringNotContainsString( '<b>tag</b>', $response['data'] );
	}

	/**
	 * Deleted terms in taxonomies we don't support are not recorded.
	 */
	public function test_deleted_term_in_unsupported_taxonomy_is_not_stored() {
		$term = self::factory()->term->create( [ 'taxonomy' => 'nav_menu' ] );
		wp_delete_term( $term, 'nav_menu' );

		$this->assertSame( [], Option::get_instance()->get( 'terms_to_redirect' ) );
	}

	/**
	 * The redirect links carry no inline JavaScript, and a slug cannot break out of its attribute.
	 */
	public function test_redirect_notice_has_no_inline_javascript() {
		if ( ! defined( 'REDIRECTION_DB_VERSION' ) ) {
			define( 'REDIRECTION_DB_VERSION', '1' );
		}
		$slug = '%27-alert%281%29-%27"onmouseover="alert(1)';
		Option::get_instance()->set(
			'terms_to_redirect',
			[
				'post_tag' => [
					$slug => [
						'name'      => 'Tag',
						'permalink' => home_url( '/tag/x/' ),
					],
				],
			]
		);

		$notice = Helper::redirect_term_notice( $slug, 'Tag', 'post_tag' );

		$this->assertStringContainsString( 'fewer-tags-redirect-action', $notice );
		$this->assertStringNotContainsString( 'javascript:', $notice );
		$this->assertStringNotContainsString( '"onmouseover="', $notice );
		$this->assertStringContainsString( 'data-slug="%27-alert%281%29-%27&quot;onmouseover=&quot;alert(1)"', $notice );
	}

	/**
	 * Entries stored by older versions, with a WP_Term object, can still be read.
	 */
	public function test_legacy_term_object_entries_are_read() {
		$term = get_term( self::factory()->tag->create( [ 'name' => 'Legacy' ] ), 'post_tag' );
		Option::get_instance()->set(
			'terms_to_redirect',
			[
				'post_tag' => [
					$term->slug => [
						'object'    => $term,
						'permalink' => home_url( '/tag/legacy/' ),
					],
				],
			]
		);

		$stored = Helper::get_term_to_redirect( 'post_tag', $term->slug );

		$this->assertSame( 'Legacy', $stored['name'] );
		$this->assertSame( home_url( '/tag/legacy/' ), $stored['permalink'] );
	}
}
