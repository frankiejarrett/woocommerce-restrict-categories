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

		// Omit posts in restricted taxonomies from frontend queries when not authenticated
		add_action( 'pre_get_posts', array( $this, 'maybe_filter_posts' ) );

		// Prevent products in restricted taxonomies from being purchasable when not authenticated
		add_filter( 'woocommerce_is_purchasable', array( $this, 'maybe_is_purchasable' ), 10, 2 );
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

		$restricted = (array) get_option( WC_Restrict_Categories::PREFIX . $taxonomy );

		if ( in_array( $term->term_id, $restricted ) ) {
			self::password_notice( $term->term_id, $taxonomy );
		}
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

		$post_id = get_queried_object_id();
		$r_terms = self::get_post_restricted_terms( $post_id );

		if ( empty( $r_terms ) ) {
			return;
		}

		foreach ( $r_terms as $taxonomy => $terms ) {
			foreach ( $terms as $key => $term_id ) {
				/**
				 * Filter if/when posts in restricted taxonomies should be restricted
				 *
				 * By default, posts that belong to restricted categories are
				 * also restricted when their URL is accessed directly by
				 * unauthenticated users. This behavior can be overridden here
				 * globally or on a more granular post/tax/term basis using the
				 * available params.
				 *
				 * @since 1.0.0
				 *
				 * @param int    $post_id
				 * @param string $taxonomy
				 * @param int    $term_id
				 *
				 * @return bool
				 */
				$restrict_post = (bool) apply_filters( 'wcrc_restrict_post', true, $post_id, $taxonomy, $term_id );

				if ( false === $restrict_post ) {
					unset( $r_terms[ $taxonomy ][ $key ] );
				}
			}

			if ( empty( $r_terms[ $taxonomy ] ) ) {
				unset( $r_terms[ $taxonomy ] );
			}
		}

		if ( empty( $r_terms ) ) {
			return;
		}

		$terms   = array_shift( $r_terms );
		$term_id = array_shift( $terms );

		self::password_notice( $term_id, $taxonomy );
	}

	/**
	 * Omit posts from restricted taxonomies from main queries when not authenticated
	 *
	 * @action pre_get_posts
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_filter_posts( $query ) {
		if (
			is_admin()
			||
			! in_array( $query->get( 'post_type' ), WC_Restrict_Categories::$post_types )
		) {
			return;
		}

		$tax_queries = array();

		foreach ( WC_Restrict_Categories::$taxonomies as $taxonomy ) {
			$terms  = (array) get_option( WC_Restrict_Categories::PREFIX . sanitize_key( $taxonomy ) );
			$terms  = array_filter( $terms );
			$_terms = array();

			foreach ( $terms as $term_id ) {
				/**
				 * Filter if/when posts should be hidden from frontend queries
				 *
				 * By default, posts that belong to restricted categories are
				 * filtered out of the frontend query results for unauthenticated
				 * users. This behavior can be overridden here globally or on a
				 * more granular tax/term basis using the available params.
				 *
				 * @since 1.0.0
				 *
				 * @param WP_Query $query
				 * @param string   $taxonomy
				 * @param int      $term_id
				 *
				 * @return bool
				 */
				$hide_posts = (bool) apply_filters( 'wcrc_hide_posts_from_results', true, $query, $taxonomy, $term_id );

				if ( false === $hide_posts ) {
					continue;
				}

				if ( ! self::is_access_granted( $term_id, $taxonomy ) ) {
					$_terms[] = $term_id;
				}
			}

			if ( ! empty( $_terms ) ) {
				$tax_queries[] = array(
					'taxonomy'  => $taxonomy,
					'terms'     => $_terms,
					'operator'  => 'NOT IN'
				);
			}
		}

		// Don't overwrite any existing tax queries
		if ( ! empty( $query->tax_query->queries ) ) {
			$tax_queries = array_merge( (array) $query->tax_query->queries, $tax_queries );
		}

		if ( ! empty( $tax_queries ) ) {
			$query->set( 'tax_query', $tax_queries );
		}
	}

	/**
	 * Prevent products in restricted categories from being purchasable when unauthenticated
	 *
	 * @filter woocommerce_is_purchasable
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @param bool   $is_purchasable
	 * @param object $product
	 *
	 * @return bool
	 */
	public function maybe_is_purchasable( $is_purchasable, $product ) {
		if ( self::get_post_restricted_terms( $product->id ) ) {
			return false;
		}

		return $is_purchasable;
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
		if ( self::is_access_granted( $term_id, $taxonomy ) ) {
			return;
		}

		$tax_label = WC_Restrict_Categories::get_tax_label( $taxonomy, 'singular_name' );
		$self_url  = home_url( wp_unslash( remove_query_arg( 'wcrc-auth', $_SERVER['REQUEST_URI'] ) ) );
		$incorrect = ( isset( $_GET['wcrc-auth'] ) && 'incorrect' === $_GET['wcrc-auth'] );

		ob_start();
		?>
		<div style="text-align:center;">
			<?php if ( $incorrect ) : ?>
				<p style="background:#ffe6e5;border:1px solid #ffc5c2;padding:10px;"><strong><?php _e( 'The password you entered is incorrect. Please try again.', 'woocommerce-restrict-categories' ) ?></strong></p>
			<?php endif; ?>
			<h1 style="border:none;"><?php printf( __( 'This is a Restricted %s', 'woocommerce-restrict-categories' ), esc_html( $tax_label ) ) ?></h1>
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

		wp_die( $message, sprintf( __( 'This is a Restricted %s', 'woocommerce-restrict-categories' ), esc_html( $tax_label ) ) );
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
	public static function is_valid_cookie( $term_id, $taxonomy ) {
		$cookie = WC_Restrict_Categories_Term_Meta::get_tax_term_option_name( $term_id, $taxonomy, 'hash' );
		$hash   = ! empty( $_COOKIE[ $cookie ] ) ? $_COOKIE[ $cookie ] : null;

		if ( empty( $hash ) ) {
			return false;
		}

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
		if ( ! is_user_logged_in() ) {
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
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$whitelist = (array) WC_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'user_whitelist' );

		return in_array( get_current_user_id(), $whitelist );
	}

	/**
	 * Returns true when a visitor is granted access
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
	public static function is_access_granted( $term_id, $taxonomy ) {
		// Exit early when applicable in rare cases
		// No need to fire the access denied action this early
		// You can't be denied access to something that doesn't exist
		if ( ! term_exists( $term_id, $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		if (
			self::is_valid_cookie( $term_id, $taxonomy )
			||
			self::has_whitelisted_role( $term_id, $taxonomy )
			||
			self::is_whitelisted_user( $term_id, $taxonomy )
		) {
			/**
			 * Fires after a visitor has been granted automatic access
			 *
			 * @param string  $taxonomy
			 * @param int     $term_id
			 */
			do_action( 'wcrc_access_granted', $taxonomy, $term_id );

			return true;
		}

		/**
		 * Fires after a visitor has been denied access
		 *
		 * @param string  $taxonomy
		 * @param int     $term_id
		 */
		do_action( 'wcrc_access_denied', $taxonomy, $term_id );

		return false;
	}

	/**
	 * Returns an array of restricted taxonomy terms for a given post
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param mixed $post  Post ID or WP_Post object
	 *
	 * @return array|bool
	 */
	public static function get_post_restricted_terms( $post ) {
		$post_id = isset( $post->ID ) ? $post->ID : absint( $post );

		if (
			empty( $post_id )
			||
			! in_array( get_post_type( $post_id ), WC_Restrict_Categories::$post_types )
		) {
			return false;
		}

		$output = array();

		foreach ( WC_Restrict_Categories::$taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$terms   = wp_list_pluck( $terms, 'term_id' );
			$r_terms = (array) get_option( WC_Restrict_Categories::PREFIX . $taxonomy );
			$matches = array_intersect( $terms, $r_terms );

			sort( $matches );

			if ( ! empty( $matches[0] ) ) {
				$output[ $taxonomy ] = $matches;
			}
		}

		ksort( $output );

		if ( ! empty( $output ) ) {
			return (array) $output;
		}

		return false;
	}

}

new WC_Restrict_Categories_Auth();
