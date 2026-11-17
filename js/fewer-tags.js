/**
 * Loaded on edit-tags admin pages, this file contains the JavaScript for the FewerTags plugin.
 *
 * @file   This files contains the functionality for the FewerTags plugin.
 * @author Joost de Valk
 */

/* global fewerTags, tb_remove, Choices */

/**
 * A helper to make AJAX requests.
 *
 * @param {Object}   params               The callback parameters.
 * @param {string}   params.url           The URL to send the request to.
 * @param {Object}   params.data          The data to send with the request.
 * @param {Function} params.successAction The callback to run on success.
 * @param {Function} params.failAction    The callback to run on failure.
 */
const fewerTagsAjaxRequest = ( { url, data, successAction, failAction } ) => {
	const http = new XMLHttpRequest();
	http.open( 'POST', url, true );
	http.onreadystatechange = () => {
		let response;
		try {
			response = JSON.parse( http.response );
		} catch ( e ) {
			if ( http.readyState === 4 && http.status !== 200 ) {
				// eslint-disable-next-line no-console
				console.warn( http, e );
				return http.response;
			}
		}
		if ( http.readyState === 4 && http.status === 200 ) {
			return successAction ? successAction( response ) : response;
		}
		return failAction ? failAction( response ) : response;
	};

	const dataForm = new FormData();

	// eslint-disable-next-line prefer-const
	for ( let [ key, value ] of Object.entries( data ) ) {
		dataForm.append( key, value );
	}

	http.send( dataForm );
};

/**
 * Similar to jQuery's $( document ).ready().
 * Runs a callback when the DOM is ready.
 *
 * @param {Function} callback The callback to run when the DOM is ready.
 */
function fewerTagsDomReady( callback ) {
	if ( document.readyState !== 'loading' ) {
		callback();
		return;
	}
	document.addEventListener( 'DOMContentLoaded', callback );
}

/**
 * A helper to allow listening for element removals,
 * and run a callback when that happens.
 *
 * @param {Element[]} elements The elements to observe.
 * @param {Function}  callback The callback to run when the element is removed.
 */
const fewerTagsObserveElementRemoval = ( elements, callback ) => {
	const theList = document.getElementById( 'the-list' );
	if ( ! theList ) {
		return;
	}
	elements.forEach( ( element ) => {
		let inDom = theList.contains( element );
		const observer = new MutationObserver( () => {
			if ( ! theList.contains( element ) && inDom ) {
				inDom = false;
				callback();
			}
		} );
		observer.observe( theList, { childList: true, subtree: true } );
	} );
};

/**
 * Escapes a value for safe use in HTML.
 *
 * @param {*} value The value to escape.
 *
 * @return {string} The escaped string.
 */
