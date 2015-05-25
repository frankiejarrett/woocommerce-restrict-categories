/* globals ajaxurl, select2 */
jQuery( document ).ready( function( $ ) {

	var $active = $( '.wcrc-active-option' ),
	    $rows   = $( '.wcrc-field' );

	if ( $active.is( ':checked' ) ) {
		$rows.show();
	} else {
		$rows.hide();
	}

	$active.change( function() {
		if ( this.checked ) {
			$rows.show();
		} else {
			$rows.hide();
		}
	});

	var $select    = $( '#wcrc-user-whitelist-select' ),
	    $add       = $( '#wcrc-user-whitelist-add' ),
	    $table     = $( '#wcrc-user-whitelist-table' ),
	    $tbody     = $table.find( 'tbody' ),
	    $removeBtn = $( '#wcrc-user-whitelist-remove-selected' );

	// WooCommerce has poor scope when targeting elements and is making this table sortable
	$table.sortable({
		disabled: true
	});

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
		$add.prop( 'disabled', false );
	});

	// Clear user selection
	$select.on( 'select2-removed', function( e ) {
		$add.prop( 'disabled', true );
	});

	// Add user
	$add.on( 'click', function( e ) {
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

				var $helper = $tbody.find( 'tr.wcrc-helper' ),
				    $newRow = $helper.clone();

				$newRow.removeAttr( 'class' );
				$newRow.find( 'th input.wcrc-user-id' ).val( response.user_id );
				$newRow.find( 'td.wcrc-name-column span' ).html( response.avatar + ' ' + response.name );
				$newRow.find( 'td.wcrc-role-column' ).text( response.role );
				$newRow.find( 'td.wcrc-views-column' ).text( response.views );
				$newRow.find( 'td.wcrc-last-viewed-column' ).text( response.last_viewed );

				$helper.after( $newRow );

				calcUsersFound();
				calcUsersSelected();

				$select.select2( 'val', '' );
				$add.prop( 'disabled', true );
			}
		);
	});

	// Remove row
	$tbody.on( 'click', 'tr:not( .hidden ) .wcrc-user-whitelist-remove-row', function( e ) {
		e.preventDefault();

		var $thisRow = $( this ).closest( 'tr' );

		$thisRow.remove();

		calcUsersFound();
		calcUsersSelected();
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

		$table.find( 'input.cb-select' ).prop( 'checked', false );

		calcUsersFound();
		calcUsersSelected();
	});

	function calcUsersFound() {
		var $rows      = $tbody.find( 'tr:not( .hidden )' ),
			$noneFound = $tbody.find( 'tr.wcrc-no-items' ),
			$selectAll = $table.find( '.check-column.manage-column input.cb-select' );

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
		var $selected = $tbody.find( 'tr:not( .hidden ) input.cb-select:checked' );

		if ( 0 === $selected.length ) {
			$removeBtn.prop( 'disabled', true );
		} else {
			$removeBtn.prop( 'disabled', false );
		}
	}

	calcUsersSelected();

	function getUsers() {
		var $rows = $tbody.find( 'tr:not( .hidden )' ),
		    users = [];

		$rows.each( function( index ) {
			var user_id = $( this ).find( 'th input.wcrc-user-id' ).val();

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
