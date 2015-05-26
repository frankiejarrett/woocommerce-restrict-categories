<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Restrict_Categories_Ajax {

	/**
	 * Class constructor
	 *
	 * @access public
	 */
	public function __construct() {
		// Ajax callback for searching users by keyword
		add_action( 'wp_ajax_wcrc_search_users', array( $this, 'search_users' ) );

		// Ajax callback for adding a new user to the whitelist
		add_action( 'wp_ajax_wcrc_add_user', array( $this, 'add_user' ) );
	}

	/**
	 * Ajax callback for searching users by keyword
	 *
	 * @action wp_ajax_wcrc_search_users
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function search_users() {
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
	 * Ajax callback for adding a new user to the whitelist
	 *
	 * @action wp_ajax_wcrc_add_user
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_user() {
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

		$roles = WC_Restrict_Categories::get_role_labels();

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

}

new WC_Restrict_Categories_Ajax();
