/* global jQuery, wcgpAdmin */
( function ( $ ) {
	'use strict';

	var i18n = wcgpAdmin.i18n;
	var isVariable = !! wcgpAdmin.isVariable;

	// Per-repo releases loaded from the last "Load releases" call, keyed by repo.
	var loaded = {};

	function esc( value ) {
		return $( '<div/>' ).text( value == null ? '' : value ).html();
	}

	function sprintf1( template, value ) {
		return String( template ).replace( '%d', value ).replace( '%s', value );
	}

	function humanSize( bytes ) {
		bytes = parseInt( bytes, 10 ) || 0;
		if ( ! bytes ) {
			return '';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i ? bytes.toFixed( 1 ) : bytes ) + ' ' + units[ i ];
	}

	function productId() {
		return $( '#wcgp-load' ).data( 'product' );
	}

	function currentTarget() {
		var sel = $( '#wcgp-target' ).val() || '';
		if ( ! isVariable || ! sel || sel === '__all__' ) {
			return { attribute: '', value: '' };
		}
		var parts = sel.split( '::' );
		return { attribute: parts[ 0 ] || '', value: parts[ 1 ] || '' };
	}

	/* ----------------------------------------------------------------- Repeater */

	var repoSeq = 1000; // New-row indices; existing rows are rendered server-side.

	$( document ).on( 'click', '#wcgp-add-repo', function () {
		var idx = repoSeq++;
		var row =
			'<div class="wcgp-repo-row">' +
			'<input type="text" class="wcgp-repo-input" name="wcgp_repos[' + idx + '][repo]" value="" placeholder="owner/moodle-mod_example" />' +
			'<input type="text" class="wcgp-repo-path" name="wcgp_repos[' + idx + '][path]" value="" placeholder="' + esc( i18n.installPath ) + '" />' +
			'<label class="wcgp-primary-label"><input type="radio" name="wcgp_repos_primary" value="' + idx + '" /> ' + esc( i18n.primary ) + '</label>' +
			'<button type="button" class="button-link wcgp-remove-repo" title="' + esc( i18n.removeRepo ) + '">&times;</button>' +
			'</div>';
		$( '#wcgp-repos' ).append( row );
	} );

	$( document ).on( 'click', '.wcgp-remove-repo', function () {
		var $rows = $( '#wcgp-repos .wcgp-repo-row' );
		if ( $rows.length <= 1 ) {
			$( this ).closest( '.wcgp-repo-row' ).find( 'input[type=text]' ).val( '' );
			return;
		}
		var $row = $( this ).closest( '.wcgp-repo-row' );
		var wasPrimary = $row.find( 'input[type=radio]' ).is( ':checked' );
		$row.remove();
		// Keep exactly one primary selected.
		if ( wasPrimary && ! $( '#wcgp-repos input[type=radio]:checked' ).length ) {
			$( '#wcgp-repos input[type=radio]' ).first().prop( 'checked', true );
		}
	} );

	/* ----------------------------------------------------------------- Composer */

	function defaultAsset( release ) {
		var assets = ( release && release.assets ) || [];
		var i;
		for ( i = 0; i < assets.length; i++ ) {
			if ( assets[ i ].kind === 'asset' && /\.zip$/i.test( assets[ i ].name || '' ) ) {
				return assets[ i ];
			}
		}
		for ( i = 0; i < assets.length; i++ ) {
			if ( assets[ i ].kind === 'asset' ) {
				return assets[ i ];
			}
		}
		return assets[ 0 ] || null;
	}

	function defaultReleaseIndex( releases ) {
		for ( var i = 0; i < releases.length; i++ ) {
			if ( releases[ i ].latest ) {
				return i;
			}
		}
		return 0;
	}

	function assetOptions( release ) {
		var assets = ( release && release.assets ) || [];
		var def = defaultAsset( release );
		var defKey = def && def.key;
		var html = '';
		assets.forEach( function ( a ) {
			var label = a.kind === 'zipball' ? i18n.sourceZip : a.name;
			if ( a.size ) {
				label += ' (' + humanSize( a.size ) + ')';
			}
			html += '<option value="' + esc( a.key ) + '" data-kind="' + esc( a.kind ) + '" data-id="' + esc( a.id ) + '"' +
				( a.key === defKey ? ' selected' : '' ) + '>' + esc( label ) + '</option>';
		} );
		return html;
	}

	function releaseOptions( releases, selectedIndex ) {
		var html = '';
		releases.forEach( function ( r, i ) {
			var label = ( r.name || r.tag || '' );
			if ( r.tag && r.name && r.name !== r.tag ) {
				label += ' (' + r.tag + ')';
			}
			var flags = [];
			if ( r.latest ) { flags.push( i18n.latest ); }
			if ( r.prerelease ) { flags.push( i18n.prerelease ); }
			if ( r.draft ) { flags.push( i18n.draft ); }
			if ( flags.length ) { label += ' — ' + flags.join( ', ' ); }
			html += '<option value="' + i + '"' + ( i === selectedIndex ? ' selected' : '' ) + '>' + esc( label ) + '</option>';
		} );
		return html;
	}

	function renderComposer( repos ) {
		loaded = {};
		var $c = $( '#wcgp-composer' ).empty();
		var publishable = 0;

		repos.forEach( function ( repo ) {
			loaded[ repo.repo ] = repo;
			var $block = $( '<div class="wcgp-crepo" />' ).attr( 'data-repo', repo.repo );
			$block.append( '<div class="wcgp-crepo-head"><strong>' + esc( repo.repo ) + '</strong>' +
				( repo.primary ? ' <span class="wcgp-primary-badge">' + esc( i18n.primary || 'primary' ) + '</span>' : '' ) + '</div>' );

			if ( repo.error ) {
				$block.append( '<div class="wcgp-crepo-error">' + esc( repo.error ) + '</div>' );
			} else if ( ! repo.releases || ! repo.releases.length ) {
				$block.append( '<div class="wcgp-crepo-error">' + esc( i18n.noReleases ) + '</div>' );
			} else {
				publishable++;
				var relIdx = defaultReleaseIndex( repo.releases );
				var rel = repo.releases[ relIdx ];
				$block.append(
					'<div class="wcgp-crepo-controls">' +
					'<label>' + esc( i18n.release ) + ' <select class="wcgp-rel">' + releaseOptions( repo.releases, relIdx ) + '</select></label> ' +
					'<label>' + esc( i18n.asset ) + ' <select class="wcgp-asset">' + assetOptions( rel ) + '</select></label>' +
					'</div>'
				);
			}
			$c.append( $block );
		} );

		// Publish only when every configured repo offers a selectable release.
		var ok = publishable > 0 && publishable === repos.length;
		$( '#wcgp-publish-wrap' ).toggle( ok );
		$( '#wcgp-publish-status' ).text( '' );
	}

	// Re-render the asset select when the release changes.
	$( document ).on( 'change', '.wcgp-rel', function () {
		var $block = $( this ).closest( '.wcgp-crepo' );
		var repo = $block.attr( 'data-repo' );
		var release = loaded[ repo ] && loaded[ repo ].releases[ parseInt( $( this ).val(), 10 ) ];
		$block.find( '.wcgp-asset' ).html( assetOptions( release ) );
	} );

	function loadReleases( force ) {
		var $spinner = $( '#wcgp-load' ).siblings( '.spinner' );
		$spinner.addClass( 'is-active' );
		$( '#wcgp-composer' ).html( '<p class="description">' + esc( i18n.loading ) + '</p>' );
		$( '#wcgp-publish-wrap' ).hide();

		return $.post( wcgpAdmin.ajaxUrl, {
			action: 'wcgp_fetch_bundle',
			nonce: wcgpAdmin.fetchNonce,
			product: productId(),
			force: force ? 1 : 0
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data && res.data.repos ) {
					renderComposer( res.data.repos );
				} else {
					$( '#wcgp-composer' ).html( '<p class="wcgp-crepo-error">' + esc( ( res && res.data && res.data.message ) || i18n.error ) + '</p>' );
				}
			} )
			.fail( function () {
				$( '#wcgp-composer' ).html( '<p class="wcgp-crepo-error">' + esc( i18n.error ) + '</p>' );
			} )
			.always( function () {
				$spinner.removeClass( 'is-active' );
			} );
	}

	$( document ).on( 'click', '#wcgp-load', function () {
		loadReleases( false );
	} );

	$( document ).on( 'click', '#wcgp-refresh', function ( e ) {
		e.preventDefault();
		loadReleases( true );
	} );

	/* ------------------------------------------------------------------ Publish */

	function collectSelections() {
		var selections = [];
		var complete = true;
		$( '#wcgp-composer .wcgp-crepo' ).each( function () {
			var $block = $( this );
			var repo = $block.attr( 'data-repo' );
			var $rel = $block.find( '.wcgp-rel' );
			var $asset = $block.find( '.wcgp-asset option:selected' );
			if ( ! $rel.length || ! $asset.length ) {
				complete = false;
				return;
			}
			var release = loaded[ repo ] && loaded[ repo ].releases[ parseInt( $rel.val(), 10 ) ];
			selections.push( {
				repo: repo,
				tag: release ? release.tag : '',
				kind: $asset.data( 'kind' ) || 'asset',
				asset_id: $asset.data( 'id' ) || 0
			} );
		} );
		return complete ? selections : null;
	}

	function addPublishedRow( data ) {
		$( '#wcgp-published .wcgp-empty' ).remove();
		var label = data.label || '';
		var li = '<li class="wcgp-managed" data-publish="' + esc( data.publish_id ) + '">' +
			'<span class="wcgp-managed-label">' + esc( label ) + '</span>';
		if ( data.components && data.components.length > 1 ) {
			li += ' <span class="wcgp-managed-components">' + esc( sprintf1( i18n.components, data.components.length ) ) + '</span>';
		}
		if ( isVariable && data.target_label ) {
			li += ' <span class="wcgp-managed-target">→ ' + esc( data.target_label ) + '</span>';
		}
		li += ' <button type="button" class="button-link wcgp-remove" data-publish="' + esc( data.publish_id ) + '">' + esc( i18n.remove ) + '</button>' +
			'<span class="wcgp-status"></span></li>';
		$( '#wcgp-published' ).append( li );
	}

	$( document ).on( 'click', '#wcgp-publish-bundle', function () {
		var selections = collectSelections();
		if ( ! selections ) {
			window.alert( i18n.error );
			return;
		}
		if ( ! window.confirm( i18n.confirmPublish ) ) {
			return;
		}
		var $btn = $( this ).prop( 'disabled', true );
		var $status = $( '#wcgp-publish-status' ).text( i18n.publishing );
		var target = currentTarget();

		$.post( wcgpAdmin.ajaxUrl, {
			action: 'wcgp_publish_bundle',
			nonce: wcgpAdmin.publishNonce,
			product: productId(),
			attribute: target.attribute,
			value: target.value,
			selections: selections
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$status.text( i18n.published );
					addPublishedRow( res.data );
				} else {
					$status.text( ( res && res.data && res.data.message ) || i18n.error );
				}
			} )
			.fail( function () {
				$status.text( i18n.error );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	/* ---------------------------------------------------------------- Unpublish */

	$( document ).on( 'click', '.wcgp-remove', function () {
		if ( ! window.confirm( i18n.confirmRemove ) ) {
			return;
		}
		var $li = $( this ).closest( '.wcgp-managed' );
		var $status = $li.find( '.wcgp-status' ).text( i18n.removing );

		$.post( wcgpAdmin.ajaxUrl, {
			action: 'wcgp_unpublish',
			nonce: wcgpAdmin.unpublishNonce,
			product: productId(),
			publish: $li.data( 'publish' )
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$li.remove();
					if ( ! $( '#wcgp-published .wcgp-managed' ).length ) {
						$( '#wcgp-published' ).append( '<li class="wcgp-empty">' + esc( i18n.nothingPublished ) + '</li>' );
					}
				} else {
					$status.text( ( res && res.data && res.data.message ) || i18n.error );
				}
			} )
			.fail( function () {
				$status.text( i18n.error );
			} );
	} );

} )( jQuery );
