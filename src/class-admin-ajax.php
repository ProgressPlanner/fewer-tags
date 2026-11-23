<?php
/**
 * AJAX handler class for merge and redirect operations.
 *
 * @package FewerTags
 */

namespace FewerTags;

/**
 * FewerTags Admin Ajax Class
 */
class Admin_Ajax {
	/**
	 * Holds the plugin's options class.
	 *
	 * @var Option
	 */
	public $options;

	/**
	 * Constructor method
	 */
	public function __construct() {
		$this->options = Option::get_instance();

		// Capture both individual AJAX deletions and normal admin bulk deletions.
		\add_action( 'pre_delete_term', [ $this, 'pre_delete_term' ], 10, 2 );
		\add_action( 'delete_term', [ $this, 'redirect_term_on_deletion' ], 10, 4 );

		// Our AJAX functions.
		\add_action( 'wp_ajax_fewer_tags_redirect_url', [ $this, 'redirect_url_action' ] );
		\add_action( 'wp_ajax_fewer_tags_merge_terms', [ $this, 'merge_terms' ] );
		\add_action( 'wp_ajax_fewer_tags_get_just_deleted_term', [ $this, 'get_just_deleted_term' ] );
		\add_action( 'wp_ajax_fewer_tags_dismiss_notice', [ $this, 'dismiss_notice' ] );
		\add_action( 'wp_ajax_fewer_tags_dismiss_redirect_tool_notice', [ $this, 'dismiss_redirect_tool_notice' ] );
	}

	/**
	 * The maximum number of deleted terms we keep redirect offers for, per taxonomy.
	 */
	const MAX_TERMS_TO_REDIRECT = 50;

	/**
	 * The user meta key that holds the term a user just deleted.
	 */
	const JUST_DELETED_META_KEY = 'fewer_tags_just_deleted_term';

	/**
	 * Permalinks of terms that are being deleted in this request, keyed by term ID.
	 *
	 * @var array<int, string>
	 */
	private $deleted_term_permalinks = [];

	/**
	 * Stores an option to show a nag to redirect a tag when it's deleted.
	 *
	 * @param int      $term         The term ID.
	 * @param int      $tt_id        The term-taxonomy ID.
	 * @param string   $taxonomy     The taxonomy of the deleted term.
	 * @param \WP_Term $deleted_term The deleted term.
	 *
	 * @return void
	 */
	public function redirect_term_on_deletion( $term, $tt_id, $taxonomy, $deleted_term ) {
		// Use the permalink captured in pre_delete_term, since the term is already deleted at this point.
		if ( ! isset( $this->deleted_term_permalinks[ (int) $term ] ) ) {
			return;
		}
		$permalink = $this->deleted_term_permalinks[ (int) $term ];
		unset( $this->deleted_term_permalinks[ (int) $term ] );

		$terms_to_redirect = $this->options->get( 'terms_to_redirect' );
		if ( ! is_array( $terms_to_redirect ) ) {
			$terms_to_redirect = [];
		}
		if ( ! isset( $terms_to_redirect[ $taxonomy ] ) || ! is_array( $terms_to_redirect[ $taxonomy ] ) ) {
			$terms_to_redirect[ $taxonomy ] = [];
		}

		// Store plain data only, and re-add the entry so the most recent deletion is last.
		unset( $terms_to_redirect[ $taxonomy ][ $deleted_term->slug ] );
		$terms_to_redirect[ $taxonomy ][ $deleted_term->slug ] = [
			'name'      => $deleted_term->name,
			'permalink' => $permalink,
		];

		// Don't let the list grow without bounds: drop the oldest entries.
		if ( count( $terms_to_redirect[ $taxonomy ] ) > self::MAX_TERMS_TO_REDIRECT ) {
			$terms_to_redirect[ $taxonomy ] = array_slice( $terms_to_redirect[ $taxonomy ], -self::MAX_TERMS_TO_REDIRECT, null, true );
		}

		$this->options->set( 'terms_to_redirect', $terms_to_redirect );

		$user_id = \get_current_user_id();
		if ( $user_id ) {
			\update_user_meta(
				$user_id,
				self::JUST_DELETED_META_KEY,
				[
					'taxonomy' => $taxonomy,
					'slug'     => $deleted_term->slug,
				]
			);
		}
	}

