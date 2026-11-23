<?php
/**
 * Redirection provider double for isolated merge tests.
 *
 * @package FewerTags
 */

namespace FewerTags\Tests;

/**
 * Captures the redirect submitted to the provider without loading that plugin.
 */
class Redirection_Item_Stub {
	/**
	 * Submitted redirect data.
	 *
	 * @var array
	 */
	public static $redirect = [];

	/**
	 * Capture a successful redirect creation.
	 *
	 * @param array $data Redirect data.
	 * @return self
	 */
	public static function create( $data ) {
		self::$redirect = $data;
		return new self();
	}
}
