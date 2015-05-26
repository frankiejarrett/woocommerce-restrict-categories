/* globals ajaxurl, select2 */
jQuery( document ).ready( function( $ ) {

	var $active    = $( '.wcrc-active-option' ),
	    $pass      = $( '.wcrc-pass-option' ),
	    $rows      = $( '.wcrc-field' ),
	    $select    = $( '#wcrc-user-whitelist-select' ),
	    $addBtn    = $( '#wcrc-user-whitelist-add' ),
	    $removeBtn = $( '#wcrc-user-whitelist-remove-selected' ),
	    $table     = $( '#wcrc-user-whitelist-table' ),
	    $tbody     = $( 'tbody', $table ),
	    $noneFound = $( 'tr.wcrc-no-items', $tbody );

	function setActiveStatus() {
		if ( $active.is( ':checked' ) ) {
			$rows.show();
			$pass.prop( 'required', true );
		} else {
			$rows.hide();
			$pass.prop( 'required', false );
		}

		calcColSpan();
	}

	setActiveStatus();

	$active.change( function() {
		setActiveStatus();
	});

	// WooCommerce has poor scope when targeting elements and is making this table sortable
	if ( 'function' === typeof sortable ) {
		$table.sortable({
			disabled: true
		});
	}

	// select2 init
	$select.select2({
		placeholder: 'Select user',
		allowClear: true,
		minimumInputLength: 3,
		ajax: {
			url: ajaxurl,
			dataType: 'json',
			delay: 250,
			data: function( term ) {
				return {
					action: 'wcrc_search_users',
					q: term
				};
			},
			results: function( data ) {
				var users = getUsers().map( Number );

				data.forEach( function( result ) {
					if ( -1 !== $.inArray( result.id, users ) ) {
						result.disabled = true;
					}
				});

				return { results: data };
			},
			cache: true
		}
	});

	// Select user
	$select.on( 'select2-selecting', function( e ) {
		$addBtn.prop( 'disabled', false );
	});

	// Clear user selection
	$select.on( 'select2-removed', function( e ) {
		$addBtn.prop( 'disabled', true );
	});

	// Add user
	$addBtn.on( 'click', function( e ) {
		e.preventDefault();

		data = {
		    action: 'wcrc_add_user',
		    user_id: parseInt( $select.val(), 10 ),
		    taxonomy: getQueryVar( 'taxonomy' ),
		    term_id: parseInt( getQueryVar( 'tag_ID' ), 10 )
		};

		$.get(
			ajaxurl,
			data,
			function( response ) {
				response = JSON.parse( response );

				var users = getUsers().map( Number );

				if ( -1 !== $.inArray( response.user_id, users ) ) {
					return false;
				}

				var $helper = $( 'tr.wcrc-helper', $tbody ),
				    $newRow = $helper.clone();

				$newRow.removeAttr( 'class' );
				$( 'th input.wcrc-user-id', $newRow ).val( response.user_id );
				$( 'td.wcrc-name-column span', $newRow ).html( response.avatar + ' ' + response.name );
				$( 'td.wcrc-role-column', $newRow ).text( response.role );
				$( 'td.wcrc-email-column', $newRow ).text( response.email );
				$( 'td.wcrc-orders-column', $newRow ).text( response.orders );

				$helper.after( $newRow );

				calcUsersFound();
				calcUsersSelected();

				$select.select2( 'val', '' );
				$addBtn.prop( 'disabled', true );
			}
		);
	});

	// Select row
	$table.on( 'click', 'input.cb-select', function() {
		calcUsersSelected();
	});

	// Remove selected rows
	$removeBtn.on( 'click', function( e ) {
		e.preventDefault();

		var $selectedRows = $( 'input.cb-select:checked', $tbody ).closest( 'tr' );

		$selectedRows.remove();

		$( 'input.cb-select', $table ).prop( 'checked', false );

		calcUsersFound();
		calcUsersSelected();
	});

	function calcUsersFound() {
		var $rows      = $( 'tr:not( .hidden )', $tbody ),
			$selectAll = $( '.check-column.manage-column input.cb-select', $table );

		if ( 0 === $rows.length ) {
			$noneFound.show();
			$selectAll.prop( 'disabled', true );
			$removeBtn.prop( 'disabled', true );
		} else {
			$noneFound.hide();
			$selectAll.prop( 'disabled', false );
		}

		regenerateAltRows( $rows );
	}

	calcUsersFound();

	function calcUsersSelected() {
		var $selected = $( 'tr:not( .hidden ) input.cb-select:checked', $tbody );

		if ( 0 === $selected.length ) {
			$removeBtn.prop( 'disabled', true );
		} else {
			$removeBtn.prop( 'disabled', false );
		}
	}

	calcUsersSelected();

	function calcColSpan() {
		var colspan = $( 'thead th:visible', $table ).length;

		$( 'td.colspanchange', $noneFound ).prop( 'colspan', colspan );
	}

	calcColSpan();

	$( window ).resize( function() {
		calcColSpan();
	});

	function getUsers() {
		var $rows = $( 'tr:not( .hidden )', $tbody ),
		    users = [];

		$rows.each( function( index ) {
			var user_id = $( 'th input.wcrc-user-id', $( this ) ).val();

			if ( user_id ) {
				users.push( user_id );
			}
		});

		return users;
	}

	function regenerateAltRows( $rows ) {
		if ( ! $rows.length ) {
			return false;
		}

		$rows.removeClass( 'alternate' );

		$rows.each( function( index ) {
			$( this ).addClass( index % 2 ? '' : 'alternate' );
		});
	};

	function getQueryVar( query_var ) {
		var query = window.location.search.substring( 1 ),
		    vars  = query.split( '&' );

		for ( var i = 0; i < vars.length; i++ ) {
			var pair = vars[i].split( '=' );

			if ( pair[0] === query_var ) {
				return pair[1];
			}
		}

		return false;
	}

});
