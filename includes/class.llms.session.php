<?php
/**
 * LLMS_Session.
 *
 * @package LifterLMS/Classes
 *
 * @since 1.0.0
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

/**
 * LLMS_Session class.
 *
 * @since 1.0.0
 * @since 3.7.7 Unknown.
 * @since 3.37.7 Added a second parameter to the `get()` method, that represents the default value
 *               to return if the session variable requested doesn't exist.
 * @since [version] Major refactor to remove reliance on the wp-session-manager library:
 *               + Moved getters & setter methods into LLMS_Abstract_Session_Data
 *               + Added new methods to support built-in DB session management.
 *               + Deprecated legacy methods
 *               + Removed the ability to utilize PHP sessions.
 */
class LLMS_Session extends LLMS_Abstract_Session_Database_Handler {

	/**
	 * Session cookie name
	 *
	 * @var string
	 */
	protected $cookie = '';

	/**
	 * Timestamp of the session's expiration
	 *
	 * @var int
	 */
	protected $expires;

	/**
	 * Timestamp of when the session is nearing expiration
	 *
	 * @var int
	 */
	protected $expiring;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 3.7.5 Unknown.
	 * @since [version] Removed PHP sessions.
	 *               Added session auto-destroy on `wp_logout`.
	 *
	 * @return void
	 */
	public function __construct() {

		/**
		 * Customize the name of the LifterLMS User Session Cookie
		 *
		 * @since [version]
		 *
		 * @param string $name Default session cookie name.
		 */
		$this->cookie = apply_filters( 'llms_session_cookie_name', sprintf( 'wp_llms_session_%s', COOKIEHASH ) );

		$this->init_cookie();

		add_action( 'wp_logout', array( $this, 'destroy' ) );
		add_action( 'shutdown', array( $this, 'maybe_save_data' ), 20 );

		/**
		 * Trigger cleanup via action.
		 *
		 * This is hooked to an hourly scheduled task.
		 */
		add_action( 'llms_delete_expired_session_data', array( $this, 'clean' ) );

	}

	/**
	 * Destroys the current session
	 *
	 * Removes session data from the database, expires the cookie,
	 * and resets class variables.
	 *
	 * @since [version]
	 *
	 * @return boolean
	 */
	public function destroy() {

		// Delete from DB.
		$this->delete( $this->get_id() );

		// Reset class vars.
		$this->id       = '';
		$this->data     = array();
		$this->is_clean = true;

		// Destroy the cookie.
		return llms_setcookie( $this->cookie, '', time() - YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $this->use_secure_cookie(), true );

	}

	/**
	 * Retrieve an validate the session cookie
	 *
	 * @since [version]
	 *
	 * @return false|mixed[]
	 */
	protected function get_cookie() {

		$value = isset( $_COOKIE[ $this->cookie ] ) ? wp_unslash( $_COOKIE[ $this->cookie ] ) : false;

		if ( empty( $value ) || ! is_string( $value ) ) {
			return false;
		}

		/**
		 * Explode the cookie into it's parts.
		 *
		 * @param string|int $0 User ID.
		 * @param int        $1 Expiration timestamp.
		 * @param int        $2 Expiration variance timestamp.
		 * @param string     $3 Cookie hash.
		 */
		$parts = explode( '||', $value );

		if ( empty( $parts[0] ) || empty( $parts[3] ) ) {
			return false;
		}

		$hash_str = sprintf( '%1$s|%2$s', $parts[0], $parts[1] );
		$expected = hash_hmac( 'md5', $hash_str, wp_hash( $hash_str ) );

		if ( ! hash_equals( $expected, $parts[3] ) ) {
			return false;
		}

		return $parts;

	}

	/**
	 * Initialize the session cookie
	 *
	 * Retrieves and validates the cookie,
	 * when there's a valid cookie it will initialize the object
	 * with data from the cookie. Otherwise it sets up and saves
	 * a new session and cookie.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	protected function init_cookie() {

		$cookie = $this->get_cookie();

		if ( $cookie ) {

			$this->id       = $cookie[0];
			$this->expires  = $cookie[1];
			$this->expiring = $cookie[2];
			$this->data     = $this->read( $this->id );

			// If the user has logged in, update the session data.
			$this->maybe_update_id();

			// If the session is nearing expiration, update the session.
			$this->maybe_extend_expiration();

		} else {

			$this->id   = $this->generate_id();
			$this->data = array();
			$this->set_expiration();
			$this->set_cookie();

		}

	}

	/**
	 * Extend the sessions expiration when the session is nearing expiration
	 *
	 * If the user is still active on the site and the cookie is older than the
	 * "expiring" time but not yet expired, renew the session.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	protected function maybe_extend_expiration() {

		if ( time() > $this->expiring ) {
			$this->set_expiration();
			$this->is_clean = false;
			$this->save( $this->expires );
		}

	}

	/**
	 * Save session data if not clean
	 *
	 * Callback for `shutdown` action hook.
	 *
	 * @since [version]
	 *
	 * @return boolean
	 */
	public function maybe_save_data() {

		if ( ! $this->is_clean ) {
			return $this->save( $this->expires );
		}

		return false;

	}

