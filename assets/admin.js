/* global busr, jQuery */
( function ( $ ) {
	function show( $el, ok, html ) { $el.removeClass( 'is-ok is-error' ).addClass( ok ? 'is-ok' : 'is-error' ).html( html ); }
	function busy( $btn, $out ) { $btn.prop( 'disabled', true ); $out.removeClass( 'is-ok is-error' ).text( busr.i18n.working ); }
	function post( action, data ) { return $.post( busr.ajax, $.extend( { action: action, nonce: busr.nonce }, data ) ); }
	function fail( $btn, $out ) { return function ( r ) { $btn.prop( 'disabled', false ); show( $out, false, ( r && r.data && r.data.message ) ? $( '<div>' ).text( r.data.message ).html() : busr.i18n.failed ); }; }

	$( '#busr-test-connection' ).on( 'click', function () {
		var $b = $( this ), $o = $( '#busr-connection-result' ); busy( $b, $o );
		post( 'busr_test_connection', {} ).done( function ( r ) { $b.prop( 'disabled', false ); if ( r.success ) { show( $o, true, $( '<div>' ).text( r.data.message ).html() ); } else { fail( $b, $o )( r ); } } ).fail( function ( x ) { fail( $b, $o )( x.responseJSON ); } );
	} );
	$( '#busr-test-rate' ).on( 'click', function () {
		var $b = $( this ), $o = $( '#busr-rate-result' ); busy( $b, $o );
		post( 'busr_test_rate', { origin: $( '#busr-origin' ).val(), dest: $( '#busr-dest' ).val(), country: $( '#busr-country' ).val(), weight: $( '#busr-weight' ).val(), length: $( '#busr-length' ).val(), width: $( '#busr-width' ).val(), height: $( '#busr-height' ).val() } )
			.done( function ( r ) {
				$b.prop( 'disabled', false );
				if ( ! r.success ) { return fail( $b, $o )( r ); }
				var $t = $( '<table>' ).append( '<tr><th>Service</th><th>USPS class</th><th>Price</th></tr>' );
				$.each( r.data.rates, function ( i, x ) { $t.append( $( '<tr>' ).append( $( '<td>' ).text( x.service ), $( '<td>' ).append( $( '<code>' ).text( x.class ) ), $( '<td>' ).html( x.price ) ) ); } );
				$o.removeClass( 'is-error' ).addClass( 'is-ok' ).empty().append( $t );
			} ).fail( function ( x ) { fail( $b, $o )( x.responseJSON ); } );
	} );
	$( '#busr-flush-cache' ).on( 'click', function () {
		var $b = $( this ), $o = $( '#busr-flush-result' ); busy( $b, $o );
		post( 'busr_flush_cache', {} ).done( function ( r ) { $b.prop( 'disabled', false ); show( $o, !! r.success, $( '<div>' ).text( r.data.message ).html() ); } ).fail( function ( x ) { fail( $b, $o )( x.responseJSON ); } );
	} );
	$( '#busr-diagnostics' ).on( 'click', function () {
		var $b = $( this ), $o = $( '#busr-diagnostics-result' ); busy( $b, $o );
		post( 'busr_diagnostics', {} ).done( function ( r ) {
			$b.prop( 'disabled', false );
			if ( ! r.success ) { return fail( $b, $o )( r ); }
			var done = function () { show( $o, true, 'Copied.' ); }, manual = function () { $o.removeClass( 'is-error' ).addClass( 'is-ok' ).empty().append( $( '<textarea rows="8" style="width:100%">' ).val( r.data.text ) ); };
			if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( r.data.text ).then( done, manual ); } else { manual(); }
		} ).fail( function ( x ) { fail( $b, $o )( x.responseJSON ); } );
	} );
}( jQuery ) );