	/**
	 * Before deleting a term, remember its permalink so we can offer to redirect it.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy name.
	 *
	 * @return void
	 */
	public function pre_delete_term( $term_id, $taxonomy ) {
		// Only terms in public taxonomies with an admin UI have an archive worth redirecting.
		if ( ! Helper::get_supported_taxonomy( $taxonomy ) ) {
			return;
		}

		$permalink = \get_term_link( (int) $term_id, $taxonomy );
		if ( \is_wp_error( $permalink ) ) {
			return;
		}

		$this->deleted_term_permalinks[ (int) $term_id ] = $permalink;
	}

	/**
	 * Retrieve a just deleted term.
	 *
	 * @return void
	 */
	public function get_just_deleted_term() {
		\check_ajax_referer( 'fewer_tags_just_deleted_term' );

		if ( ! \current_user_can( 'manage_categories' ) ) {
			\wp_send_json_error( [ 'msg' => __( 'You do not have permission to do this.', 'fewer-tags' ) ] );
		}

		// The marker is stored per user, so users only ever get the notice for their own deletion.
		$user_id      = \get_current_user_id();
		$just_deleted = \get_user_meta( $user_id, self::JUST_DELETED_META_KEY, true );
		\delete_user_meta( $user_id, self::JUST_DELETED_META_KEY );

		if ( ! is_array( $just_deleted ) || ! isset( $just_deleted['taxonomy'], $just_deleted['slug'] ) || ! is_string( $just_deleted['taxonomy'] ) || ! is_string( $just_deleted['slug'] ) ) {
			\wp_send_json_error( [ 'msg' => __( 'No deleted term found.', 'fewer-tags' ) ] );
		}

		$term = Helper::get_term_to_redirect( $just_deleted['taxonomy'], $just_deleted['slug'] );
		if ( null === $term || ! $this->current_user_can_manage_taxonomy( $term['taxonomy'] ) ) {
			\wp_send_json_error( [ 'msg' => __( 'No deleted term found.', 'fewer-tags' ) ] );
		}

		$msg = Helper::redirect_term_notice( $term['slug'], $term['name'], $term['taxonomy'] );

		\wp_send_json_success( $msg );
	}

	/**
	 * Dismiss a notice.
	 *
	 * @return void
	 */
	public function dismiss_notice() {
		\check_ajax_referer( 'fewer_tags_dismiss_notice' );

		if ( ! \current_user_can( 'manage_categories' ) ) {
			\wp_send_json_error( [ 'msg' => __( 'You do not have permission to do this.', 'fewer-tags' ) ] );
		}

		if ( ! isset( $_POST['id'] ) || ! isset( $_POST['taxonomy'] ) || ! is_string( $_POST['id'] ) || ! is_string( $_POST['taxonomy'] ) ) {
			\wp_send_json_error( [ 'msg' => __( 'Invalid data.', 'fewer-tags' ) ] );
		}

		$slug     = \str_replace( 'fewer-tags-dismiss-', '', trim( \wp_strip_all_tags( \wp_unslash( $_POST['id'] ) ) ) );
		$taxonomy = \sanitize_key( \wp_unslash( $_POST['taxonomy'] ) );

		if ( ! $this->current_user_can_manage_taxonomy( $taxonomy ) ) {
			\wp_send_json_error( [ 'msg' => __( 'You do not have permission to do this.', 'fewer-tags' ) ] );
		}

		$this->remove_term_from_terms_to_redirect( $slug, $taxonomy );

		\wp_send_json_success( $slug );
	}

	/**
	 * Dismiss the "no redirect plugin" notice for the current user.
	 *
	 * @return void
	 */
	public function dismiss_redirect_tool_notice() {
		\check_ajax_referer( 'fewer_tags_dismiss_redirect_tool_notice' );

		\update_user_meta( \get_current_user_id(), Admin::REDIRECT_TOOL_NOTICE_DISMISSED_META_KEY, 1 );

		\wp_send_json_success();
	}

