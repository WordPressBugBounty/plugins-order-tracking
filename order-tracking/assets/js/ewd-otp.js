jQuery( document ).ready( function() {

	jQuery( '.ewd-otp-tracking-form' ).on( 'submit', function( event ) {

		if ( jQuery( this ).parent().parent().hasClass( 'ewd-otp-disable-ajax' ) ) { return; }

		event.preventDefault();
		var $form = jQuery( this );
		var $results = $form.closest( '.ewd-otp-order-tracking-form-div' ).siblings( '.ewd-otp-tracking-results' );
		if ( ! $results.length ) { $results = jQuery( '.ewd-otp-tracking-results' ).first(); }
		var $submit = $form.find( ':submit' );
		var requestNumber = ( $form.data( 'ewd-otp-request-number' ) || 0 ) + 1;
		$form.data( 'ewd-otp-request-number', requestNumber );

		var order_number = $form.find( 'input[name="ewd_otp_identifier_number"]' ).val();
		var order_email = $form.find( 'input[name="ewd_otp_form_email"]' ).val();
		$submit.prop( 'disabled', true ).attr( 'aria-busy', 'true' );
		$results.attr( 'aria-busy', 'true' );

		var params = {
			order_number: order_number,
			order_email: order_email,
			customer_notes_label: ewd_otp_php_data.customer_notes_submit,
			action: 'ewd_otp_get_order',
			nonce: ewd_otp_php_data.nonce
		};

		jQuery.post( ajaxurl, params )
			.done( function( response ) {
				if ( requestNumber !== $form.data( 'ewd-otp-request-number' ) ) { return; }
				if ( ! response || ! response.success || ! response.data || typeof response.data.output !== 'string' ) {
					$results.text( response && response.data && response.data.output ? response.data.output : ewd_otp_php_data.request_failed );
					return;
				}

				$results.html( response.data.output );
				ewd_otp_resizeimage();
				ewd_otp_enable_note_click();
			} )
			.fail( function() {
				if ( requestNumber === $form.data( 'ewd-otp-request-number' ) ) { $results.text( ewd_otp_php_data.request_failed ); }
			} )
			.always( function() {
				if ( requestNumber !== $form.data( 'ewd-otp-request-number' ) ) { return; }
				$submit.prop( 'disabled', false ).removeAttr( 'aria-busy' );
				$results.removeAttr( 'aria-busy' );
			} );
	});

	var ewd_otp_print_source = null;
	function ewd_otp_prepare_print() {
		if ( document.querySelector( 'body > .ewd-otp-print-root' ) ) { return; }
		var source = ewd_otp_print_source || jQuery( '.ewd-otp-tracking-results:visible' ).has( '.ewd-otp-order-results' ).first()[0];
		if ( ! source ) { return; }
		var print_root = source.cloneNode( true );
		print_root.classList.add( 'ewd-otp-print-root' );
		document.body.appendChild( print_root );
	}
	function ewd_otp_finish_print() {
		jQuery( 'body > .ewd-otp-print-root' ).remove();
		ewd_otp_print_source = null;
	}
	window.addEventListener( 'beforeprint', ewd_otp_prepare_print );
	window.addEventListener( 'afterprint', ewd_otp_finish_print );
	jQuery( document ).on( 'click', '.ewd-otp-print-results', function() {
		ewd_otp_print_source = jQuery( this ).closest( '.ewd-otp-tracking-results' )[0];
		ewd_otp_prepare_print();
		window.print();
	});

    ewd_otp_enable_note_click();

});

function ewd_otp_results_for( $form ) {
	return $form.closest( '.ewd-otp-form' ).find( '.ewd-otp-tracking-results' ).first();
}

function ewd_otp_begin_request( $owner, $results ) {
	var requestNumber = ( $owner.data( 'ewd-otp-request-number' ) || 0 ) + 1;
	$owner.data( 'ewd-otp-request-number', requestNumber );
	$owner.find( ':submit' ).prop( 'disabled', true ).attr( 'aria-busy', 'true' );
	$results.attr( 'aria-busy', 'true' ).html( '<h3>' + ewd_otp_php_data.retrieving_results + '</h3>' );
	return requestNumber;
}

