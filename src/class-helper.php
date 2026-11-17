<?php
/**
 * Helper class with utility methods.
 *
 * @package FewerTags
 */

namespace FewerTags;

/**
 * FewerTags Helper Class
 */
class Helper {

	/**
	 * Get a redirect notice.
	 *
	 * @param string $slug     The slug of the term to redirect.
	 * @param string $name     The name of the term to redirect.
	 * @param string $taxonomy The taxonomy of the term to redirect.
	 *
	 * @return string The redirect notice HTML.
	 */
	public static function redirect_term_notice( $slug, $name, $taxonomy ) {
		$message = self::get_redirect_message( $slug, $taxonomy );
		if ( '' === $message ) {
			return '';
		}

		$notice  = '<div id="' . \esc_attr( 'fewer-tags-redirect-' . $slug ) . '" class="notice notice-error is-dismissible fewer-tags-redirect-notice" data-slug="' . \esc_attr( $slug ) . '">';
		$notice .= '<p>';
		$notice .= $message;
		$notice .= '</p>';
		$notice .= '<button type="button" data-nonce="' . \esc_attr( \wp_create_nonce( 'fewer_tags_dismiss_notice' ) ) . '" data-id="' . \esc_attr( 'fewer-tags-dismiss-' . $slug ) . '" data-taxonomy="' . \esc_attr( $taxonomy ) . '" class="notice-dismiss"><span class="screen-reader-text">' . \esc_html__( 'Dismiss this notice.', 'fewer-tags' ) . '</span></button>';
		$notice .= '</div>';

		return $notice;
	}

