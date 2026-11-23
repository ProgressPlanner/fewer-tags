<?php
/**
 * Class Admin_Test
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

use FewerTags\Plugin;
use FewerTags\Admin;

/**
 * Sample test case.
 */
class Admin_Test extends \WP_UnitTestCase {

	/**
	 * Instance of the class being tested.
	 *
	 * @var FewerTags\Admin
	 */
	private static $class_instance;

	/**
	 * Live tag.
	 *
	 * @var array
	 */
	private static $live_tag;

	/**
	 * Not live tag.
	 *
	 * @var array
	 */
	private static $not_live_tag;

	/**
	 * Set up the class instance to be tested.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$class_instance = new Admin();

		Plugin::$min_posts_count = 5;

		self::$live_tag     = wp_insert_term( 'Live tag', 'post_tag' );
		self::$not_live_tag = wp_insert_term( 'Not alive', 'post_tag' );

		$i = 0;
		while ( $i < 11 ) {
			wp_insert_post(
				[
					'post_title'  => 'Post ' . $i,
					'post_status' => 'publish',
					'tags_input'  => 'Live tag',
				]
			);
			++$i;
		}
	}

	/**
	 * Tests hooks registration.
	 *
	 * @covers FewerTags\Admin::register_hooks
	 */
	public function test_register_hooks() {
		self::$class_instance->register_hooks();

		$this->assertSame( 10, has_action( 'admin_init', [ self::$class_instance, 'register_settings' ] ) );
		$this->assertSame( 10, has_action( 'manage_edit-post_tag_columns', [ self::$class_instance, 'add_tag_columns' ] ) );
		$this->assertSame( 10, has_action( 'manage_post_tag_custom_column', [ self::$class_instance, 'manage_tag_columns' ] ), 10, 3 );
		$this->assertSame( 10, has_action( 'post_tag_row_actions', [ self::$class_instance, 'remove_view_action' ] ), 10, 2 );
	}

	/**
	 * Tests settings registration.
	 *
	 * @covers FewerTags\Admin::register_settings
	 */
	public function test_register_settings() {
		self::$class_instance->register_settings();

		global $wp_settings_sections, $wp_settings_fields;

		$this->assertArrayHasKey( 'fewer_tags_section', $wp_settings_sections['reading'] );
		$this->assertArrayHasKey( 'fewer_tags_min_posts_count', $wp_settings_fields['reading']['fewer_tags_section'] );
	}

	/**
	 * Tests that the setting is sanitized through register_setting().
	 *
	 * @covers FewerTags\Admin::sanitize_setting
	 */
	public function test_sanitize_setting() {
		$redirects = [
			'post_tag' => [
				'old-tag' => [
					'name'      => 'Old tag',
					'permalink' => home_url( '/tag/old-tag/' ),
				],
			],
		];
		update_option(
			'fewer_tags',
			[
				'min_posts_count'   => 10,
				'terms_to_redirect' => $redirects,
			]
		);
		self::$class_instance->register_settings();
		foreach ( [
			'5'   => 5,
			'0'   => 0,
			''    => 0,
			'-5'  => 0,
			'abc' => 0,
		] as $value => $expected ) {
			$sanitized = sanitize_option( 'fewer_tags', [ 'min_posts_count' => (string) $value ] );
			$this->assertSame( $expected, $sanitized['min_posts_count'] );
			$this->assertSame( $redirects, $sanitized['terms_to_redirect'] );
		}
	}

	/**
	 * Full-array writes from Option::set() keep their new keys, and a write
	 * without min_posts_count keeps the stored value instead of resetting it.
	 *
	 * @covers FewerTags\Admin::sanitize_setting
	 */
	public function test_sanitize_setting_keeps_full_array_writes() {
		update_option(
			'fewer_tags',
			[
				'min_posts_count'   => 3,
				'terms_to_redirect' => [],
			]
		);
		self::$class_instance->register_settings();

		$redirects = [
			'post_tag' => [
				'new-tag' => [
					'name'      => 'New tag',
					'permalink' => home_url( '/tag/new-tag/' ),
				],
			],
		];
		update_option(
			'fewer_tags',
			[
				'min_posts_count'   => 3,
				'terms_to_redirect' => $redirects,
			]
		);
		$saved = get_option( 'fewer_tags' );
		$this->assertSame( $redirects, $saved['terms_to_redirect'] );
		$this->assertSame( 3, $saved['min_posts_count'] );

		update_option( 'fewer_tags', [ 'redirection_group' => 7 ] );
		$saved = get_option( 'fewer_tags' );
		$this->assertSame( 3, $saved['min_posts_count'] );
		$this->assertSame( 7, $saved['redirection_group'] );
		$this->assertSame( $redirects, $saved['terms_to_redirect'] );
	}

	/**
	 * Tests display section.
	 *
	 * @covers FewerTags\Admin::display_section
	 */
	public function test_display_section() {
		ob_start();
		self::$class_instance->display_section();
		$output = ob_get_clean();

		$this->assertSame( 'Set the minimum number of posts a tag should have to become live on the site and not be redirected to the homepage.', $output );
	}