function ewd_otp_ajax_request( $owner, $results, requestNumber, params, successCallback ) {
	jQuery.post( ajaxurl, params )
		.done( function( response ) {
			if ( requestNumber !== $owner.data( 'ewd-otp-request-number' ) ) { return; }
			if ( ! response || ! response.success || ! response.data || typeof response.data.output !== 'string' ) {
				$results.text( response && response.data && response.data.output ? response.data.output : ewd_otp_php_data.request_failed );
				return;
			}
			$results.html( response.data.output );
			if ( successCallback ) { successCallback(); }
		} )
		.fail( function() {
			if ( requestNumber === $owner.data( 'ewd-otp-request-number' ) ) { $results.text( ewd_otp_php_data.request_failed ); }
		} )
		.always( function() {
			if ( requestNumber !== $owner.data( 'ewd-otp-request-number' ) ) { return; }
			$owner.find( ':submit' ).prop( 'disabled', false ).removeAttr( 'aria-busy' );
			$results.removeAttr( 'aria-busy' );
		} );
}

function ewd_otp_enable_note_click() {

	jQuery( '#ewd-otp-customer-notes-form' ).off( 'submit.ewdotp' ).on( 'submit.ewdotp', function( event ) {

		event.preventDefault();
		var $form = jQuery( this );
		var $notes = $form.closest( '#ewd-otp-customer-notes' );
		var $submit = $form.find( 'input[name="ewd_otp_customer_notes_submit"]' );
		var requestNumber = ( $form.data( 'ewd-otp-request-number' ) || 0 ) + 1;
		$form.data( 'ewd-otp-request-number', requestNumber );
		$submit.prop( 'disabled', true ).attr( 'aria-busy', 'true' );
		
		var order_number = $form.find( 'input[name="ewd_otp_order_number"]' ).val();
		var order_id = $form.find( 'input[name="ewd_otp_order_id"]' ).val();
		var customer_notes = $form.find( 'textarea[name="ewd_otp_customer_notes"]' ).val();
		var order_email = $form.find( 'input[name="ewd_otp_order_email_proof"]' ).val();
		var tracking_token = $form.find( 'input[name="ewd_otp_tracking_token"]' ).val();
		var collection_token = $form.find( 'input[name="ewd_otp_collection_token"]' ).val();

		var params = {
			order_number: order_number,
			order_id: order_id,
			customer_notes: customer_notes,
			order_email: order_email,
			tracking_token: tracking_token,
			collection_token: collection_token,
			action: 'ewd_otp_update_customer_note',
			nonce: ewd_otp_php_data.nonce
		};

		jQuery.post( ajaxurl, params )
			.done( function( response ) {
				if ( requestNumber !== $form.data( 'ewd-otp-request-number' ) ) { return; }
				var message = response && response.data && response.data.output ? response.data.output : ewd_otp_php_data.request_failed;
				jQuery( '<div class="ewd-otp-customer-note-response"></div>' ).text( message ).prependTo( $notes ).delay( 3000 ).fadeOut( 400 );
				if ( response && response.success ) {
					$form.closest( '.ewd-otp-order-results' ).find( '.ewd-otp-customer-notes-value' ).first().text( customer_notes );
			}
			} )
			.fail( function() {
				if ( requestNumber !== $form.data( 'ewd-otp-request-number' ) ) { return; }
				jQuery( '<div class="ewd-otp-customer-note-response"></div>' ).text( ewd_otp_php_data.request_failed ).prependTo( $notes ).delay( 3000 ).fadeOut( 400 );
			} )
			.always( function() {
				if ( requestNumber !== $form.data( 'ewd-otp-request-number' ) ) { return; }
				$submit.prop( 'disabled', false ).removeAttr( 'aria-busy' );
		});

		return false;
	});
}

function ewd_otp_resizeimage() {

	var graphic_div = jQuery( '.ewd-otp-tracking-graphic' );

	if ( jQuery( '.ewd-otp-empty-display > img' ).length ) {

		var img_empty = jQuery( '.ewd-otp-empty-display > img' );
		var img_full = jQuery( '.ewd-otp-full-display > img' );

		img_full.width( img_empty.width() );

		if ( jQuery( window ).width() > 600 ) { var div_height = Math.max( img_empty.height(), 150 ); }

		jQuery( '.ewd-otp-tracking-graphic' ).height( div_height );
	}
} 
jQuery( window ).resize( ewd_otp_resizeimage );
jQuery( document ).ready( ewd_otp_resizeimage );