	/**
	 * Create a redirect.
	 *
	 * @return void
	 */
	public function redirect_url_action() {
		\check_ajax_referer( 'fewer_tags_redirect_url' );

		if ( ! \current_user_can( 'manage_categories' ) ) {
			\wp_send_json_error( [ 'msg' => __( 'You do not have permission to do this.', 'fewer-tags' ) ] );
		}

		if ( ! isset( $_POST['slug'], $_POST['target'], $_POST['taxonomy'] ) || ! is_string( $_POST['slug'] ) || ! is_string( $_POST['target'] ) || ! is_string( $_POST['taxonomy'] ) ) {
			\wp_send_json_error(
				[
					'msg' => __( 'Invalid data.', 'fewer-tags' ),
				]
			);
		}
		$slug     = trim( \wp_strip_all_tags( \wp_unslash( $_POST['slug'] ) ) );
		$taxonomy = \sanitize_key( \wp_unslash( $_POST['taxonomy'] ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized and validated in sanitize_redirect_target().
		$target = $this->sanitize_redirect_target( \wp_unslash( $_POST['target'] ) );

		if ( ! $this->current_user_can_manage_taxonomy( $taxonomy ) ) {
			\wp_send_json_error( [ 'msg' => __( 'You do not have permission to do this.', 'fewer-tags' ) ] );
		}

		$term = Helper::get_term_to_redirect( $taxonomy, $slug );
		if ( null === $term ) {
			\wp_send_json_error( [ 'msg' => __( 'We could not find the deleted term to redirect.', 'fewer-tags' ) ] );
		}

		if ( \is_wp_error( $target ) ) {
			\wp_send_json_error(
				[
					'slug' => $term['slug'],
					'msg'  => $target->get_error_message(),
				]
			);
		}

		$redirects = new Redirects();
		if ( ! $redirects->create_redirect_from_slug( $slug, $taxonomy, $target ) ) {
			$msg = Helper::determine_redirect_tool()
				// translators: %s is the deleted term's name.
				? sprintf( __( 'We could not create the redirect for %s. A redirect for this URL may already exist in your redirect plugin.', 'fewer-tags' ), $term['name'] )
				: __( 'We could not create the redirect. Please make sure the Redirection plugin or Yoast SEO Premium is active.', 'fewer-tags' );
			\wp_send_json_error(
				[
					'slug' => $term['slug'],
					'msg'  => $msg,
				]
			);
		}

		\wp_send_json_success(
			[
				'slug' => $term['slug'],
				// translators: %1$s is the just redirected term name.
				'msg'  => sprintf( \esc_html__( 'Redirect for %1$s created!', 'fewer-tags' ), '<a href="' . \esc_url( $term['permalink'] ) . '">' . \esc_html( $term['name'] ) . '</a>' ),
			]
		);
	}

	/**
	 * Merges two tags, source and target, into target, and redirects the source tag to the target tag.
	 *
	 * @return void
	 */
	public function merge_terms() {
		\check_ajax_referer( 'fewer_tags_merge_terms' );

		if ( ! \current_user_can( 'manage_categories' ) ) {
			\wp_send_json_error( [ 'msg' => __( 'You do not have permission to do this.', 'fewer-tags' ) ] );
		}

		if ( ! isset( $_POST['source_id'], $_POST['target_id'], $_POST['source_taxonomy'], $_POST['target_taxonomy'] ) || ! is_string( $_POST['source_taxonomy'] ) || ! is_string( $_POST['target_taxonomy'] ) ) {
			\wp_send_json_error( [ 'msg' => __( 'Invalid data.', 'fewer-tags' ) ] );
		}
		$source_term_id  = \absint( $_POST['source_id'] );
		$target_term_id  = \absint( $_POST['target_id'] );
		$source_taxonomy = \sanitize_key( \wp_unslash( $_POST['source_taxonomy'] ) );
		$target_taxonomy = \sanitize_key( \wp_unslash( $_POST['target_taxonomy'] ) );

		// Validate everything before we change anything.
		$source_taxonomy_object = Helper::get_supported_taxonomy( $source_taxonomy );
		$target_taxonomy_object = Helper::get_supported_taxonomy( $target_taxonomy );
		if ( ! $source_taxonomy_object || ! $target_taxonomy_object ) {
			\wp_send_json_error( [ 'msg' => __( 'Invalid taxonomy.', 'fewer-tags' ) ] );
		}

		// The source term gets deleted and the target term gets assigned, so check the capabilities of both taxonomies.
		if (
			! \current_user_can( $source_taxonomy_object->cap->manage_terms )
			|| ! \current_user_can( $source_taxonomy_object->cap->delete_terms )
			|| ! \current_user_can( $target_taxonomy_object->cap->manage_terms )
			|| ! \current_user_can( $target_taxonomy_object->cap->assign_terms )
		) {
			\wp_send_json_error( [ 'msg' => __( 'You do not have permission to do this.', 'fewer-tags' ) ] );
		}

		if ( ! $source_term_id || ! $target_term_id || $source_term_id === $target_term_id ) {
			\wp_send_json_error( [ 'msg' => __( 'You cannot merge a term into itself.', 'fewer-tags' ) ] );
		}

		$source_term = \get_term( $source_term_id, $source_taxonomy );
		$target_term = \get_term( $target_term_id, $target_taxonomy );
		if ( ! $source_term instanceof \WP_Term || ! $target_term instanceof \WP_Term ) {
			\wp_send_json_error( [ 'msg' => __( 'We could not find the terms to merge.', 'fewer-tags' ) ] );
		}

		$source_link = \get_term_link( $source_term );
		$target_link = \get_term_link( $target_term );
		if ( \is_wp_error( $source_link ) || \is_wp_error( $target_link ) ) {
			\wp_send_json_error( [ 'msg' => __( 'We could not find the terms to merge.', 'fewer-tags' ) ] );
		}
		$source_url = $this->make_relative_url( $source_link );

		// Terms can only move between taxonomies that are registered for at least one shared object type.
		if ( ! array_intersect( (array) $source_taxonomy_object->object_type, (array) $target_taxonomy_object->object_type ) ) {
			\wp_send_json_error( [ 'msg' => __( 'These taxonomies cannot be merged, as they are not used for the same content types.', 'fewer-tags' ) ] );
		}

		// The default term of a taxonomy cannot be deleted, so we remove it from each post manually after merging.
		$default_taxonomy_term_id = Helper::get_default_term_id( $source_taxonomy );

		// Validate every object before assigning terms or deleting the source.
		$posts = \get_objects_in_term( $source_term_id, $source_taxonomy );
		if ( \is_wp_error( $posts ) ) {
			\wp_send_json_error( [ 'msg' => __( 'We could not find the terms to merge.', 'fewer-tags' ) ] );
		}
		foreach ( $posts as $post_id ) {
			$post_id   = (int) $post_id;
			$post_type = \get_post_type( $post_id );

			if ( ! $post_type || ! \is_object_in_taxonomy( $post_type, $source_taxonomy ) || ! \is_object_in_taxonomy( $post_type, $target_taxonomy ) ) {
				\wp_send_json_error( [ 'msg' => __( 'These terms cannot be merged because some content cannot use the target taxonomy.', 'fewer-tags' ) ] );
			}
		}

		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			\wp_set_object_terms( $post_id, [ $target_term_id ], $target_taxonomy, true );
			if ( $default_taxonomy_term_id && $source_term_id === $default_taxonomy_term_id ) {
				\wp_remove_object_terms( $post_id, $default_taxonomy_term_id, $source_taxonomy );
			}
		}
		// Remove our term deletion functionality, as now we have merged the tags and thus know where to redirect.
		\remove_action( 'delete_term', [ $this, 'redirect_term_on_deletion' ], 10 );
		\remove_action( 'pre_delete_term', [ $this, 'pre_delete_term' ], 10 );

		// Returns true when deleted, 0 for a taxonomy's default term (which is kept), false or a WP_Error on failure.
		$deleted = \wp_delete_term( $source_term_id, $source_taxonomy );
		if ( \is_wp_error( $deleted ) || false === $deleted ) {
			\wp_send_json_error(
				[
					// translators: %1$s is the source term name, %2$s is the target term name.
					'msg' => sprintf( __( 'The content of %1$s was moved to %2$s, but %1$s could not be deleted. No redirect was created.', 'fewer-tags' ), $source_term->name, $target_term->name ),
				]
			);
		}
		$source_deleted = true === $deleted;

		// Only redirect a deleted term: a kept default term still has an archive that receives new content.
		if ( $source_deleted ) {
			// Deleting an ancestor reparents its descendants and can change the target archive URL.
			$target_link = \get_term_link( $target_term_id, $target_taxonomy );
			if ( ! \is_wp_error( $target_link ) ) {
				$redirects = new Redirects();
				$redirects->create_redirect( $source_url, $this->make_relative_url( $target_link ) );
			}
		}

		// We're grabbing this again after we've moved the new posts into the term, so we get the correct count.
		\clean_term_cache( $target_term_id, $target_taxonomy );
		$updated_target_term = \get_term( $target_term_id, $target_taxonomy );
		if ( $updated_target_term instanceof \WP_Term ) {
			$target_term = $updated_target_term;
		}

		// translators: %1$s is the source tag name, %2$s is the target tag name.
		$msg = sprintf( \esc_html__( '%1$s merged into %2$s!', 'fewer-tags' ), '<strong>' . \esc_html( $source_term->name ) . '</strong>', '<strong>' . \esc_html( $target_term->name ) . '</strong>' );
		if ( $source_taxonomy !== $target_taxonomy ) {
			// translators: %1$s is the source tag name, %2$s is the target tag name, %3$s is the source taxonomy, %4$s is the target taxonomy.
			$msg = sprintf( \esc_html__( '%1$s (%3$s) merged into %2$s (%4$s)!', 'fewer-tags' ), '<strong>' . \esc_html( $source_term->name ) . '</strong>', '<strong>' . \esc_html( $target_term->name ) . '</strong>', \esc_html( $source_taxonomy ), \esc_html( $target_taxonomy ) );
		}
		if ( ! $source_deleted ) {
			// translators: %s is the source term name.
			$msg .= ' ' . sprintf( \esc_html__( '%s is the default term, so it was kept and not redirected.', 'fewer-tags' ), '<strong>' . \esc_html( $source_term->name ) . '</strong>' );
		}
		\wp_send_json_success(
			[
				'source_id'      => $source_term_id,
				'source_deleted' => $source_deleted,
				'target_id'      => $target_term_id,
				'target_count'   => (int) $target_term->count,
				'msg'            => $msg,
			]
		);
	}

	/**
	 * Checks whether the current user can manage terms in a taxonomy this plugin supports.
	 *
	 * @param string $taxonomy The taxonomy name.
	 *
	 * @return bool
	 */
	private function current_user_can_manage_taxonomy( $taxonomy ) {
		$taxonomy_object = Helper::get_supported_taxonomy( $taxonomy );

		return $taxonomy_object && \current_user_can( $taxonomy_object->cap->manage_terms );
	}

	/**
	 * Sanitizes and validates the target of a redirect.
	 *
	 * Accepts a site-relative path (starting with a single slash) or an absolute http(s) URL.
	 * Redirecting to another site requires the `manage_options` capability.
	 *
	 * @param string $target The submitted redirect target.
	 *
	 * @return string|\WP_Error The sanitized target, or an error when it is not allowed.
	 */
	private function sanitize_redirect_target( $target ) {
		$target = trim( \wp_strip_all_tags( $target ) );
		$error  = new \WP_Error( 'fewer_tags_invalid_target', __( 'Please enter a valid URL to redirect to: a path on this site that starts with a slash, or a full URL.', 'fewer-tags' ) );

		if ( '' === $target ) {
			return $error;
		}

		if ( '/' === $target[0] ) {
			// Sanitize first: that can remove characters, and we need to judge what is left.
			$target = \esc_url_raw( $target );

			// Protocol-relative URLs ("//example.com", "/\example.com") point to another site, so don't accept those as paths.
			if ( '' === $target || '/' !== $target[0] || ( isset( $target[1] ) && ( '/' === $target[1] || '\\' === $target[1] ) ) ) {
				return $error;
			}

			return $target;
		}

		$target = \esc_url_raw( $target, [ 'http', 'https' ] );
		$host   = \wp_parse_url( $target, PHP_URL_HOST );
		if ( '' === $target || ! is_string( $host ) || '' === $host ) {
			return $error;
		}

		$allowed_hosts = array_filter(
			[
				\wp_parse_url( \home_url(), PHP_URL_HOST ),
				\wp_parse_url( \site_url(), PHP_URL_HOST ),
			]
		);
		if ( ! in_array( strtolower( $host ), array_map( 'strtolower', $allowed_hosts ), true ) && ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'fewer_tags_external_target', __( 'You do not have permission to redirect to another site. Please use a URL on this site.', 'fewer-tags' ) );
		}

		return $target;
	}

	/**
	 * Removes a tag from the "tags to redirect" option.
	 *
	 * @param string $slug     The slug of the tag to remove.
	 * @param string $taxonomy The taxonomy of the tag to remove.
	 *
	 * @return void
	 */
	private function remove_term_from_terms_to_redirect( $slug, $taxonomy ) {
		$terms_to_redirect = $this->options->get( 'terms_to_redirect' );
		if ( ! is_array( $terms_to_redirect ) || ! isset( $terms_to_redirect[ $taxonomy ] ) || ! is_array( $terms_to_redirect[ $taxonomy ] ) || ! isset( $terms_to_redirect[ $taxonomy ][ $slug ] ) ) {
			return;
		}
		unset( $terms_to_redirect[ $taxonomy ][ $slug ] );
		if ( empty( $terms_to_redirect[ $taxonomy ] ) ) {
			unset( $terms_to_redirect[ $taxonomy ] );
		}
		$this->options->set( 'terms_to_redirect', $terms_to_redirect );
	}

	/**
	 * Take an absolute URL and make it relative.
	 *
	 * @param string $url The URL to make relative.
	 *
	 * @return string The relative URL.
	 */
	private function make_relative_url( $url ) {
		return \wp_make_link_relative( $url );
	}
}
