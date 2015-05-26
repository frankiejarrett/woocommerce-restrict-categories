<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Restrict_Categories_Term_Meta {

	/**
	 * Class constructor
	 *
	 * @access public
	 */
	public function __construct() {
		// Render custom term fields for each approved taxonomy
		foreach ( WC_Restrict_Categories::$taxonomies as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_custom_term_fields' ), 11, 2 );
		}

		// Save custom term field values
		add_action( 'edit_term', array( $this, 'save_custom_term_fields' ), 10, 3 );
	}

	/**
	 * Render custom fields in term edit screen
	 *
	 * @action {$taxonomy}_edit_form_fields
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
		$label  = WC_Restrict_Categories::get_tax_label( $taxonomy, 'singular_name' );

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
		$min_pass_length = apply_filters( 'wcrc_min_password_length', 5, $taxonomy, $term );
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
				<p class="description"><?php printf( __( 'Visitors will be required to enter a password to view this %s archive and the products within it.', 'woocommerce-restrict-categories' ), esc_html( $label ) ) ?></p>
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
						<?php foreach ( WC_Restrict_Categories::get_role_labels() as $role => $label ) : ?>
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

							$roles       = WC_Restrict_Categories::get_role_labels();
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
	 * Save custom field values on term update
	 *
	 * @action edit_term
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
			! in_array( $taxonomy, WC_Restrict_Categories::$taxonomies )
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
				$tax_option = WC_Restrict_Categories::PREFIX . sanitize_key( $taxonomy );
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
	 * Return a unique option name relative to a tax/term context
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @param string $option (optional)
	 *
	 * @return string
	 */
	public static function get_tax_term_option_name( $term_id, $taxonomy, $option = null ) {
		return sprintf( '%s%s_%d-%s', WC_Restrict_Categories::PREFIX, sanitize_key( $taxonomy ), absint( $term_id ), sanitize_key( $option ) );
	}

	/**
	 * Return an option value relative to a tax/term context
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
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

}

new WC_Restrict_Categories_Term_Meta();