	/**
	 * Checks whether a taxonomy is one this plugin offers merging and redirecting for.
	 *
	 * Only public taxonomies with an admin UI qualify. That excludes internal taxonomies
	 * such as nav_menu, post_format and wp_theme, which share the default term capabilities.
	 *
	 * @param string $taxonomy The taxonomy name.
	 *
	 * @return \WP_Taxonomy|false The taxonomy object, or false when it is not supported.
	 */
	public static function get_supported_taxonomy( $taxonomy ) {
		if ( '' === $taxonomy || ! \taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$taxonomy_object = \get_taxonomy( $taxonomy );
		if ( ! $taxonomy_object || ! $taxonomy_object->public || ! $taxonomy_object->show_ui ) {
			return false;
		}

		return $taxonomy_object;
	}

	/**
	 * Gets the stored data for a deleted term we can offer a redirect for.
	 *
	 * @param string $taxonomy The taxonomy of the deleted term.
	 * @param string $slug     The slug of the deleted term.
	 *
	 * @return array{name: string, slug: string, taxonomy: string, permalink: string}|null The term data, or null when there is none.
	 */
	public static function get_term_to_redirect( $taxonomy, $slug ) {
		$terms_to_redirect = Option::get_instance()->get( 'terms_to_redirect' );
		if ( ! is_array( $terms_to_redirect ) || ! isset( $terms_to_redirect[ $taxonomy ] ) || ! is_array( $terms_to_redirect[ $taxonomy ] ) || ! isset( $terms_to_redirect[ $taxonomy ][ $slug ] ) ) {
			return null;
		}

		return self::normalize_term_to_redirect( $terms_to_redirect[ $taxonomy ][ $slug ], $taxonomy, $slug );
	}

	/**
	 * Normalizes a stored "term to redirect" entry.
	 *
	 * Entries are stored as plain arrays. Older versions (and Fewer Tags Pro) stored a
	 * WP_Term object under the `object` key, which we still read.
	 *
	 * @param mixed  $entry    The stored entry.
	 * @param string $taxonomy The taxonomy the entry is stored under.
	 * @param string $slug     The slug the entry is stored under.
	 *
	 * @return array{name: string, slug: string, taxonomy: string, permalink: string}|null The normalized entry, or null when it is invalid.
	 */
	public static function normalize_term_to_redirect( $entry, $taxonomy, $slug ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$name = null;
		if ( isset( $entry['name'] ) && is_string( $entry['name'] ) ) {
			$name = $entry['name'];
		} elseif ( isset( $entry['object'] ) && $entry['object'] instanceof \WP_Term ) {
			$name = $entry['object']->name;
		}
		if ( null === $name ) {
			return null;
		}

		return [
			'name'      => $name,
			'slug'      => (string) $slug,
			'taxonomy'  => (string) $taxonomy,
			'permalink' => ( isset( $entry['permalink'] ) && is_string( $entry['permalink'] ) ) ? $entry['permalink'] : '',
		];
	}

	/**
	 * Determines which redirect tool to use.
	 *
	 * @return string|false The redirect tool to use.
	 */
	public static function determine_redirect_tool() {
		if ( \defined( 'REDIRECTION_DB_VERSION' ) ) {
			return 'redirection';
		}
		if ( \defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			return 'yoast';
		}
		return false;
	}

	/**
	 * Gets the message to redirect a tag when it's deleted.
	 *
	 * @param string $slug     The slug of the tag to redirect.
	 * @param string $taxonomy The taxonomy of the term to redirect.
	 *
	 * @return string The message to redirect a tag when it's deleted.
	 */
	public static function get_redirect_message( $slug, $taxonomy ) {
		$term            = self::get_term_to_redirect( $taxonomy, $slug );
		$taxonomy_object = \get_taxonomy( $taxonomy );
		if ( null === $term || ! $taxonomy_object ) {
			return '';
		}

		$term_link = '<a href="' . \esc_url( $term['permalink'] ) . '">' . \esc_html( $term['name'] ) . '</a>';
		$singular  = \esc_html( strtolower( $taxonomy_object->labels->singular_name ) );

		$msg = '<strong>' . \esc_html__( 'Fewer Tags notice', 'fewer-tags' ) . '</strong><br/>';

		$tool = self::determine_redirect_tool();

		// Without a redirect tool we cannot create a redirect, so don't offer one. Point the user at the tools instead.
		if ( ! $tool ) {
			// translators: %1$s is the term name (linked), %2$s is the singular taxonomy label, e.g. "tag".
			$msg .= sprintf( \esc_html__( 'You\'ve deleted the %2$s "%1$s". To redirect it, install and activate the Redirection plugin or Yoast SEO Premium.', 'fewer-tags' ), $term_link, $singular );
			return $msg;
		}

		// translators: %1$s is the term name (linked), %2$s is the singular taxonomy label, e.g. "tag".
		$msg .= sprintf( \esc_html__( 'You\'ve deleted the %2$s "%1$s", let\'s redirect it?', 'fewer-tags' ), $term_link, $singular );

		if ( $tool === 'redirection' ) {
			$msg .= ' ' . \esc_html__( 'We can use your Redirection plugin to do that.', 'fewer-tags' );
		}
		if ( $tool === 'yoast' ) {
			$msg .= ' ' . \esc_html__( 'We can use your Yoast SEO Premium plugin to do that.', 'fewer-tags' );
		}

		// The links carry their data in attributes; a click listener in fewer-tags.js handles them.
		$attributes = ' data-slug="' . \esc_attr( $slug ) . '" data-taxonomy="' . \esc_attr( $taxonomy ) . '" data-nonce="' . \esc_attr( \wp_create_nonce( 'fewer_tags_redirect_url' ) ) . '"';

		$msg .= '<ul>';
		$msg .= '<li style="list-style-type: disc; margin-left: 15px;"><button type="button" class="button-link fewer-tags-redirect-action"' . $attributes . ' data-target="/">' . \esc_html__( 'Redirect to homepage', 'fewer-tags' ) . '</button></li>';
		$msg .= '<li style="list-style-type: disc; margin-left: 15px;"><button type="button" class="button-link fewer-tags-redirect-action"' . $attributes . ' data-prompt="' . \esc_attr__( 'Where should the page redirect to?', 'fewer-tags' ) . '">' . \esc_html__( 'Redirect to another URL', 'fewer-tags' ) . '</button></li>';
		$msg .= '</ul>';

		return $msg;
	}
}
