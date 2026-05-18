jQuery(document).ready(function() {

    if ( ! jQuery.isFunction( jQuery.fn.spectrum ) ) { return; }

    jQuery('.sap-spectrum').spectrum({
        showInput: true,
        showInitial: true,
        preferredFormat: "hex",
        allowEmpty: true
    });

    jQuery('.sap-spectrum').css('display', 'inline');

    jQuery('.sap-spectrum').on('change', function() {
        if (jQuery(this).val() != "") {
            jQuery(this).css('background', jQuery(this).val());
            var rgb = EWD_SAP_hexToRgb(jQuery(this).val());
            var Brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
            if (Brightness < 100) {jQuery(this).css('color', '#ffffff');}
            else {jQuery(this).css('color', '#000000');}
        }
        else {
            jQuery(this).css('background', 'none');
        }
    });

    jQuery('.sap-spectrum').each(function() {
        if (jQuery(this).val() != "") {
            jQuery(this).css('background', jQuery(this).val());
            var rgb = EWD_SAP_hexToRgb(jQuery(this).val());
            var Brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
            if (Brightness < 100) {jQuery(this).css('color', '#ffffff');}
            else {jQuery(this).css('color', '#000000');}
        }
    });

    jQuery( 'fieldset[data-conditional_on]' ).each( function() {
        
        var option = jQuery( this );
        var option_page = jQuery( 'input[name="option_page"]' ).val();

        var conditional_on = option.data( 'conditional_on' ); console.log( conditional_on );
        var conditional_on_value = option.data( 'conditional_on_value' );

        jQuery.each( conditional_on, function ( index, field ) {

            var option_name = option_page + '[' + field + ']';

            jQuery( '[name="' + option_name + '"], [name="' + option_name + '[]"]' ).on( 'change', function() { console.log( option_name );

                var show_option = true;

                jQuery.each( conditional_on, function ( index, field ) { //console.log( conditional_on ); console.log( conditional_on_value );

                    var check_option = option_page + '[' + field + ']';
                    var input_element = jQuery( '[name="' + check_option + '"], [name="' + check_option + '[]"]' );

                    if ( input_element.length ) {

                        if (input_element.is(':checkbox')) {
                            option_value = input_element.is(':checked') ? input_element.val() : false;
                        } else if (input_element.is(':radio')) {
                            option_value = jQuery('[name="' + check_option + '"]:checked').val() || false;
                        } else {
                            option_value = input_element.val();
                        }
                    } //console.log( option_value );

                    var values_to_check = Array.isArray( conditional_on_value[ index ] ) ? conditional_on_value[ index ] : [ conditional_on_value[ index ] ];
                    //console.log( values_to_check ); console.log( values_to_check.includes( option_value ) ); console.log( option_value == true  ); console.log( values_to_check.includes( true ) );
                    if ( ! values_to_check.includes( option_value ) && ! ( option_value == true && values_to_check.includes( true ) ) ) {

                        show_option = false;
                        return false;
                    }
                } );

                if ( show_option ) {

                    option.parent().parent().removeClass( 'sap-hidden' );
                }
                else {

                    option.parent().parent().addClass( 'sap-hidden' );
                }
            } );
        } );
    });
});

function EWD_SAP_hexToRgb(hex) {
    var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? {
        r: parseInt(result[1], 16),
        g: parseInt(result[2], 16),
        b: parseInt(result[3], 16)
    } : null;
}

//OPTIONS PAGE YES/NO TOGGLE SWITCHES
jQuery(document).ready(function($){
	$('.sap-admin-option-toggle').on('change', function() {
		var Input_Name = $(this).data('inputname');
		if ($(this).is(':checked')) {
			$('input[name="' + Input_Name + '"][value="1"]').prop('checked', true).trigger('change');
			$('input[name="' + Input_Name + '"][value=""]').prop('checked', false);
		}
		else {
			$('input[name="' + Input_Name + '"][value="1"]').prop('checked', false).trigger('change');
			$('input[name="' + Input_Name + '"][value=""]').prop('checked', true);
		}
	});
});

/*LOCK BOXES*/
jQuery( document ).ready( function() {

    function resizeLockdownBoxes() {
        jQuery('.sap-premium-options-table-overlay').each(function(){
            
            var eachProTableOverlay = jQuery( this );
            var associatedTable = eachProTableOverlay.next();
            associatedTable.css('min-height', '260px');
            var tablePosition = associatedTable.position();

            eachProTableOverlay.css( 'width', associatedTable.outerWidth(true) + 'px' );
            eachProTableOverlay.css( 'height', associatedTable.outerHeight() + 'px' );
            eachProTableOverlay.css( 'left', tablePosition.left + 'px' );
            eachProTableOverlay.css( 'top', tablePosition.top + 'px' );
        });
    }

    setTimeout( resizeLockdownBoxes, 750 );
    setInterval( resizeLockdownBoxes, 3000 );
    jQuery( window ).on( 'resize', resizeLockdownBoxes );
});

/* TUTORIAL VIDEOS */
jQuery( document ).ready( function() {

    if ( ! jQuery( '.sap-tutorial-div' ).length ) { return; }

    var tutorial_div = jQuery( '.sap-tutorial-div' );

    tutorial_div.next().insertBefore( tutorial_div );

    jQuery( '.sap-settings-menu-and-video-button > h2:first-of-type' ).after( '<div class="sap-tutorial-toggle"><span class="dashicons dashicons-format-video"></span>Video Tutorial</div>' );

    jQuery( document ).on('click', '.sap-tutorial-toggle', function( event ) { 

        jQuery( '.sap-tutorial-div' ).toggleClass( 'sap-hidden' );
    } );
} );


/* NEW SETTINGS PAGE LAYOUT */

jQuery( document ).ready( function() {

    jQuery( 'table.form-table > tbody > tr' ).each( function () {
        
        var this_tr = jQuery( this );
        var this_description = this_tr.find( 'td .description' );
        var this_th = this_tr.find( '> th' );

        this_description.appendTo( this_th );
    } );

    jQuery( '.sap-parent-form input#submit' ).removeClass( 'button button-primary' );
} );
