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
	 * Plugin version number
	 *
	 * @const string
	 */
	const VERSION = '1.0.0';

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

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		add_action( 'product_cat_edit_form_fields', array( $this, 'edit_category_fields' ), 11, 2 );

		add_action( 'edit_term', array( $this, 'save_category_fields' ), 10, 3 );

		add_action( 'template_redirect', array( $this, 'maybe_restrict_category' ) );
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

		if ( ! isset( $screen->id ) || 'edit-product_cat' !== $screen->id ) {
			return;
		}

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
	public function edit_category_fields( $term, $taxonomy ) {
		$option  = sprintf( 'wcrc_%s_%d', sanitize_key( $taxonomy ), absint( $term->term_id ) );
		$options = (array) get_option( $option );
		$active  = ! empty( $options['active'] ) ? (string) $options['active'] : 'no';
		$pass    = ! empty( $options['pass'] ) ? (string) $options['pass'] : null;
		$roles   = ! empty( $options['role_whitelist'] ) ? (array) $options['role_whitelist'] : array();
		$users   = ! empty( $options['user_whitelist'] ) ? (array) $options['user_whitelist'] : array();
		?>
		<tr class="form-field">
			<th scope="row" valign="top">
				<label><?php _e( 'Restrict Category', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<input type="checkbox" name="<?php echo esc_attr( $option ) ?>[active]" id="<?php echo esc_attr( $option ) ?>[active]" value="yes" <?php checked( 'yes', $active ) ?>>
				<label for="<?php echo esc_attr( $option ) ?>[active]">Enabled</label>
			</td>
		</tr>

		<tr class="form-field wcrc-field">
			<th scope="row" valign="top">
				<label for="<?php echo esc_attr( $option ) ?>[pass]"><?php _e( 'Category Password', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<input type="text" name="<?php echo esc_attr( $option ) ?>[pass]" id="<?php echo esc_attr( $option ) ?>[pass]" class="regular-text code" value="<?php echo esc_attr( $pass ) ?>" autocomplete="off">
				<p class="description">Users will be required to enter this password in order to view this category and the products within it.</p>
			</td>
		</tr>

		<tr class="form-field wcrc-field">
			<th scope="row" valign="top">
				<label><?php _e( 'Role Whitelist', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<p class="description">Grant access to certain user roles automatically without requiring the category password.</p>
				<br>
				<div id="<?php echo esc_attr( $option ) ?>[role_whitelist]">
					<fieldset>
						<?php foreach ( self::get_roles() as $role => $label ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ) ?>[role_whitelist][]" value="<?php echo esc_attr( $role ) ?>" <?php checked( in_array( $role, $roles ) ) ?>>
								<span><?php echo esc_html( $label ) ?></span>
							</label>
							<br>
						<?php endforeach; ?>
					</fieldset>
				</div>
			</td>
		</tr>

		<tr class="form-field wcrc-field">
			<th scope="row" valign="top">
				<label><?php _e( 'User Whitelist', 'woocommerce-restrict-categories' ) ?></label>
			</th>
			<td>
				<p class="description">Grant access to certain registered users automatically without requiring the category password.</p>
				<br>
				<div class="tablenav top"><input type="button" class="button" id="exclude_rules_new_rule" value="+ Add User"></div>
				<table class="wp-list-table widefat fixed wcrc-user-table">
					<thead>
						<tr>
							<th scope="col" class="manage-column check-column"><input class="cb-select" type="checkbox" disabled=""></th>
							<th scope="col" class="manage-column"><?php _e( 'User', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column"><?php _e( 'Role', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column"><?php _e( 'Viewed', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column"><?php _e( 'Last Viewed', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-actions-column"><span class="hidden"><?php _e( 'Action', 'woocommerce-restrict-categories' ) ?></span></th>
						</tr>
					</thead>
					<tfoot>
						<tr>
							<th scope="col" class="manage-column check-column"><input class="cb-select" type="checkbox" disabled=""></th>
							<th scope="col" class="manage-column"><?php _e( 'User', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column"><?php _e( 'Role', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column"><?php _e( 'Viewed', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column"><?php _e( 'Last Viewed', 'woocommerce-restrict-categories' ) ?></th>
							<th scope="col" class="manage-column wcrc-actions-column"><span class="hidden"><?php _e( 'Action', 'woocommerce-restrict-categories' ) ?></span></th>
						</tr>
					</tfoot>
					<tbody>
						<tr class="wcrc-no-items hidden" style="display: table-row;">
							<td class="colspanchange" colspan="6">
								<?php _e( 'No users have been whitelisted.', 'woocommerce-restrict-categories' ) ?></a>
							</td>
						</tr>
						<tr class=" hidden helper">
							<th scope="row" class="check-column">
								<input class="cb-select" type="checkbox">
								<input type="hidden" name="<?php echo esc_attr( $option ) ?>[user_whitelist][]" value="">
							</th>
							<td></td>
							<td></td>
							<td></td>
							<th scope="row" class="wcrc-actions-column">
								<a href="#" class="wcrc-user-whitelist-remove-row"><?php _e( 'Delete', 'woocommerce-restrict-categories' ) ?></a>
							</th>
						</tr>
					</tbody>
				</table>
				<div class="tablenav bottom">
					<input type="button" class="button" id="wcrc-user-whitelist-remove-selected" value="Remove Selected Users" disabled="disabled">
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
	public function save_category_fields( $term_id, $taxonomy_term_id, $taxonomy ) {
		$tax_option  = sprintf( 'wcrc_%s', sanitize_key( $taxonomy ) );
		$term_option = sprintf( '%s_%d', $tax_option, absint( $term_id ) );

		if ( 'product_cat' === $taxonomy && isset( $_POST[ $term_option ] ) ) {
			update_option( $term_option, (array) $_POST[ $term_option ] );

			$values = (array) get_option( $tax_option );

			if ( isset( $_POST[ $term_option ]['active'] ) ) {
				$values[] = absint( $term_id );
			} else {
				$key = array_search( $term_id, $values );

				if ( ! empty( $key ) ) {
					unset( $values[ $key ] );
				}
			}

			$values = array_filter( array_unique( $values ) );

			update_option( $tax_option, $values );
		}
	}

	/**
	 *
	 *
	 *
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_restrict_category() {
		if ( ! is_tax( 'product_cat' ) && ! is_singular( 'product' ) ) {
			return;
		}

		if ( is_tax( 'product_cat' ) ) {
			$options   = (array) get_option( sprintf( 'wcrc_product_cat_%d', get_queried_object_id() ) );
			$is_active = ( isset( $options['active'] ) && 'yes' === $options['active'] );
			$role_list = isset( $options['role_whitelist'] ) ? (array) $options['role_whitelist'] : array();
			$user_list = isset( $options['user_whitelist'] ) ? (array) $options['user_whitelist'] : array();

			if (
				! $is_active
				||
				self::has_whitelisted_role( $role_list )
				||
				self::is_whitelisted_user( $user_list )
			) {
				return;
			}
		}

		if ( is_singular( 'product' ) ) {
			$options   = (array) get_option( 'wcrc_product_cat' );
			$cats      = wp_get_post_terms( get_queried_object_id(), 'product_cat' );
			$cats      = wp_list_pluck( $cats, 'term_id' );
			$intersect = array_intersect( $cats, $options );

			if ( empty( $intersect ) ) {
				return;
			}

			$options   = (array) get_option( sprintf( 'wcrc_product_cat_%d', absint( $intersect[0] ) ) );
			$role_list = isset( $options['role_whitelist'] ) ? (array) $options['role_whitelist'] : array();
			$user_list = isset( $options['user_whitelist'] ) ? (array) $options['user_whitelist'] : array();

			if (
				self::has_whitelisted_role( $role_list )
				||
				self::is_whitelisted_user( $user_list )
			) {
				return;
			}
		}

		ob_start();
		?>
		<div style="text-align:center;">
			<h1 style="border:none;">This is a Restricted Category</h1>
			<p>Please enter the password to unlock:</p>
			<form method="post" action="/">
				<p><input type="password" size="30" style="padding:3px 5px;font-size:16px;text-align:center;"></p>
				<p>
					<?php wp_nonce_field( sprintf( 'wcrc_pass_nonce-%d', get_queried_object_id() ), 'wcrc_pass_nonce' ) ?>
					<input type="submit" class="button" value="Continue">
				</p>
			</form>
		</div>
		<?php
		$message = ob_get_clean();

		wp_die( $message, 'This is a Restricted Category' );
	}

	/**
	 *
	 *
	 * @access public
	 * @static
	 * @since 1.0.0
	 *
	 * @param array $whitelist
	 *
	 * @return bool
	 */
	public static function has_whitelisted_role( $whitelist ) {
		$user      = wp_get_current_user();
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
	 * @param array $whitelist
	 *
	 * @return bool
	 */
	public static function is_whitelisted_user( $whitelist ) {
		return in_array( get_current_user_id(), (array) $whitelist );
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
	public static function get_roles() {
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