function fewerTagsEscapeHtml( value ) {
	return String( value === null || value === undefined ? '' : value )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

/**
 * Create a redirect for a term, and show a notice.
 * This function creates an AJAX request to the server.
 *
 * @param {Element} notice   The notice element the redirect was requested from.
 * @param {string}  slug     The slug of the term to redirect.
 * @param {string}  taxonomy The taxonomy of the term to redirect.
 * @param {string}  target   The target URL to redirect to.
 * @param {string}  nonce    The nonce to use for the request.
 */
function fewerTagsRedirectToUrl( notice, slug, taxonomy, target, nonce ) {
	fewerTagsAjaxRequest( {
		url: fewerTags.ajaxUrl,
		data: {
			action: 'fewer_tags_redirect_url',
			slug,
			taxonomy,
			target,
			_ajax_nonce: nonce,
		},
		successAction: ( response ) => {
			if ( ! response || ! response.data || ! notice ) {
				return;
			}
			// The message is built and escaped on the server.
			const result = document.createElement( 'div' );
			result.className = `notice ${ response.success ? 'notice-success' : 'notice-error' } is-dismissible`;
			result.innerHTML = `
				<p>${ response.data.msg }</p>
				<button type="button" class="notice-dismiss">
					<span class="screen-reader-text">${ fewerTagsEscapeHtml( fewerTags.dismissText ) }</span>
				</button>`;
			result.querySelector( '.notice-dismiss' ).addEventListener( 'click', () => result.remove() );

			if ( response.success ) {
				notice.replaceWith( result );
				return;
			}
			// On failure, keep the original notice so the user can try again, and replace any earlier error.
			if ( notice.previousElementSibling && notice.previousElementSibling.classList.contains( 'fewer-tags-redirect-error' ) ) {
				notice.previousElementSibling.remove();
			}
			result.classList.add( 'fewer-tags-redirect-error' );
			notice.before( result );
		},
	} );
}

// The redirect notices can be added to the page after load, so we listen on the document.
document.addEventListener( 'click', ( event ) => {
	if ( ! event.target || ! event.target.closest ) {
		return;
	}

	const redirectAction = event.target.closest( '.fewer-tags-redirect-action' );
	if ( redirectAction ) {
		event.preventDefault();
		let target = redirectAction.dataset.target;
		if ( redirectAction.dataset.prompt ) {
			// eslint-disable-next-line no-alert
			target = window.prompt( redirectAction.dataset.prompt );
			if ( target === null || target.trim() === '' ) {
				// The prompt was cancelled or left empty.
				return;
			}
		}
		fewerTagsRedirectToUrl(
			redirectAction.closest( '.fewer-tags-redirect-notice' ),
			redirectAction.dataset.slug,
			redirectAction.dataset.taxonomy,
			target,
			redirectAction.dataset.nonce
		);
		return;
	}

	const dismissButton = event.target.closest( '.fewer-tags-redirect-notice .notice-dismiss' );
	if ( dismissButton ) {
		const notice = dismissButton.closest( '.fewer-tags-redirect-notice' );
		fewerTagsAjaxRequest( {
			url: fewerTags.ajaxUrl,
			data: {
				action: 'fewer_tags_dismiss_notice',
				id: dismissButton.getAttribute( 'data-id' ),
				taxonomy: dismissButton.getAttribute( 'data-taxonomy' ),
				_ajax_nonce: dismissButton.getAttribute( 'data-nonce' ),
			},
			successAction: () => {
				// Remove the notice from the DOM.
				notice.remove();
			},
		} );
	}
} );

async function fewerTagsGetTaxonomies( currentTaxonomy ) {
	const apiUrl = fewerTags.taxonomiesUrl;

	try {
		const response = await fetch( apiUrl, {
			credentials: 'include', // Include cookies with the request
			headers: {
				'X-WP-Nonce': fewerTags.restAPInonce,
			},
		} );

		if ( ! response.ok ) {
			throw new Error( `Network response was not ok (Status: ${ response.status })` );
		}

		const taxonomies = await response.json();
		const publicTaxonomies = Object.keys( taxonomies )
			.filter( ( key ) => taxonomies[ key ].visibility && taxonomies[ key ].visibility.public && taxonomies[ key ].visibility.show_ui ) // Only public taxonomies with an admin UI can be merged into
			.map( ( key ) => ( {
				value: taxonomies[ key ].slug,
				label: taxonomies[ key ].labels.singular_name,
				selected: ( taxonomies[ key ].slug === currentTaxonomy ),
				customProperties: {
					post_type: taxonomies[ key ].types,
				},
			} ) );

		return publicTaxonomies; // This array contains objects with the slug and plural name of each public taxonomy
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( 'Error fetching public taxonomies with edit context and cookies:', error );
	}
}

let FTterms = [];

/**
 * Get all terms from the WordPress REST API.
 *
 * @param {string} taxonomy The taxonomy to get the terms for.
 *
 * @return {Promise} The promise of the terms.
 */
async function fewerTagsGetAllTerms( taxonomy ) {
	const retrievedTerms = [];
	const perPage = 100; // Max allowed by WP REST API
	let page = 1;
	let hasMore = true;
	const termsOutput = [];

	const endpoint = fewerTags.termUrls[ taxonomy ];
	if ( ! endpoint ) {
		return termsOutput;
	}

	while ( hasMore ) {
		const apiUrl = new URL( endpoint );
		apiUrl.searchParams.set( 'per_page', perPage );
		apiUrl.searchParams.set( 'page', page );

		try {
			const response = await fetch( apiUrl.toString() );
			if ( ! response.ok ) {
				throw new Error( `Network response was not ok (Status: ${ response.status })` );
			}
			const data = await response.json();
			retrievedTerms.push( ...data );

			if ( data.length < perPage ) {
				hasMore = false; // Break the loop if we got less than perPage items
			} else {
				page++; // Prepare to fetch the next page
			}
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Error fetching post tags:', error );
			break; // Exit the loop in case of an error
		}
	}

	// Process the tags
	retrievedTerms.forEach( ( tag ) => {
		termsOutput.push(
			{
				value: tag.id,
				label: tag.name,
				customProperties: {
					count: tag.count,
				},
			},
		);
	} );
	return termsOutput;
}

/**
 * Function to remove an object from the array by its (integer) value.
 *
 * @param {Array}  arr           The array of terms.
 * @param {number} valueToRemove The ID of the term to remove.
 *
 * @return {Array} Returns the array without the item we removed.
 */
function fewerTagsRemoveObjectByValue( arr, valueToRemove ) {
	arr = arr.filter( ( item ) => parseInt( item.value ) !== parseInt( valueToRemove ) );
	return arr;
}

/**
 * Function to get an object from the array by its (integer) value.
 *
 * @param {Array}  arr         The array of terms.
 * @param {number} valueToFind The term-ID.
 *
 * @return {Object} The term details.
 */
function fewerTagsGetObjectByValue( arr, valueToFind ) {
	return arr.find( ( item ) => parseInt( item.value ) === parseInt( valueToFind ) );
}

let FTchoices = {};
let FTbackup = {};

const FTchoicesElement = document.getElementById( 'fewer-tags-target-term-id' );
fewerTagsGetAllTerms( FTchoicesElement.dataset.taxonomy ).then( ( terms ) => {
	FTterms = terms;
	FTchoices = new Choices( FTchoicesElement, {
		choices: FTterms,
		allowHTML: false,
		position: 'bottom',
		itemSelectText: '',
		renderChoiceLimit: -1,
		removeItemButton: true,
		placeholder: false,
		labelId: 'fewer-tags-target-term-id',
		classNames: {
			containerOuter: 'choices terms',
		},
		callbackOnCreateTemplates( template ) {
			return {
				choice: ( { classNames }, data ) => {
					return template( `
						<div
							class="${ classNames.item } ${ classNames.itemChoice } ${ data.disabled ? classNames.itemDisabled : classNames.itemSelectable }"
							data-select-text="${ fewerTagsEscapeHtml( this.config.itemSelectText ) }"
							data-choice
							${ data.disabled ? 'data-choice-disabled aria-disabled="true"' : 'data-choice-selectable' }
							data-id="${ fewerTagsEscapeHtml( data.id ) }"
							data-value="${ fewerTagsEscapeHtml( data.value ) }"
							${ data.groupId > 0 ? 'role="treeitem"' : 'role="option"' }
						>
							${ fewerTagsEscapeHtml( data.label ) } <span class="count">${ fewerTagsEscapeHtml( data.customProperties.count ) }</span>
						</div>
					` );
				},
			};
		},
	} );
} );

fewerTagsDomReady( () => {
	let FTtaxonomies = [];

	const FTtaxonomyElement = document.getElementById( 'fewer-tags-target-taxonomy-slug' );
	fewerTagsGetTaxonomies( FTtaxonomyElement.dataset.currentTaxonomy ).then( ( taxonomies ) => {
		FTtaxonomies = taxonomies;
		new Choices( FTtaxonomyElement, {
			choices: FTtaxonomies,
			allowHTML: false,
			position: 'bottom',
			itemSelectText: '',
			renderChoiceLimit: -1,
			removeItemButton: true,
			placeholder: false,
			callbackOnCreateTemplates( template ) {
				return {
					choice: ( { classNames }, data ) => {
						return template( `
							<div
								class="${ classNames.item } ${ classNames.itemChoice } ${ data.disabled ? classNames.itemDisabled : classNames.itemSelectable }"
								data-select-text="${ fewerTagsEscapeHtml( this.config.itemSelectText ) }"
								data-choice
								${ data.disabled ? 'data-choice-disabled aria-disabled="true"' : 'data-choice-selectable' }
								data-id="${ fewerTagsEscapeHtml( data.id ) }"
								data-value="${ fewerTagsEscapeHtml( data.value ) }"
								${ data.groupId > 0 ? 'role="treeitem"' : 'role="option"' }
							>
								${ fewerTagsEscapeHtml( data.label ) } <span class="count">${ fewerTagsEscapeHtml( data.customProperties.post_type ) }</span>
							</div>
						` );
					},
				};
			},
		} );
	} );

	FTtaxonomyElement.addEventListener( 'change', ( event ) => {
		const targetTaxonomy = event.target.value;
		fewerTagsGetAllTerms( targetTaxonomy ).then( ( terms ) => {
			FTterms = terms;
			FTchoices.setChoices( FTterms, 'value', 'label', true );
			document.getElementById( 'fewer-tags-target-term-label' ).textContent = 'Target ' + targetTaxonomy;
			document.getElementById( 'fewer-tags-target-taxonomy-slug' ).value = targetTaxonomy;
		} );
	} );

	fewerTagsObserveElementRemoval( document.querySelectorAll( '#the-list tr' ), () => {
		fewerTagsAjaxRequest( {
			url: fewerTags.ajaxUrl,
			data: {
				action: 'fewer_tags_get_just_deleted_term',
				_ajax_nonce: fewerTags.deleteTermNonce,
			},
			successAction: ( response ) => {
				// No notice to show, for instance when the deleted term has no archive to redirect.
				if ( ! response || ! response.success || typeof response.data !== 'string' ) {
					return;
				}
				const ajaxResponseEl = document.getElementById( 'ajax-response' );
				if ( ajaxResponseEl ) {
					ajaxResponseEl.replaceChildren();
					ajaxResponseEl.innerHTML = response.data;
				}
				window.scrollTo( 0, 0 );
			},
		} );
	} );

	// Handler when 'Merge' link is clicked. Thickbox opens automatically, this sets the values for the form.
	document.querySelectorAll( '.fewer-tags-merge-action' ).forEach( ( element ) => {
		element.addEventListener( 'click', () => {
			const termID = element.getAttribute( 'data-term-id' );
			const termName = element.getAttribute( 'data-term-name' );

			document.getElementById( 'fewer-tags-source-term-id' ).value = termID;
			document.getElementById( 'fewer-tags-source-term-name' ).value = termName;
			// If we want to merge a term with another,
			// then we need to remove the option in the dropdown
			// because we obviously can't merge it with itself.
			// The option is backed up, so we can add it again
			// when the modal closes (see separate MutationObserver).
			FTbackup = fewerTagsGetObjectByValue( FTterms, termID );
			FTterms = fewerTagsRemoveObjectByValue( FTterms, termID );
			FTchoices.setChoices( FTterms, 'value', 'label', true );

			// Otherwise the old value will show up in the dropdown placeholder, super annoying.
			document.querySelector( '.choices.terms .choices__item' ).innerText = '';

			if ( fewerTags.defaultTermId && parseInt( termID ) === fewerTags.defaultTermId ) {
				document.getElementById( 'fewer-tags-note' ).style.display = '';
				document.querySelector( '#fewer-tags-merge-form h3' ).style.display = 'none';
			} else {
				document.getElementById( 'fewer-tags-note' ).style.display = 'none';
				document.querySelector( '#fewer-tags-merge-form h3' ).style.display = '';
			}
		} );
	} );

	document.getElementById( 'fewer-tags-merge-form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();

		fewerTagsAjaxRequest( {
			url: fewerTags.ajaxUrl,
			data: {
				action: 'fewer_tags_merge_terms',
				source_taxonomy: document.getElementById( 'fewer-tags-taxonomy' ).value,
				source_id: document.getElementById( 'fewer-tags-source-term-id' ).value,
				target_taxonomy: document.getElementById( 'fewer-tags-target-taxonomy-slug' ).value,
				target_id: document.getElementById( 'fewer-tags-target-term-id' ).value,
				_ajax_nonce: document.getElementById( 'fewer-tags-merge-terms-nonce' ).value,
			},
			successAction: ( response ) => {
				if ( ! response || ! response.success ) {
					const errorMsg = response && response.data && response.data.msg ? response.data.msg : '';
					document.getElementById( 'ajax-response' )
						.innerHTML = `
							<div class="notice notice-error is-dismissible">
								<p>${ fewerTagsEscapeHtml( errorMsg ) }</p>
								<button type="button" class="notice-dismiss">
									<span class="screen-reader-text">${ fewerTagsEscapeHtml( fewerTags.dismissText ) }</span>
								</button>
							</div>`;
					document.querySelector( '#ajax-response .notice-dismiss' ).addEventListener( 'click', ( event ) => {
						event.target.closest( '.notice' ).remove();
					} );
					tb_remove();
					window.scrollTo( 0, 0 );
					return;
				}

				// Remove the option from the list of terms and from the backup, as it must not exist there in case we want to merge more terms and should not be added back to the list.
				FTbackup = {};
				FTterms = fewerTagsRemoveObjectByValue( FTterms, response.data.source_id );

				// Hide the row from the table.
				const sourceRow = document.getElementById( `tag-${ parseInt( response.data.source_id ) }` );
				if ( sourceRow ) {
					sourceRow.style.display = 'none';
				}

				document.getElementById( 'ajax-response' )
					.innerHTML = `
						<div class="notice notice-success is-dismissible">
							<p>${ response.data.msg }</p>
							<button type="button" class="notice-dismiss">
								<span class="screen-reader-text">${ fewerTagsEscapeHtml( fewerTags.dismissText ) }</span>
							</button>
						</div>`;

				const elTr = document.querySelector( `tr#tag-${ parseInt( response.data.target_id ) } td.column-posts a` );
				if ( elTr ) {
					elTr.textContent = response.data.target_count;
				}

				// Add event listener to the dismiss button.
				document.querySelector( '#ajax-response .notice-dismiss' ).addEventListener( 'click', ( event ) => {
					event.target.closest( '.notice' ).remove();
				} );

				tb_remove();

				window.scrollTo( 0, 0 );
			},
		} );
	} );
} );

// When the modal closes, we need to show all options again,
// So they are available for the next merge.
// We can do that by using a MutationObserver, and checking
// if the modal-open class gets removed from the <body>.
let fewerTagsModalWasOpen = document.body.classList.contains( 'modal-open' );
const fewerTagsBodyObserver = new MutationObserver( ( mutations ) => {
	mutations.forEach( ( mutation ) => {
		if ( mutation.attributeName === 'class' ) {
			const currentState = mutation.target.classList.contains( 'modal-open' );
			if ( fewerTagsModalWasOpen !== currentState ) {
				fewerTagsModalWasOpen = currentState;
				if ( ! currentState ) {
					// The modal was closed, so we can restore the removed option from the backup.
					FTterms.push( FTbackup );
				}
			}
		}
	} );
} );
fewerTagsBodyObserver.observe( document.body, {
	attributes: true,
	attributeOldValue: true,
	attributeFilter: [ 'class' ],
} );
