const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { resolve } = require( 'node:path' );
const { test } = require( 'node:test' );
const vm = require( 'node:vm' );

const source = readFileSync( resolve( __dirname, '../../js/fewer-tags.js' ), 'utf8' );

function loadScript( config, fetch ) {
	const context = vm.createContext( {
		URL,
		console,
		fewerTags: config,
		fetch,
		document: {
			readyState: 'loading',
			addEventListener() {},
			getElementById: () => ( { dataset: { taxonomy: 'not_registered' } } ),
			body: { classList: { contains: () => false } },
		},
		Choices: class {},
		MutationObserver: class { observe() {} },
	} );
	vm.runInContext( source, context );
	return context;
}

for ( const endpoint of [
	'https://example.org/subsite/wp-json/wp/v2/tags',
	'https://example.org/subsite/?rest_route=/wp/v2/tags',
	'https://example.org/subsite/index.php?rest_route=/books/v1/topics&lang=nl',
] ) {
	test( `term pagination preserves the WordPress endpoint: ${ endpoint }`, async () => {
		const requests = [];
		const context = loadScript( { termUrls: { post_tag: endpoint } }, async ( url ) => {
			requests.push( new URL( url ) );
			return {
				ok: true,
				json: async () => requests.length === 1
					? Array.from( { length: 100 }, ( _, index ) => ( { id: index + 1, name: 'Tag', count: 1 } ) )
					: [ { id: 101, name: 'Last tag', count: 2 } ],
			};
		} );
		const terms = await context.fewerTagsGetAllTerms( 'post_tag' );
		assert.equal( terms.length, 101 );
		assert.equal( requests.length, 2 );
		requests.forEach( ( request, index ) => {
			assert.equal( request.searchParams.get( 'per_page' ), '100' );
			assert.equal( request.searchParams.get( 'page' ), String( index + 1 ) );
			request.searchParams.delete( 'per_page' );
			request.searchParams.delete( 'page' );
			const expected = new URL( endpoint );
			expected.searchParams.sort();
			request.searchParams.sort();
			assert.equal( request.toString(), expected.toString() );
		} );
	} );
}

test( 'taxonomy discovery uses its supplied URL and authentication nonce', async () => {
	const endpoint = 'https://example.org/subsite/?rest_route=/wp/v2/taxonomies&context=edit';
	const context = loadScript( { taxonomiesUrl: endpoint, termUrls: {}, restAPInonce: 'test-nonce' }, async ( url, options ) => {
		assert.equal( url, endpoint );
		assert.equal( options.headers['X-WP-Nonce'], 'test-nonce' );
		return { ok: true, json: async () => ( {} ) };
	} );
	assert.equal( ( await context.fewerTagsGetTaxonomies( 'post_tag' ) ).length, 0 );
} );
