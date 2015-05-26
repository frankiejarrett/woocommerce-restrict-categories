<?php
/**
 * Plugin Name: WooCommerce Restrict Categories
 * Plugin URI: http://www.woothemes.com/products/woocommerce-restrict-categories/
 * Description:
 * Version: 1.0.0
 * Author: WooThemes
 * Author URI: http://woothemes.com/
 * Developer: Frankie Jarrett
 * Developer URI: http://frankiejarrett.com/
 * Depends: WooCommerce
 * Text Domain: woocommerce-restrict-categories
 * Domain Path: /languages
 *
 * Copyright: © 2009-2015 WooThemes.
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class
 *
 *
 *
 * @version 1.0.0
 * @package WooCommerce
 * @author  Frankie Jarrett
 */
class WC_Restrict_Categories {

	/**
	 * Hold class instance
	 *
	 * @access public
	 * @static
	 *
	 * @var WC_Restrict_Categories
	 */
	public static $instance;

	/**
	 * Hold the taxonomy slugs where restriction is enabled
	 *
	 * @access public
	 * @static
	 *
	 * @var array
	 */
	public static $taxonomies;

	/**
	 * Plugin version number
	 *
	 * @const string
	 */
	const VERSION = '1.0.0';

	/**
	 * Unique key prefix
	 *
	 * @const string
	 */
	const PREFIX = 'wcrc_';