	/**
	 * Updates the session id when an anonymous visitor logs in.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	protected function maybe_update_id() {

		$uid = strval( get_current_user_id() );
		if ( $uid && $uid !== $this->get_id() ) {
			$old_id         = $this->get_id();
			$this->id       = $uid;
			$this->is_clean = false;
			$this->delete( $old_id );
			$this->save( $this->expires );
			$this->set_cookie();
		}

	}

	/**
	 * Set the cookie
	 *
	 * @since [version]
	 *
	 * @return boolean
	 */
	protected function set_cookie() {

		$hash_str = sprintf( '%1$s|%2$s', $this->get_id(), $this->expires );
		$hash     = hash_hmac( 'md5', $hash_str, wp_hash( $hash_str ) );
		$value    = sprintf( '%1$s||%2$d||%3$d||%4$s', $this->get_id(), $this->expires, $this->expiring, $hash );

		// There's no cookie set or the existing cookie needs to be updated.
		if ( ! isset( $_COOKIE[ $this->cookie ] ) || $_COOKIE[ $this->cookie ] !== $value ) {

			return llms_setcookie( $this->cookie, $value, $this->expires, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $this->use_secure_cookie(), true );

		}

		return false;

	}

	/**
	 * Set cookie expiration and expiring timestamps
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	protected function set_expiration() {

		/**
		 * Filter the lifespan of user session data
		 *
		 * @since [version]
		 *
		 * @param int $duration Lifespan of session data, in seconds.
		 */
		$duration = (int) apply_filters( 'llms_session_data_expiration_duration', HOUR_IN_SECONDS * 6 );

		/**
		 * Filter the user session lifespan variance
		 *
		 * This is subtracted from the session cookie expiration to determine it's "expiring" timestamp.
		 *
		 * When an active session passes it's expiring timestamp but has not yet passed it's expiration timestamp
		 * the session data will be extended and the data session will not be destroyed.
		 *
		 * @since [version]
		 *
		 * @param int $duration Lifespan of session data, in seconds.
		 */
		$variance = (int) apply_filters( 'llms_session_data_expiration_variance', HOUR_IN_SECONDS );

		$this->expires  = time() + $duration;
		$this->expiring = $this->expires - $variance;

	}

	/**
	 * Determine if a secure cookie should be used.
	 *
	 * @since [version]
	 *
	 * @return boolean
	 */
	protected function use_secure_cookie() {

		$secure = llms_is_site_https() && is_ssl();

		/**
		 * Determine whether or not a secure cookie should be used for user session data
		 *
		 * @since [version]
		 *
		 * @param boolean $secure Whether or not a secure cookie should be used.
		 */
		return apply_filters( 'llms_session_use_secure_cookie', $secure );

	}

	/**
	 * Setup the WP_Session instance.
	 *
	 * @since Unknown
	 * @deprecated [version]
	 *
	 * @return array
	 */
	public function init() {
		llms_deprecated_function( 'LLMS_Session::init', '[version]' );
		return $this->data;
	}

	/**
	 * Starts a new session if one hasn't started yet.
	 *
	 * @since Unknown
	 * @deprecated [version]
	 *
	 * @return void
	 */
	public function maybe_start_session() {
		llms_deprecated_function( 'LLMS_Session::maybe_start_session', '[version]' );
	}

	/**
	 * Deprecated.
	 *
	 * @since Unknown
	 * @deprecated [version]
	 *
	 * @param int $exp Default expiration time in seconds.
	 * @return int
	 */
	public function set_expiration_variant_time( $exp ) {
		llms_deprecated_function( 'LLMS_Session::set_expiration_variant_time', '[version]' );
		return $exp;
	}

	/**
	 * Deprecated.
	 *
	 * @since Unknown
	 * @deprecated [version]
	 *
	 * @param int $exp Default expiration (1 hour).
	 * @return int
	 */
	public function set_expiration_time( $exp ) {
		llms_deprecated_function( 'LLMS_Session::set_expiration_time', '[version]' );
		return $exp;
	}

	/**
	 * Determine should we use php session or wp.
	 *
	 * @since Unknown
	 * @deprecated [version]
	 *
	 * @return bool
	 */
	public function use_php_sessions() {
		llms_deprecated_function( 'LLMS_Session::use_php_sessions', '[version]' );
		return false;
	}

}
