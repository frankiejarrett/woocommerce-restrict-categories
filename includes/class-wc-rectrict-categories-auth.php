<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Restrict_Categories_Auth {

	/**
	 * Class constructor
	 *
	 * @access public
	 */
	public function __construct() {
		// Verify authentication on restricted taxonomies/products
		add_action( 'template_redirect', array( $this, 'authenticate' ) );

		// Check for possible restriction on taxonomy term archives
		add_action( 'template_redirect', array( $this, 'maybe_restrict_tax_term_archive' ) );

		// Check for possible restriction on single products
		add_action( 'template_redirect', array( $this, 'maybe_restrict_post' ) );
	}

	/**
	 * Verify authentication on restricted taxonomies/products
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function authenticate() {
		if (
			empty( $_POST['wcrc_auth_nonce'] )
			||
			empty( $_POST['wcrc_pass'] )
			||
			empty( $_POST['wcrc_taxonomy'] )
			||
			empty( $_POST['wcrc_term_id'] )
			||
			empty( $_POST['_wp_http_referer'] )
		) {
			return;
		}

		$pass     = trim( $_POST['wcrc_pass'] );
		$taxonomy = sanitize_key( $_POST['wcrc_taxonomy'] );
		$term_id  = absint( $_POST['wcrc_term_id'] );
		$redirect = home_url( wp_unslash( remove_query_arg( 'wcrc-auth', $_POST['_wp_http_referer'] ) ) );

		if ( false === wp_verify_nonce( $_POST['wcrc_auth_nonce'], sprintf( 'wcrc_auth_nonce-%s-%d', $taxonomy, $term_id ) ) ) {
			return;
		}

		$_pass = (string) WC_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'pass' );

		if ( $pass === $_pass ) {
			/**
			 * Fires after a user has successfully entered the correct password
			 *
			 * @param string $taxonomy
			 * @param int    $term_id
			 * @param string $pass
			 */
			do_action( 'wcrc_password_success', $taxonomy, $term_id, $pass );

			self::set_cookie( $term_id, $taxonomy, $pass );

			wp_safe_redirect( $redirect, 302 );

			exit;
		}

		/**
		 * Fires after a user has entered an incorrect password
		 *
		 * @param string $taxonomy
		 * @param int    $term_id
		 * @param string $pass
		 */
		do_action( 'wcrc_password_incorrect', $taxonomy, $term_id, $pass );

		$redirect = add_query_arg(
			array(
				'wcrc-auth' => 'incorrect',
			),
			$redirect
		);

		wp_safe_redirect( $redirect, 302 );

		exit;
	}

	/**
	 * Check for possible restriction on taxonomy term archives
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_restrict_tax_term_archive() {
		if ( ! is_tax() && ! is_category() && ! is_tag() ) {
			return;
		}

		$taxonomy = is_category() ? 'category' : ( is_tag() ? 'post_tag' : get_query_var( 'taxonomy' ) );

		if ( ! in_array( $taxonomy, WC_Restrict_Categories::$taxonomies ) ) {
			return;
		}

		$term_slug = is_category() ? get_query_var( 'category_name' ) : ( is_tag() ? get_query_var( 'tag' ) : get_query_var( $taxonomy ) );

		if ( empty( $term_slug ) ) {
			return;
		}

		$term = get_term_by( 'slug', $term_slug, $taxonomy );

		if ( ! $term ) {
			return;
		}

		self::password_notice( $term->term_id, $taxonomy );
	}

	/**
	 * Check for possible restriction on single products
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_restrict_post() {
		if ( ! is_singular() ) {
			return;
		}

		if ( ! in_array( get_post_type(), WC_Restrict_Categories::$post_types ) ) {
			return;
		}

		foreach ( WC_Restrict_Categories::$taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( get_queried_object_id(), $taxonomy );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$terms      = wp_list_pluck( $terms, 'term_id' );
			$restricted = (array) get_option( WC_Restrict_Categories::PREFIX . $taxonomy );
			$intersect  = array_intersect( $terms, $restricted );

			if ( ! empty( $intersect[0] ) ) {
				self::password_notice( $intersect[0], $taxonomy );
			}
		}
	}

	/**
	 * Display restriction notice requiring a password to proceed
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @return void
	 */
	public static function password_notice( $term_id, $taxonomy ) {
		$cookie = WC_Restrict_Categories_Term_Meta::get_tax_term_option_name( $term_id, $taxonomy, 'hash' );
		$hash   = ! empty( $_COOKIE[ $cookie ] ) ? $_COOKIE[ $cookie ] : null;

		if (
			self::has_whitelisted_role( $term_id, $taxonomy )
			||
			self::is_whitelisted_user( $term_id, $taxonomy )
			||
			self::is_valid_cookie( $term_id, $taxonomy, $hash )
		) {
			/**
			 * Fires after a visitor has been granted automatic access
			 *
			 * @param string  $taxonomy
			 * @param int     $term_id
			 */
			do_action( 'wcrc_access_granted', $taxonomy, $term_id );

			return;
		}

		$tax_object = get_taxonomy( $taxonomy );
		$tax_labels = get_taxonomy_labels( $tax_object );
		$self_url   = home_url( wp_unslash( remove_query_arg( 'wcrc-auth', $_SERVER['REQUEST_URI'] ) ) );
		$incorrect  = ( isset( $_GET['wcrc-auth'] ) && 'incorrect' === $_GET['wcrc-auth'] );

		ob_start();
		?>
		<div style="text-align:center;">
			<?php if ( $incorrect ) : ?>
				<p style="background:#ffe6e5;border:1px solid #ffc5c2;padding:10px;"><strong><?php _e( 'The password you entered is incorrect. Please try again.', 'woocommerce-restrict-categories' ) ?></strong></p>
			<?php endif; ?>
			<h1 style="border:none;"><?php printf( __( 'This is a Restricted %s', 'woocommerce-restrict-categories' ), esc_html( $tax_labels->singular_name ) ) ?></h1>
			<p><?php _e( 'Please enter the password to unlock:', 'woocommerce-restrict-categories' ) ?></p>
			<form method="post" action="<?php echo esc_url( $self_url ) ?>">
				<p><input type="password" name="wcrc_pass" size="30" style="padding:3px 5px;font-size:16px;text-align:center;" autocomplete="off"></p>
				<p>
					<input type="hidden" name="wcrc_taxonomy" value="<?php echo sanitize_key( $taxonomy ) ?>">
					<input type="hidden" name="wcrc_term_id" value="<?php echo absint( $term_id ) ?>">
					<?php wp_nonce_field( sprintf( 'wcrc_auth_nonce-%s-%d', sanitize_key( $taxonomy ), absint( $term_id ) ), 'wcrc_auth_nonce' ) ?>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Continue', 'woocommerce-restrict-categories' ) ?>">
				</p>
			</form>
		</div>
		<?php
		$message = ob_get_clean();

		wp_die( $message, sprintf( __( 'This is a Restricted %s', 'woocommerce-restrict-categories' ), esc_html( $tax_labels->singular_name ) ) );
	}

	/**
	 * Set authentication cookie after access has been granted
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @return void
	 */
	public static function set_cookie( $term_id, $taxonomy, $pass ) {
		$name = WC_Restrict_Categories_Term_Meta::get_tax_term_option_name( $term_id, $taxonomy, 'hash' );

		/**
		 * Filter authentication cookie expiration length (in seconds)
		 *
		 * @since 1.0.0
		 *
		 * @param string $taxonomy
		 * @param int    $term_id
		 *
		 * @return int
		 */
		$ttl = absint( apply_filters( 'wcrc_auth_cookie_ttl', HOUR_IN_SECONDS, $taxonomy, $term_id ) );

		setcookie( $name, wp_hash_password( $pass ), time() + $ttl, '/' );
	}

	/**
	 * Check authentication cookie hash for validity before granting access
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @param string $hash
	 *
	 * @return bool
	 */
	public static function is_valid_cookie( $term_id, $taxonomy, $hash ) {
		if ( ! class_exists( 'PasswordHash' ) ) {
			require_once ABSPATH . 'wp-includes/class-phpass.php';
		}

		$hasher = new PasswordHash( 8, true );
		$pass   = (string) WC_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'pass' );

		return $hasher->CheckPassword( $pass, $hash );
	}

	/**
	 * Returns true when a user's role has been whitelisted for a given taxonomy term
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 *
	 * @return bool
	 */
	public static function has_whitelisted_role( $term_id, $taxonomy ) {
		if (
			! is_user_logged_in()
			||
			! taxonomy_exists( $taxonomy )
			||
			! term_exists( absint( $term_id ), $taxonomy )
		) {
			return false;
		}

		$user      = wp_get_current_user();
		$whitelist = (array) WC_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'role_whitelist' );
		$roles     = isset( $user->roles ) ? (array) $user->roles : array();
		$intersect = array_intersect( $roles, $whitelist );

		return ! empty( $intersect );
	}

	/**
	 * Returns true when a user has been whitelisted for a given taxonomy term
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 *
	 * @return bool
	 */
	public static function is_whitelisted_user( $term_id, $taxonomy ) {
		if (
			! is_user_logged_in()
			||
			! taxonomy_exists( $taxonomy )
			||
			! term_exists( absint( $term_id ), $taxonomy )
		) {
			return false;
		}

		$whitelist = (array) WC_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'user_whitelist' );

		return in_array( get_current_user_id(), $whitelist );
	}

}

new WC_Restrict_Categories_Auth();
