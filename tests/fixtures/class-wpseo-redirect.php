<?php
/**
 * Yoast SEO Premium redirect doubles for isolated tests.
 *
 * Mirror the parts of WPSEO_Redirect_Option that Fewer Tags relies on: add()
 * returns false when a redirect for the same origin already exists.
 *
 * @package FewerTags
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- Small test doubles.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Must match Yoast's class names.

/**
 * Redirect value object double.
 */
class WPSEO_Redirect {
	/**
	 * Redirect origin.
	 *
	 * @var string
	 */
	public $origin;

	/**
	 * Redirect target.
	 *
	 * @var string
	 */
	public $target;

	/**
	 * Constructor.
	 *
	 * @param string $origin Origin.
	 * @param string $target Target.
	 */
	public function __construct( $origin, $target ) {
		$this->origin = $origin;
		$this->target = $target;
	}
}

/**
 * Redirect storage double.
 */
class WPSEO_Redirect_Option {
	/**
	 * Saved redirects, keyed by origin.
	 *
	 * @var array
	 */
	public static $saved = [];

	/**
	 * Redirects added in this instance, keyed by origin.
	 *
	 * @var array
	 */
	private $redirects = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->redirects = self::$saved;
	}

	/**
	 * Add a redirect unless one exists for its origin.
	 *
	 * @param WPSEO_Redirect $redirect Redirect.
	 * @return bool
	 */
	public function add( $redirect ) {
		if ( isset( $this->redirects[ $redirect->origin ] ) ) {
			return false;
		}
		$this->redirects[ $redirect->origin ] = $redirect->target;
		return true;
	}

	/**
	 * Persist the redirects.
	 *
	 * @return void
	 */
	public function save() {
		self::$saved = $this->redirects;
	}
}

/**
 * Redirect export double.
 */
class WPSEO_Redirect_Manager {
	/**
	 * No-op export.
	 *
	 * @return void
	 */
	public function export_redirects() {}
}
