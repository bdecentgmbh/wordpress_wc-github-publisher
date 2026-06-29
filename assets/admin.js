/* global jQuery, wcgpAdmin */
( function ( $ ) {
	'use strict';

	var i18n = wcgpAdmin.i18n;
	var isVariable = !! wcgpAdmin.isVariable;
	// Asset keys (e.g. "asset:123" or "source:zip") with a publish entry on this product.
	var publishedKeys = ( wcgpAdmin.publishedKeys || [] ).map( String );

	function esc( value ) {
		return $( '<div/>' ).text( value == null ? '' : String( value ) ).html();
	}

	function sprintf1( template, value ) {
		return template.replace( '%s', value ).replace( '%d', value );
	}

	function humanSize( bytes ) {
		if ( ! bytes ) {
			return '';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		var n = bytes;
		while ( n >= 1024 && i < units.length - 1 ) {
			n /= 1024;
			i++;
		}
		return ( Math.round( n * 10 ) / 10 ) + ' ' + units[ i ];
	}

	function humanAgo( epochSeconds ) {
		if ( ! epochSeconds ) {
			return '';
		}
		var secs = Math.max( 0, Math.floor( Date.now() / 1000 ) - epochSeconds );
		if ( secs < 60 ) {
			return secs + 's';
		}
		if ( secs < 3600 ) {
			return Math.floor( secs / 60 ) + 'm';
		}
		if ( secs < 86400 ) {
			return Math.floor( secs / 3600 ) + 'h';
		}
		return Math.floor( secs / 86400 ) + 'd';
	}

	function isPublished( key ) {
		return publishedKeys.indexOf( String( key ) ) !== -1;
	}

	function productId() {
		return $( '#wcgp-fetch' ).data( 'product' );
	}

	function repo() {
		return $( '#_wcgp_repo' ).val();
	}

	// The selected publish target. Simple products have no selector → product target.
	function currentTarget() {
		var $sel = $( '#wcgp-target' );
		if ( ! $sel.length ) {
			return { attribute: '', value: '' };
		}
		var v = $sel.val();
		if ( ! v || v === '__all__' ) {
			return { attribute: '', value: '__all__' };
		}
		var idx = v.indexOf( '::' );
		if ( idx === -1 ) {
			return { attribute: '', value: '__all__' };
		}
		return { attribute: v.slice( 0, idx ), value: v.slice( idx + 2 ) };
	}

	function renderMeta( meta ) {
		var $meta = $( '#wcgp-meta' ).empty();
		if ( ! meta ) {
			return;
		}
		var parts = [];
		if ( meta.fetched_at ) {
			parts.push( sprintf1( i18n.cachedAgo, humanAgo( meta.fetched_at ) ) );
		}
		if ( meta.rate && typeof meta.rate.remaining !== 'undefined' ) {
			parts.push( sprintf1( i18n.rateLeft, meta.rate.remaining ) );
		}
		$meta.text( parts.join( ' · ' ) );
	}

	function render( releases ) {
		var $box = $( '#wcgp-releases' ).empty();
		if ( ! releases || ! releases.length ) {
			$box.html( '<p>' + esc( i18n.noReleases ) + '</p>' );
			return;
		}
		releases.forEach( function ( r ) {
			var badges = '';
			if ( r.latest ) {
				badges += ' <span class="wcgp-badge wcgp-latest">' + esc( i18n.latest ) + '</span>';
			}
			if ( r.draft ) {
				badges += ' <span class="wcgp-badge wcgp-draft">' + esc( i18n.draft ) + '</span>';
			}
			if ( r.prerelease ) {
				badges += ' <span class="wcgp-badge wcgp-pre">' + esc( i18n.prerelease ) + '</span>';
			}

			var $release = $( '<div class="wcgp-release"></div>' );
			$release.append(
				'<h4>' + esc( r.name || r.tag ) + ' <code>' + esc( r.tag ) + '</code>' + badges + '</h4>'
			);

			if ( ! r.assets || ! r.assets.length ) {
				$release.append( '<p class="description">' + esc( i18n.noAssets ) + '</p>' );
			} else {
				var $list = $( '<ul class="wcgp-assets"></ul>' );
				r.assets.forEach( function ( a ) {
					$list.append( assetRow( a, r.tag ) );
				} );
				$release.append( $list );
				$release.append(
					$( '<button type="button" class="button button-small wcgp-publish-selected"></button>' )
						.text( i18n.publishSel )
						.attr( 'data-tag', r.tag )
				);
			}
			$box.append( $release );
		} );
	}

	function assetRow( a, tag ) {
		var key = a.key || ( 'asset:' + a.id );
		var kind = a.kind || 'asset';
		var $li = $( '<li></li>' ).attr( 'data-key', key ).attr( 'data-asset', a.id );
		var published = isPublished( key );
		// Variable products keep controls enabled (an asset may target several values).
		var lock = published && ! isVariable;

		var $check = $( '<input type="checkbox" class="wcgp-asset-check" />' )
			.attr( 'data-asset', a.id )
			.attr( 'data-kind', kind )
			.attr( 'data-tag', tag );
		if ( lock ) {
			$check.prop( 'disabled', true );
		}
		$li.append( $check ).append( ' ' );
		$li.append( '<span class="wcgp-asset-name">' + esc( a.name ) + '</span> ' );
		if ( a.size ) {
			$li.append( '<span class="wcgp-asset-size">' + esc( humanSize( a.size ) ) + '</span> ' );
		}

		var $btn = $( '<button type="button" class="button button-small wcgp-publish"></button>' )
			.text( i18n.publish )
			.attr( 'data-asset', a.id )
			.attr( 'data-kind', kind )
			.attr( 'data-tag', tag );
		if ( lock ) {
			$btn.prop( 'disabled', true );
		}
		$li.append( $btn );
		$li.append( ' <span class="wcgp-status"></span>' );

		if ( published ) {
			$li.append( ' <span class="wcgp-badge wcgp-published">✓ ' + esc( i18n.published ) + '</span>' );
		}
		return $li;
	}

	function fetchReleases( force ) {
		var $spinner = $( '#wcgp-fetch' ).next( '.spinner' );
		$spinner.addClass( 'is-active' );
		$( '#wcgp-releases' ).html( '<p>' + esc( i18n.fetching ) + '</p>' );

		return $.post( wcgpAdmin.ajaxUrl, {
			action: 'wcgp_fetch_releases',
			nonce: wcgpAdmin.fetchNonce,
			repo: repo(),
			force: force ? 1 : 0
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					render( res.data.releases );
					renderMeta( res.data.meta );
				} else {
					$( '#wcgp-releases' ).html(
						'<p class="wcgp-error">' + esc( ( res && res.data && res.data.message ) || i18n.error ) + '</p>'
					);
				}
			} )
			.fail( function () {
				$( '#wcgp-releases' ).html( '<p class="wcgp-error">' + esc( i18n.error ) + '</p>' );
			} )
			.always( function () {
				$spinner.removeClass( 'is-active' );
			} );
	}

	// Publish one asset to the current target. Returns a jQuery promise.
	function publishAsset( assetId, kind, tag, $status ) {
		var target = currentTarget();
		$status.removeClass( 'wcgp-ok wcgp-error' ).text( i18n.publishing );
		return $.post( wcgpAdmin.ajaxUrl, {
			action: 'wcgp_publish_asset',
			nonce: wcgpAdmin.publishNonce,
			product: productId(),
			repo: repo(),
			asset: assetId,
			kind: kind,
			tag: tag,
			attribute: target.attribute,
			value: target.value
		} ).then( function ( res ) {
			if ( res && res.success ) {
				$status.addClass( 'wcgp-ok' ).text( '✓ ' + i18n.published );
				onPublished( res.data );
			} else {
				$status.addClass( 'wcgp-error' ).text( ( res && res.data && res.data.message ) || i18n.error );
			}
			return res;
		}, function () {
			$status.addClass( 'wcgp-error' ).text( i18n.error );
		} );
	}

	function onPublished( data ) {
		var key = data.asset_key;
		if ( ! isPublished( key ) ) {
			publishedKeys.push( String( key ) );
		}
		var $li = $( '#wcgp-releases li' ).filter( function () {
			return $( this ).attr( 'data-key' ) === key;
		} );
		if ( ! $li.find( '.wcgp-published' ).length ) {
			$li.append( ' <span class="wcgp-badge wcgp-published">✓ ' + esc( i18n.published ) + '</span>' );
		}
		if ( ! isVariable ) {
			$li.find( '.wcgp-publish' ).prop( 'disabled', true );
			$li.find( '.wcgp-asset-check' ).prop( 'checked', false ).prop( 'disabled', true );
		}
		addPublishedRow( data );
	}

	function addPublishedRow( data ) {
		$( '#wcgp-published .wcgp-empty' ).remove();

		var date = '';
		if ( data.published_at ) {
			try {
				date = new Date( data.published_at ).toLocaleDateString();
			} catch ( e ) {
				date = '';
			}
		}

		var $li = $( '<li class="wcgp-managed"></li>' )
			.attr( 'data-publish', data.publish_id )
			.attr( 'data-key', data.asset_key );
		$li.append( '<span class="wcgp-managed-label">' + esc( data.label ) + '</span> ' );

		if ( isVariable && data.target_label ) {
			var targetText = data.target_label + ' · ' + sprintf1( i18n.variations, data.targets );
			$li.append( '<span class="wcgp-managed-target">→ ' + esc( targetText ) + '</span> ' );
		}
		if ( date ) {
			$li.append( '<span class="wcgp-managed-date">— ' + esc( date ) + '</span> ' );
		}
		$li.append(
			$( '<button type="button" class="button-link wcgp-remove"></button>' )
				.text( i18n.remove )
				.attr( 'data-publish', data.publish_id )
		);
		$li.append( ' <span class="wcgp-status"></span>' );
		$( '#wcgp-published' ).append( $li );
	}

	// After a row is removed, drop the published badge/lock if no rows remain for it.
	function refreshAssetState( key ) {
		var stillPublished = $( '#wcgp-published li' ).filter( function () {
			return $( this ).attr( 'data-key' ) === key;
		} ).length > 0;
		if ( stillPublished ) {
			return;
		}
		var idx = publishedKeys.indexOf( String( key ) );
		if ( idx !== -1 ) {
			publishedKeys.splice( idx, 1 );
		}
		var $arow = $( '#wcgp-releases li' ).filter( function () {
			return $( this ).attr( 'data-key' ) === key;
		} );
		$arow.find( '.wcgp-published' ).remove();
		$arow.find( '.wcgp-publish' ).prop( 'disabled', false );
		$arow.find( '.wcgp-asset-check' ).prop( 'disabled', false );
	}

	// --- Events ---

	$( document ).on( 'click', '#wcgp-fetch', function () {
		fetchReleases( false );
	} );

	$( document ).on( 'click', '#wcgp-refresh', function ( e ) {
		e.preventDefault();
		fetchReleases( true );
	} );

	$( document ).on( 'click', '.wcgp-publish', function () {
		var $btn = $( this );
		if ( ! window.confirm( i18n.confirm ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		var key = $btn.closest( 'li' ).attr( 'data-key' );
		publishAsset( $btn.data( 'asset' ), $btn.data( 'kind' ), $btn.data( 'tag' ), $btn.nextAll( '.wcgp-status' ).first() )
			.always( function () {
				if ( isVariable || ! isPublished( key ) ) {
					$btn.prop( 'disabled', false );
				}
			} );
	} );

	$( document ).on( 'click', '.wcgp-publish-selected', function () {
		var $release = $( this ).closest( '.wcgp-release' );
		var $checked = $release.find( '.wcgp-asset-check:checked' );
		if ( ! $checked.length || ! window.confirm( i18n.confirm ) ) {
			return;
		}
		var $selectedBtn = $( this ).prop( 'disabled', true );

		// Publish sequentially to avoid concurrent product/variation saves clobbering.
		var queue = $checked.toArray();
		( function next() {
			if ( ! queue.length ) {
				$selectedBtn.prop( 'disabled', false );
				return;
			}
			var $chk = $( queue.shift() );
			var $li = $chk.closest( 'li' );
			$li.find( '.wcgp-publish' ).prop( 'disabled', true );
			publishAsset( $chk.data( 'asset' ), $chk.data( 'kind' ), $chk.data( 'tag' ), $li.find( '.wcgp-status' ).first() )
				.always( next );
		} )();
	} );

	$( document ).on( 'click', '.wcgp-remove', function () {
		var $btn = $( this );
		if ( ! window.confirm( i18n.confirmRemove ) ) {
			return;
		}
		var publishId = $btn.data( 'publish' );
		var $li = $btn.closest( 'li' );
		var key = $li.attr( 'data-key' );
		var $status = $li.find( '.wcgp-status' ).first();
		$btn.prop( 'disabled', true );
		$status.removeClass( 'wcgp-ok wcgp-error' ).text( i18n.removing );

		$.post( wcgpAdmin.ajaxUrl, {
			action: 'wcgp_unpublish',
			nonce: wcgpAdmin.unpublishNonce,
			product: productId(),
			publish: publishId
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$li.remove();
					if ( ! $( '#wcgp-published li' ).length ) {
						$( '#wcgp-published' ).append( '<li class="wcgp-empty">' + esc( i18n.nothingPublished ) + '</li>' );
					}
					if ( key ) {
						refreshAssetState( key );
					}
				} else {
					$btn.prop( 'disabled', false );
					$status.addClass( 'wcgp-error' ).text( ( res && res.data && res.data.message ) || i18n.error );
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
				$status.addClass( 'wcgp-error' ).text( i18n.error );
			} );
	} );
} )( jQuery );