	/**
	 * Class constructor
	 *
	 * @access private
	 */
	private function __construct() {
		if ( ! $this->woocommerce_exists() ) {
			return;
		}

		define( 'WC_RESTRICT_CATEGORIES_URL', plugins_url( '/', __FILE__ ) );

		/**
		 * Register the taxonomy slugs where restriction will be enabled
		 *
		 * @since 1.0.0
		 *
		 * @return array
		 */
		self::$taxonomies = (array) apply_filters( 'wcrc_taxonomies', array( 'product_cat', 'product_tag' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		foreach ( self::$taxonomies as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_custom_term_fields' ), 11, 2 );
		}

		add_action( 'edit_term', array( $this, 'save_custom_term_fields' ), 10, 3 );

		add_action( 'wp_ajax_wcrc_search_users', array( $this, 'ajax_search_users' ) );

		add_action( 'wp_ajax_wcrc_add_user', array( $this, 'ajax_add_user' ) );

		add_action( 'template_redirect', array( $this, 'maybe_restrict_tax_term_archive' ) );

		add_action( 'template_redirect', array( $this, 'maybe_restrict_product' ) );
	}

	/**
	 * Return an active instance of this class
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @return WC_Restrict_Categories
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Returns true if WooCommerce exists
	 *
	 * Looks at the active list of plugins on the site to
	 * determine if WooCommerce is installed and activated.
	 *
	 * @access private
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function woocommerce_exists() {
		return in_array( 'woocommerce/woocommerce.php', (array) apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
	}

	/**
	 * Enqueue scripts and styles in the admin
	 *
	 * @action admin_enqueue_scripts
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		$screen = get_current_screen();

		if ( ! isset( $screen->id ) ) {
			return;
		}

		$screen_ids = array();

		foreach ( self::$taxonomies as $taxonomy ) {
			$screen_ids[] = 'edit-' . $taxonomy;
		}

		$screen_ids = implode( ' ', $screen_ids );

		if ( false === strpos( $screen_ids, $screen->id ) ) {
			return;
		}

		// Scripts
		wp_enqueue_script( 'wcrc-admin', WC_RESTRICT_CATEGORIES_URL . 'ui/js/admin.js', array( 'jquery', 'select2' ), self::VERSION );

		// Styles
		wp_enqueue_style( 'wcrc-admin', WC_RESTRICT_CATEGORIES_URL . 'ui/css/admin.css', array(), self::VERSION );
	}

	/**
	 *
	 *
	 *
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @param object $term
	 * @param string $taxonomy
	 *
	 * @return void
	 */
	public function render_custom_term_fields( $term, $taxonomy ) {
		$prefix = self::get_tax_term_option_name( $term->term_id, $taxonomy );
		$active = (string) self::get_tax_term_option( $term->term_id, $taxonomy, 'active' );
		$pass   = (string) self::get_tax_term_option( $term->term_id, $taxonomy, 'pass' );
		$roles  = (array) self::get_tax_term_option( $term->term_id, $taxonomy, 'role_whitelist' );
		$users  = (array) self::get_tax_term_option( $term->term_id, $taxonomy, 'user_whitelist' );
		$labels = get_taxonomy_labels( get_taxonomy( $taxonomy ) );

		/**
		 * Filter the minimum allowed password length
		 *
		 * @since 1.0.0
		 *
		 * @param string $taxonomy
		 * @param object $term
		 *
		 * @return int
		 */
		$min_pass_length = apply_filters( 'wcrc_min_password_length', 3, $taxonomy, $term );
		?>
		<tr class="form-field">
			<th scope="row" valign="top">
				<label><?php _e( 'Restrict Access', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $prefix . 'active' ) ?>" value="no">
				<input type="checkbox" name="<?php echo esc_attr( $prefix ) ?>active" id="<?php echo esc_attr( $prefix ) ?>active" class="wcrc-active-option" value="yes" <?php checked( 'yes', $active ) ?>>
				<label for="<?php echo esc_attr( $prefix ) ?>active"><?php _e( 'Enabled', 'woocommerce-restrict-categories' ) ?></label>
			</td>
		</tr>

		<tr class="form-field wcrc-field hidden">
			<th scope="row" valign="top">
				<label for="<?php echo esc_attr( $prefix . 'pass' ) ?>"><?php _e( 'Password', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<input type="text" name="<?php echo esc_attr( $prefix . 'pass' ) ?>" id="<?php echo esc_attr( $prefix . 'pass' ) ?>" class="regular-text code wcrc-pass-option" pattern=".{<?php echo absint( $min_pass_length ) ?>,}" title="<?php printf( _n( 'Must be at least 1 character.', 'Must be at least %d characters.', absint( $min_pass_length ), 'woocommerce-restrict-categories' ), absint( $min_pass_length ) ) ?>" value="<?php echo esc_attr( $pass ) ?>" autocomplete="off">
				<p class="description"><?php printf( __( 'Visitors will be required to enter a password to view this %s archive and the products within it.', 'woocommerce-restrict-categories' ), esc_html( $labels->singular_name ) ) ?></p>
			</td>
		</tr>

		<tr class="form-field wcrc-field hidden">
			<th scope="row" valign="top">
				<label><?php _e( 'Role Whitelist', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<p class="description"><?php _e( 'Select roles that should always have access without requiring the password.', 'woocommerce-restrict-categories' ) ?></p>
				<br>
				<div id="<?php echo esc_attr( $prefix . 'role_whitelist' ) ?>">
					<fieldset>
						<input type="hidden" name="<?php echo esc_attr( $prefix . 'role_whitelist[]' ) ?>" value="">
						<?php foreach ( self::get_role_labels() as $role => $label ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $prefix . 'role_whitelist[]' ) ?>" value="<?php echo esc_attr( $role ) ?>" <?php checked( in_array( $role, $roles ) ) ?>>
								<span><?php echo esc_html( $label ) ?></span>
							</label>
							<br>
						<?php endforeach; ?>
					</fieldset>
				</div>
			</td>
		</tr>

		<tr class="form-field wcrc-field hidden">
			<th scope="row" valign="top">
				<label><?php _e( 'User Whitelist', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<p class="description"><?php _e( 'List registered users that should always have access without requiring the password.', 'woocommerce-restrict-categories' ) ?></p>
				<br>
				<div class="tablenav top">
					<input type="hidden" id="wcrc-user-whitelist-select" class="wcrc-select2">
					<input type="button" class="button" id="wcrc-user-whitelist-add" value="<?php esc_attr_e( 'Add To Whitelist', 'woocommerce-restrict-categories' ) ?>" disabled="disabled">
				</div>
				<table id="wcrc-user-whitelist-table" class="wp-list-table widefat fixed wcrc-user-table">
					<thead>
						<tr>
							<th scope="col" class="manage-column check-column"><input class="cb-select" type="checkbox" disabled=""></th>
							<th scope="col" class="manage-column wcrc-name-manage-column"><?php _e( 'User', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-role-manage-column"><?php _e( 'Role', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-email-manage-column"><?php _e( 'E-mail', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-orders-manage-column"><?php _e( 'Orders', 'woocommerce-restrict-categories' ) ?></th>
						</tr>
					</thead>
					<tfoot>
						<tr>
							<th scope="col" class="manage-column check-column"><input class="cb-select" type="checkbox" disabled=""></th>
							<th scope="col" class="manage-column wcrc-name-manage-column"><?php _e( 'User', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-role-manage-column"><?php _e( 'Role', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-email-manage-column"><?php _e( 'E-mail', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-orders-manage-column"><?php _e( 'Orders', 'woocommerce-restrict-categories' ) ?></th>
						</tr>
					</tfoot>
					<tbody>
						<tr class="wcrc-no-items hidden">
							<td class="colspanchange" colspan="5">
								<?php _e( 'No users have been whitelisted.', 'woocommerce-restrict-categories' ) ?></a>
							</td>
						</tr>
						<tr class="wcrc-helper hidden">
							<th scope="row" class="check-column">
								<input class="cb-select" type="checkbox">
								<input type="hidden" name="<?php echo esc_attr( $prefix . 'user_whitelist[]' ) ?>" class="wcrc-user-id" value="">
							</th>
							<td class="wcrc-name-column"><span></span></td>
							<td class="wcrc-role-column"></td>
							<td class="wcrc-email-column"></td>
							<td class="wcrc-orders-column"></td>
						</tr>
						<?php foreach ( $users as $user_id ) : ?>
							<?php
							$user = get_user_by( 'id', $user_id );

							if ( ! $user ) {
								continue;
							}

							$roles       = self::get_role_labels();
							$role        = ! empty( $roles[ $user->roles[0] ] ) ? $roles[ $user->roles[0] ] : null;
							$email       = ! empty( $user->user_email ) ? $user->user_email : null;
							$orders      = get_user_meta( $user->ID, '_order_count', true );
							$orders_url  = add_query_arg(
								array(
									'post_type'      => 'shop_order',
									'_customer_user' => $user->ID,
								),
								admin_url( 'edit.php' )
							);
							$taxonomy    = ! empty( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : null;
							$term_id     = ! empty( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : null;
							?>
							<tr>
								<th scope="row" class="check-column">
									<input class="cb-select" type="checkbox">
									<input type="hidden" name="<?php echo esc_attr( $prefix . 'user_whitelist[]' ) ?>" class="wcrc-user-id" value="<?php echo absint( $user->ID ) ?>">
								</th>
								<td class="wcrc-name-column"><a href="<?php echo get_edit_user_link( $user->ID ) ?>"><span><?php echo get_avatar( $user->ID, 24 ) ?> <?php echo esc_html( $user->display_name ) ?></span></a></td>
								<td class="wcrc-role-column"><?php echo esc_html( $role ) ?></td>
								<td class="wcrc-email-column"><?php echo esc_html( $email ) ?></td>
								<td class="wcrc-orders-column"><a href="<?php echo esc_url( $orders_url ) ?>"><?php echo absint( $orders ) ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="tablenav bottom">
					<input type="button" class="button" id="wcrc-user-whitelist-remove-selected" value="<?php esc_attr_e( 'Remove Selected', 'woocommerce-restrict-categories' ) ?>" disabled="disabled">
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 *
	 *
	 *
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @param int    $term_id
	 * @param int    $taxonomy_term_id
	 * @param string $taxonomy
	 *
	 * @return void
	 */
	public function save_custom_term_fields( $term_id, $taxonomy_term_id, $taxonomy ) {
		if (
			! in_array( $taxonomy, self::$taxonomies )
			||
			empty( $_POST )
		) {
			return;
		}

		$prefix = self::get_tax_term_option_name( $term_id, $taxonomy );

		foreach ( (array) $_POST as $option => $value ) {
			if ( 0 !== strpos( $option, $prefix ) ) {
				continue;
			}

			// Sanitize strings
			if ( is_string( $value ) ) {
				$value = sanitize_text_field( $value );
			}

			// Sanitize arrays
			if ( is_array( $value ) ) {
				// Remove empty items in arrays
				$value = array_values( array_filter( $value ) );

				if ( $prefix . 'user_whitelist' === $option ) {
					$value = self::sort_user_ids( $value );
					$value = array_map( 'absint', $value );
				} else {
					$value = array_map( 'sanitize_text_field', $value );
				}
			}

			update_option( $option, $value );

			if ( $prefix . 'active' === $option ) {
				$tax_option = self::PREFIX . sanitize_key( $taxonomy );
				$terms      = (array) get_option( $tax_option );

				if ( 'yes' === $value ) {
					$terms[] = absint( $term_id );
					$terms   = array_values( array_filter( array_unique( $terms ) ) );
				} elseif ( false !== ( $key = array_search( $term_id, $terms ) ) ) {
					unset( $terms[ $key ] );
				}

				sort( $terms );

				update_option( $tax_option, $terms );
			}
		}
	}

	/**
	 *
	 *
	 * @action wp_ajax_wcrc_search_users
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_search_users() {
		$users = array_filter(
			get_users(),
			function ( $user ) {
				if ( ! isset( $_GET['q'] ) ) {
					return false;
				}

				$q = mb_strtolower( trim( $_GET['q'] ) );

				// Search these user fields, in order of priority
				$display_name = mb_strtolower( $user->display_name );
				$user_login   = mb_strtolower( $user->user_login );
				$roles        = mb_strtolower( implode( ', ', $user->roles ) );
				$user_email   = mb_strtolower( $user->user_email );

				return ( false !== mb_strpos( $display_name, $q ) || false !== mb_strpos( $user_login, $q ) || false !== mb_strpos( $roles, $q ) || false !== mb_strpos( $user_email, $q ) );
			}
		);

		$users = array_map(
			function( $user ) {
				return array(
					'id'   => $user->ID,
					'text' => $user->display_name,
				);
			},
			$users
		);

		/**
		 * Filter the maximum number of users to return during an ajax search
		 *
		 * @since 1.0.0
		 *
		 * @return int
		 */
		$max_users = absint( apply_filters( 'wcrc_ajax_search_users_max', 50 ) );

		if ( count( $users ) > $max_users ) {
			$users = array_slice( $users, 0, $max_users );
		}

		echo json_encode( array_values( $users ) );

		die();
	}

	/**
	 *
	 *
	 * @action wp_ajax_wcrc_add_user
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_add_user() {
		$tax_slug = ! empty( $_GET['taxonomy'] ) ? $_GET['taxonomy'] : null;
		$term_id  = ! empty( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : null;

		if ( ! taxonomy_exists( $tax_slug ) || ! term_exists( $term_id, $tax_slug ) ) {
			die();
		}

		$user_id  = ! empty( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : null;
		$user     = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			die();
		}

		$roles = self::get_role_labels();

		$data = array(
			'user_id' => $user->ID,
			'avatar'  => get_avatar( $user->ID, 24 ),
			'name'    => $user->display_name,
			'role'    => ! empty( $roles[ $user->roles[0] ] ) ? $roles[ $user->roles[0] ] : __( 'N/A', 'woocommerce-restrict-categories' ),
			'email'   => $user->user_email,
			'orders'  => absint( get_user_meta( $user->ID, '_order_count', true ) ),
		);

		echo json_encode( $data );

		die();
	}

	/**
	 *
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_restrict_tax_term_archive() {
		if ( ! is_tax() ) {
			return;
		}

		$taxonomy = get_query_var( 'taxonomy' );

		if ( ! in_array( $taxonomy, self::$taxonomies ) ) {
			return;
		}

		$term_slug = get_query_var( $taxonomy );

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
	 *
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_restrict_product() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		foreach ( self::$taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( get_queried_object_id(), $taxonomy );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$terms      = wp_list_pluck( $terms, 'term_id' );
			$restricted = (array) get_option( self::PREFIX . $taxonomy );
			$intersect  = array_intersect( $terms, $restricted );

			if ( ! empty( $intersect[0] ) ) {
				self::password_notice( $intersect[0], $taxonomy );
			}
		}
	}

	/**
	 *
	 *
	 *
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @return void
	 */
	public static function password_notice( $term_id, $taxonomy ) {
		if (
			self::has_whitelisted_role( $term_id, $taxonomy )
			||
			self::is_whitelisted_user( $term_id, $taxonomy )
		) {
			/**
			 * Fires after a whitelisted user has been granted access
			 *
			 * @param WP_User $user
			 * @param string  $taxonomy
			 * @param int     $term_id
			 */
			do_action( 'wcrc_whitelist_access_granted', wp_get_current_user(), $taxonomy, $term_id );

			return;
		}

		$tax_object = get_taxonomy( $taxonomy );
		$tax_labels = get_taxonomy_labels( $tax_object );
		$self_url   = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		ob_start();
		?>
		<div style="text-align:center;">
			<h1 style="border:none;"><?php printf( __( 'This is a Restricted %s', 'woocommerce-restrict-categories' ), esc_html( $tax_labels->singular_name ) ) ?></h1>
			<p><?php _e( 'Please enter the password to unlock:', 'woocommerce-restrict-categories' ) ?></p>
			<form method="post" action="<?php echo esc_url( $self_url ) ?>">
				<p><input type="password" size="30" style="padding:3px 5px;font-size:16px;text-align:center;"></p>
				<p>
					<?php wp_nonce_field( sprintf( 'wcrc_pass_nonce-%d', absint( $term_id ) ), 'wcrc_pass_nonce' ) ?>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Continue', 'woocommerce-restrict-categories' ) ?>">
				</p>
			</form>
		</div>
		<?php
		$message = ob_get_clean();

		wp_die( $message, sprintf( __( 'This is a Restricted %s', 'woocommerce-restrict-categories' ), esc_html( $tax_labels->singular_name ) ) );
	}

	/**
	 * Sort an array of user IDs by any WP_User field
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param array  $users
	 * @param string $orderby (optional)
	 * @param string $order (optional)
	 *
	 * @return array
	 */
	public static function sort_user_ids( $users, $orderby = 'display_name', $order = 'ASC' ) {
		$sort = array();

		foreach ( (array) $users as $user_id ) {
			$user   = get_user_by( 'id', $user_id );
			$sort[] = ! empty( $user->$orderby ) ? $user->$orderby : null;
		}

		$order = ( 'DESC' === $order ) ? SORT_DESC : SORT_ASC;

		array_multisort( $sort, $order, $users );

		return (array) $users;
	}

	/**
	 *
	 *
	 * @access public
	 * @static
	 * @since 1.0.0
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
		$whitelist = (array) self::get_tax_term_option( $term_id, $taxonomy, 'role_whitelist' );
		$roles     = isset( $user->roles ) ? (array) $user->roles : array();
		$intersect = array_intersect( $roles, $whitelist );

		return ! empty( $intersect );
	}

	/**
	 *
	 *
	 * @access public
	 * @static
	 * @since 1.0.0
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

		$whitelist = (array) self::get_tax_term_option( $term_id, $taxonomy, 'user_whitelist' );

		return in_array( get_current_user_id(), $whitelist );
	}

	/**
	 * Return a unique option name relative to a tax/term context
	 *
	 * @access public
	 * @static
	 * @since 1.0.0
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @param string $option (optional)
	 *
	 * @return string
	 */
	public static function get_tax_term_option_name( $term_id, $taxonomy, $option = null ) {
		return sprintf( '%s%s_%d-%s', self::PREFIX, sanitize_key( $taxonomy ), absint( $term_id ), sanitize_key( $option ) );
	}

	/**
	 * Return an option value relative to a tax/term context
	 *
	 * @access public
	 * @static
	 * @since 1.0.0
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @param string $option
	 * @param mixed  $default (optional)
	 *
	 * @return string
	 */
	public static function get_tax_term_option( $term_id, $taxonomy, $option, $default = false ) {
		$option_name = self::get_tax_term_option_name( $term_id, $taxonomy, $option );

		return get_option( $option_name, $default );
	}

	/**
	 * Get an array of user roles
	 *
	 * @access public
	 * @static
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_role_labels() {
		$roles  = array();
		$_roles = new WP_Roles();

		foreach ( $_roles->get_names() as $role => $label ) {
			$roles[ $role ] = translate_user_role( $label );
		}

		return (array) $roles;
	}

}

/**
 * Instantiate the plugin instance
 *
 * @global WC_Restrict_Categories
 */
$GLOBALS['wc_restrict_categories'] = WC_Restrict_Categories::get_instance();