	/**
	 * Tests display setting.
	 *
	 * @covers FewerTags\Admin::display_setting
	 */
	public function test_display_setting() {
		ob_start();
		self::$class_instance->display_setting();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="fewer_tags[min_posts_count]"', $output );
		$this->assertStringContainsString( 'id="fewer_tags_min_posts_count"', $output );
		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'min="0"', $output );
		$this->assertStringContainsString( 'value="5"', $output ); // This is tied to the value of Plugin::$min_posts_count.
		$this->assertStringContainsString( 'class="small-text"', $output );
		$this->assertStringContainsString( 'posts before being live on the site.', $output );
	}

	/**
	 * Tests add tag columns.
	 *
	 * @covers FewerTags\Admin::add_tag_columns
	 */
	public function test_add_tag_columns() {
		$columns = self::$class_instance->add_tag_columns( [] );

		$this->assertArrayHasKey( 'active', $columns );
		$this->assertSame( 'Live on site', $columns['active'] );
	}

	/**
	 * Tests manage tag columns.
	 *
	 * @covers FewerTags\Admin::manage_tag_columns
	 */
	public function test_manage_tag_columns() {
		$out = self::$class_instance->manage_tag_columns( '', 'active', self::$not_live_tag['term_id'] );
		$this->assertMatchesRegularExpression( '/Not live/', $out );

		$out = self::$class_instance->manage_tag_columns( '', 'active', self::$live_tag['term_id'] );
		$this->assertSame( 'Live', $out );
	}

	/**
	 * Tests remove view action.
	 *
	 * @covers FewerTags\Admin::remove_view_action
	 */
	public function test_remove_view_action() {
		$actions = self::$class_instance->remove_view_action( $this->get_term_row_actions(), get_term( self::$not_live_tag['term_id'] ) );
		$this->assertArrayNotHasKey( 'view', $actions );

		$actions = self::$class_instance->remove_view_action( $this->get_term_row_actions(), get_term( self::$live_tag['term_id'] ) );
		$this->assertArrayHasKey( 'view', $actions );
	}

	/**
	 * Returns the term row actions.
	 *
	 * @return array
	 */
	private function get_term_row_actions() {
		return [
			'edit'   => 'Edit',
			'delete' => 'Delete',
			'view'   => 'View',
		];
	}

	/**
	 * Render the redirect tool notice for a user with the given role.
	 *
	 * @param string $role The user's role.
	 *
	 * @return string The notice HTML.
	 */
	private function render_redirect_tool_notice_for( $role ) {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		if ( 'administrator' === $role && is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post_tag' );

		ob_start();
		( new Admin() )->redirect_tool_notice();
		$output = ob_get_clean();

		set_current_screen( 'front' );
		return $output;
	}

	/**
	 * Administrators see the notice with the install button.
	 *
	 * Runs in a separate process: other tests define REDIRECTION_DB_VERSION, which hides the notice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @covers FewerTags\Admin::redirect_tool_notice
	 */
	public function test_redirect_tool_notice_for_administrator() {
		$output = $this->render_redirect_tool_notice_for( 'administrator' );

		$this->assertStringContainsString( 'fewer-tags-redirect-tool-notice', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'Install Redirection', $output );
	}

	/**
	 * Users who can't install or activate plugins don't see the notice.
	 *
	 * Runs in a separate process: other tests define REDIRECTION_DB_VERSION, which hides the notice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @covers FewerTags\Admin::redirect_tool_notice
	 */
	public function test_redirect_tool_notice_hidden_for_editor() {
		$this->assertSame( '', $this->render_redirect_tool_notice_for( 'editor' ) );
	}

	/**
	 * Without install_plugins, the Install button is left out but the notice remains.
	 *
	 * Runs in a separate process: other tests define REDIRECTION_DB_VERSION, which hides the notice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @covers FewerTags\Admin::redirect_tool_notice
	 */
	public function test_redirect_tool_notice_without_install_capability() {
		// map_meta_cap also applies to super admins on multisite, unlike user_has_cap.
		$deny_install = static function ( $caps, $cap ) {
			return 'install_plugins' === $cap ? [ 'do_not_allow' ] : $caps;
		};
		add_filter( 'map_meta_cap', $deny_install, 10, 2 );
		$output = $this->render_redirect_tool_notice_for( 'administrator' );
		remove_filter( 'map_meta_cap', $deny_install, 10 );

		$this->assertStringContainsString( 'fewer-tags-redirect-tool-notice', $output );
		$this->assertStringNotContainsString( 'Install Redirection', $output );
		$this->assertStringContainsString( 'Get Yoast SEO Premium', $output );
	}

	/**
	 * A dismissed notice stays hidden for that user.
	 *
	 * Runs in a separate process: other tests define REDIRECTION_DB_VERSION, which hides the notice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @covers FewerTags\Admin::redirect_tool_notice
	 */
	public function test_redirect_tool_notice_hidden_after_dismissal() {
		$dismiss = static function ( $value, $object_id, $meta_key ) {
			return Admin::REDIRECT_TOOL_NOTICE_DISMISSED_META_KEY === $meta_key ? [ 1 ] : $value;
		};
		add_filter( 'get_user_metadata', $dismiss, 10, 3 );
		$output = $this->render_redirect_tool_notice_for( 'administrator' );
		remove_filter( 'get_user_metadata', $dismiss, 10 );

		$this->assertSame( '', $output );
	}
}
