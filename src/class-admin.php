<?php
/**
 * Handles all admin functions.
 *
 * @package FewerTags
 */

namespace FewerTags;

/**
 * FewerTags Admin Class
 */
class Admin {

	/**
	 * User meta key storing that the user dismissed the "no redirect plugin" notice.
	 *
	 * @var string
	 */
	const REDIRECT_TOOL_NOTICE_DISMISSED_META_KEY = 'fewer_tags_redirect_tool_notice_dismissed';

	/**
	 * Register the needed hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_notices', [ $this, 'redirect_tool_notice' ] );
		$this->register_list_table_hooks();
	}

	/**
	 * Register the tag list table hooks.
	 *
	 * These also run on AJAX requests, because WordPress renders rows through
	 * admin-ajax.php when a tag is added or quick-edited.
	 *
	 * @return void
	 */
	public function register_list_table_hooks() {
		\add_filter( 'manage_edit-post_tag_columns', [ $this, 'add_tag_columns' ] );
		\add_filter( 'manage_post_tag_custom_column', [ $this, 'manage_tag_columns' ], 10, 3 );
		\add_filter( 'post_tag_row_actions', [ $this, 'remove_view_action' ], 10, 2 );
	}

	/**
	 * Register settings and add settings field to the Reading settings page.
	 *
	 * @return void
	 */
	public function register_settings() {
		\add_settings_section(
			'fewer_tags_section',
			__( 'Fewer Tags settings', 'fewer-tags' ),
			[ $this, 'display_section' ],
			'reading'
		);

		\add_settings_field(
			'fewer_tags_min_posts_count',
			__( 'Tags need to have', 'fewer-tags' ),
			[ $this, 'display_setting' ],
			'reading',
			'fewer_tags_section'
		);

		\register_setting(
			'reading',
			'fewer_tags',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_setting' ],
			]
		);
	}

	/**
	 * Display the section text.
	 *
	 * @return void
	 */
	public function display_section() {
		\esc_html_e( 'Set the minimum number of posts a tag should have to become live on the site and not be redirected to the homepage.', 'fewer-tags' );
	}

	/**
	 * Display the setting field in the Reading settings page.
	 *
	 * @return void
	 */
	public function display_setting() {
		?>
		<input
			name="fewer_tags[min_posts_count]"
			id="fewer_tags_min_posts_count"
			type="number"
			min="0"
			value="<?php echo (int) Plugin::$min_posts_count; ?>"
			class="small-text"
		/>
		<?php \esc_html_e( 'posts before being live on the site.', 'fewer-tags' ); ?>
		<?php
	}

	/**
	 * Sanitize the setting value.
	 *
	 * The sanitize_option_fewer_tags filter runs on every update_option() call,
	 * not just the Reading settings form. The form only submits min_posts_count,
	 * while the plugin itself saves the full array (terms_to_redirect,
	 * redirection_group) through Option::set(). So merge the input over the
	 * stored value: submitted keys win, keys that aren't submitted are kept.
	 *
	 * @param mixed $input The input value from the form or update_option().
	 *
	 * @return array The sanitized option array.
	 */
	public function sanitize_setting( $input ) {
		$current = \get_option( 'fewer_tags', [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}
		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$sanitized = array_merge( $current, $input );

		// Submitted value, else stored value, else the default. Zero and negative mean "off".
		$sanitized['min_posts_count'] = max( 0, (int) ( $sanitized['min_posts_count'] ?? 10 ) );

		return $sanitized;
	}

	/**
	 * Adds a new column to the tag list table to show whether a tag is active or inactive.
	 *
	 * @param array $columns The existing array of columns.
	 *
	 * @return array The modified array of columns.
	 */
	public function add_tag_columns( $columns ) {
		$columns['active'] = __( 'Live on site', 'fewer-tags' );
		return $columns;
	}

	/**
	 * Manages the output for the custom column in the tag list table.
	 *
	 * @param string $out         The output for the custom column (this will be empty initially).
	 * @param string $column_name The name of the custom column.
	 * @param int    $tag_ID      The ID of the tag being displayed.
	 *
	 * @return string The output for the custom column.
	 */
	public function manage_tag_columns( $out, $column_name, $tag_ID ) {
		if ( $column_name === 'active' ) {
			$term = \get_term( $tag_ID );
			$out  = \esc_html__( 'Live', 'fewer-tags' );
			if ( $term->count < \FewerTags\Plugin::$min_posts_count ) {
				$out = '<span title="' . \esc_html__( 'Not live due to not enough posts being in this tag.', 'fewer-tags' ) . '">' . \esc_html__( 'Not live', 'fewer-tags' ) . '</span>';
			}
		}

		return $out;
	}

	/**
	 * Removes the "View" action link for tags that have fewer than the minimum number of posts.
	 *
	 * @param array    $actions An array of action links.
	 * @param \WP_Term $tag     Current WP_Term object.
	 *
	 * @return array Modified array of action links.
	 */
	public function remove_view_action( $actions, $tag ) {
		if ( $tag->count < \FewerTags\Plugin::$min_posts_count ) {
			unset( $actions['view'] );
		}

		return $actions;
	}

	/**
	 * Display a notice if no redirect tool is available.
	 *
	 * @return void
	 */
	public function redirect_tool_notice() {
		$screen = \get_current_screen();
		if ( ! \is_object( $screen ) || $screen->base !== 'edit-tags' ) {
			return;
		}

		if ( Helper::determine_redirect_tool() !== false ) {
			return;
		}

		// Only show the notice to users who can act on it, and only until they dismiss it.
		$can_install  = \current_user_can( 'install_plugins' );
		$can_activate = \current_user_can( 'activate_plugins' );
		if ( ( ! $can_install && ! $can_activate ) || \get_user_meta( \get_current_user_id(), self::REDIRECT_TOOL_NOTICE_DISMISSED_META_KEY, true ) ) {
			return;
		}

		// If the Redirection plugin is not installed, link to installing it; if it is, link to activating it.
		$is_redirection_plugin_installed = isset( \get_plugins()['redirection/redirection.php'] );
		$redirection_button_url          = '';
		if ( $is_redirection_plugin_installed && $can_activate ) {
			$redirection_button_url = \wp_nonce_url(
				\add_query_arg(
					[
						'action' => 'activate',
						'plugin' => 'redirection/redirection.php',
					],
					\admin_url( 'plugins.php' )
				),
				'activate-plugin_redirection/redirection.php'
			);
		} elseif ( ! $is_redirection_plugin_installed && $can_install ) {
			$redirection_button_url = \wp_nonce_url(
				\add_query_arg(
					[
						'action' => 'install-plugin',
						'plugin' => 'redirection',
					],
					\admin_url( 'update.php' )
				),
				'install-plugin_redirection'
			);
		}
		?>
		<div class="notice notice-warning is-dismissible fewer-tags-redirect-tool-notice" data-nonce="<?php echo \esc_attr( \wp_create_nonce( 'fewer_tags_dismiss_redirect_tool_notice' ) ); ?>">
			<p>
				<strong><?php \esc_html_e( 'Warning:', 'fewer-tags' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to Redirection plugin */
					\esc_html__( 'Fewer Tags can create redirects when you merge or delete tags, categories and other terms, but that requires either the %s plugin by John Godley or the Yoast SEO Premium plugin to be installed and activated.', 'fewer-tags' ),
					'<a href="https://wordpress.org/plugins/redirection/" target="_blank" rel="noopener noreferrer">Redirection</a>'
				);
				?>
			</p>
			<p>
				<?php if ( $redirection_button_url ) : ?>
				<a class="button" style="margin: 0 10px 0 0;" href="<?php echo \esc_url( $redirection_button_url ); ?>">
					<?php
					echo $is_redirection_plugin_installed
						? \esc_html__( 'Activate Redirection', 'fewer-tags' )
						: \esc_html__( 'Install Redirection', 'fewer-tags' );
					?>
				</a>
				<?php endif; ?>
				<a href="https://yoast.com/wordpress/plugins/seo/" target="_blank" rel="noopener noreferrer"><?php \esc_html_e( 'Get Yoast SEO Premium', 'fewer-tags' ); ?></a>
			</p>
		</div>
		<?php
	}
}
