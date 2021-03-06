const { gql } = require( 'apollo-server-hapi' );
const sitematrix = require( '../utils/sitematrix' );
const getCodes = require( '../utils/codes' );
const { resolvers: siteResolvers } = require( '../resolvers/site' );
const languageResolver = require( '../resolvers/language' );

const schema = Promise.resolve().then( async () => {
	const { sites } = await sitematrix;

	const codes = getCodes( sites );

	const siteTypes = [ ...codes.entries() ].map( ( [ code, options ] ) => {
		if ( options.multi ) {
			return `
				${code} (
					"If no language is specified, the language tag from the 'Accept-Language' header will be used."
					language: ID
				): SiteLink
			`;
		}

		return `
			${code}: SiteLink
		`;
	} );

	return gql`
		type SiteLinkMap {
			${siteTypes.join( '' )}
			sites: [SiteLink!]!

			language (
				"If no code is specified, the language tag from the 'Accept-Language' header will be used."
				code: ID
			): SiteLinkLanguage
			languages: [SiteLinkLanguage!]!
		}
		type SiteLinkLanguage {
			# Language. GraphQL doesn't support type inheritence.
			code: ID!
			name: String!
			localname: String!
			dir: String!
			site(code: ID!): SiteLink
			sites: [SiteLink!]!
		}
		type EntityLabel {
			language: String!
			value: String!
		}
		type SiteLink {
			# Site (sans 'page' which is different). GraphQL doesn't support type inheritence.
			dbname: ID!
			url: String!
			code: String!
			sitename: String!
			closed: Boolean!
			fishbowl: Boolean!
			private: Boolean!
			language: Language
			# Page with no argument since it's provided by the sitelink.
			page: Page
		}
		type Reference {
			hash: String
			snaks(property: ID): [Snak!]!
		}
		type Snak {
			snaktype: String
			property: Entity
			hash: String
			datatype: String
			datavalue: SnakValue
		}
		interface SnakValue {
			type: String
		}
		type SnakValueString implements SnakValue {
			value: String
			# SnakValue
			type: String
		}
		type SnakValueEntity implements SnakValue {
			value: Entity
			# SnakValue
			type: String
		}
		type SnakValuePage implements SnakValue {
			value: Page
			# SnakValue
			type: String
		}
		type SnakValueGlobeCoordinate implements SnakValue {
			value: SnakValueGlobeCoordinateValue
			# SnakValue
			type: String
		}
		type SnakValueGlobeCoordinateValue {
			latitude: Float
			longitude: Float
			precision: Float
			# altitude is not documented.
			# altitude: Float
			globe: Entity
		}
		type SnakValueMonolingualText implements SnakValue {
			value: SnakValueMonolingualTextValue
			# SnakValue
			type: String
		}
		type SnakValueMonolingualTextValue {
			text: String
			language: String
		}
		type SnakValueQuantity implements SnakValue {
			value: SnakValueQuantityValue
			# SnakValue
			type: String
		}
		type SnakValueQuantityValue {
			amount: String
			unit: Entity
		}
		type SnakValueTime implements SnakValue {
			value: SnakValueTimeValue
			# SnakValue
			type: String
		}
		type SnakValueTimeValue {
			time: String
			timezone: Int
			before: Int
			after: Int
			precision: Int
			calendarmodel: Entity
		}
		type Claim {
			mainsnak: Snak
			type: String
			id: ID
			rank: String
			qualifiers(property: ID): [Snak!]!
			references: [Reference!]!
		}
		type Entity {
			pageid: Int
			ns: Int
			title: String
			lastrevid: Int
			modified: String
			type: String
			id: ID
			label (
				"If no language is specified, the language tag from the 'Accept-Language' header will be used."
				language: String
			): EntityLabel
			labels: [EntityLabel!]!
			description (
				"If no language is specified, the language tag from the 'Accept-Language' header will be used."
				language: String
			): EntityLabel
			descriptions: [EntityLabel!]!
			alias (
				"If no language is specified, the language tag from the 'Accept-Language' header will be used."
				language: String
			): [EntityLabel!]!
			aliases: [EntityLabel!]!
			claims(property: ID): [Claim!]!
			sitelinks: SiteLinkMap
		}
	`;
} );

const infoResolver = prop => async ( { id, __site: { dbname } }, args, { dataSources } ) => {
	const entity = await dataSources[ dbname ].getEntity( id, 'info' );

	if ( !entity ) {
		return null;
	}

	return entity[ prop ];
};

const multiLabelReducer = labels => Object.values( labels ).reduce( ( acc, label ) => {
	if ( !Array.isArray( label ) ) {
		return [
			...acc,
			label
		];
	}

	return [
		...acc,
		...label
	];
}, [] );

const labelResolver = ( prop, multi = false ) => async (
	{ id, __site: { dbname } },
	{ language },
	{ dataSources, languages: acceptLanguages }
) => {
	const entity = await dataSources[ dbname ].getEntity( id, prop, language || acceptLanguages );

	if ( !entity ) {
		return multi ? [] : null;
	}

	if ( !( prop in entity ) ) {
		return multi ? [] : null;
	}

	if ( language ) {
		if ( language in entity[ prop ] ) {
			return entity[ prop ][ language ];
		}

		return multi ? [] : null;
	}

	// Property value is either an object, or an array of objects, reduce!
	let labels = multiLabelReducer( entity[ prop ] );

	const preferedLabels = labels.filter( label => (
		// Remove irelevant sites.
		acceptLanguages.includes( label.language )
	) ).sort( ( a, b ) => (
		// Sort by preference.
		acceptLanguages.findIndex(
			tag => tag === a.language
		) - acceptLanguages.findIndex(
			tag => tag === b.language
		)
	) );

	if ( preferedLabels.length === 0 ) {
		return multi ? [] : null;
	}

	// Return the first item from the list.
	if ( !multi ) {
		return preferedLabels[ 0 ];
	}

	// Return the top items from the list, but ensure they are all the same language.
	const topLanguage = preferedLabels[ 0 ].language;
	return preferedLabels.filter( label => label.language === topLanguage );
};

const labelsResolver = prop => async ( { id, __site: { dbname } }, args, { dataSources } ) => {
	const entity = await dataSources[ dbname ].getEntity( id, prop, '*' );

	if ( !entity ) {
		return [];
	}

	if ( !( prop in entity ) ) {
		return [];
	}

	return multiLabelReducer( entity[ prop ] );
};

const resolveSiteLink = callback => async ( sitelinks, args, info, context ) => {
	const site = callback( sitelinks, args, info, context );

	if ( !site ) {
		return null;
	}

	if ( !( site.dbname in sitelinks ) ) {
		return null;
	}

	return {
		...site,
		__sitelink: sitelinks[ site.dbname ]
	};
};

const resolveSiteLinks = callback => async ( sitelinks, args, info, context ) => (
	( await callback( sitelinks, args, info, context ) ).map( ( site ) => {
		if ( !( site.dbname in sitelinks ) ) {
			return null;
		}

		return {
			...site,
			__sitelink: sitelinks[ site.dbname ]
		};
	} ).filter( data => !!data )
);

const siteLinkLanguage = ( sitelinks, language, sites ) => {
	const langSiteLinks = sites.filter( site => (
		site.languageCode === language.code && ( site.dbname in sitelinks )
	) );

	if ( langSiteLinks.length === 0 ) {
		return null;
	}

	return {
		...language,
		sites: langSiteLinks.map( site => ( {
			...site,
			__sitelink: sitelinks[ site.dbname ]
		} ) )
	};
};

const resolveLanguageSite = async ( sitelinks, args, info, context ) => {
	const [ language, { sites } ] = await Promise.all( [
		languageResolver( sitelinks, args, info, context ),
		sitematrix
	] );

	return siteLinkLanguage( sitelinks, language, sites );
};

const resolveLanguageSites = async ( sitelinks ) => {
	const { languages, sites } = await sitematrix;

	return languages.map(
		language => siteLinkLanguage( sitelinks, language, sites )
	).filter( language => !!language );
};

const resolvePropertyItems = prop => ( obj, { property } ) => {
	const { [ prop ]: set, __site } = obj;

	if ( !set ) {
		return [];
	}

	if ( property ) {
		return ( set[ property ] || [] ).map( item => ( {
			...item,
			__site
		} ) );
	}

	// If the order is specificed, then use that order.
	const order = `${prop}-order`;
	if ( obj[ order ] ) {
		const { [ order ]: list } = obj;
		return list.reduce( ( acc, property ) => [
			...acc,
			...( set[ property ] || [] ).map( item => ( {
				...item,
				__site
			} ) )
		], [] );
	}

	// If there is no order, then just use the order they were returned in.
	return Object.values( set ).reduce( ( acc, items ) => [
		...acc,
		...items.map( item => ( {
			...item,
			__site
		} ) )
	], [] );
};

const resolveEntityFromUri = prop => ( { __site, [ prop ]: uri } ) => {
	const url = new URL( uri );
	const id = url.pathname.split( '/' ).slice( -1 ).pop();

	return {
		__site,
		id
	};
};

const attachSiteToValue = ( { __site, value } ) => ( {
	__site,
	...value
} );

const resolvers = Promise.resolve().then( async () => {
	const { sites } = await sitematrix;

	const siteResolverMap = await siteResolvers();
	for ( const key in siteResolverMap ) {
		siteResolverMap[ key ] = resolveSiteLink( siteResolverMap[ key ] );
	}

	return {
		SiteLink: {
			page: ( site ) => {
				const { __sitelink: sitelink } = site;

				if ( !( 'title' in sitelink ) ) {
					return null;
				}

				return {
					__site: site,
					title: sitelink.title
				};
			}
		},
		SiteLinkMap: {
			...siteResolverMap,
			sites: resolveSiteLinks( () => sites ),
			language: resolveLanguageSite,
			languages: resolveLanguageSites
		},
		SiteLinkLanguage: {
			site: ( language, { code } ) => language.sites.find( site => site.code === code )
		},
		Reference: {
			snaks: resolvePropertyItems( 'snaks' )
		},
		Claim: {
			// Pass the __site to the mainsnak.
			mainsnak: ( { mainsnak, __site } ) => ( {
				...mainsnak || {},
				__site
			} ),
			qualifiers: resolvePropertyItems( 'qualifiers' ),
			// Pass the __site to the property.
			references: ( { __site, references } ) => {
				return ( references || [] ).map( reference => ( {
					...reference,
					__site
				} ) );
			}
		},
		Snak: {
			// Pass the __site to the property.
			property: ( { __site, property: id } ) => ( {
				__site,
				id
			} ),
			// Pass the datatype to the datavalue.
			datavalue: ( { __site, datatype, datavalue } ) => {
				if ( !datavalue ) {
					return null;
				}

				return {
					__site,
					__datatype: datatype,
					...datavalue
				};
			}
		},
		SnakValue: {
			__resolveType: ( obj ) => {
				// If there is no object, then it is not a value Snak.
				if ( !obj ) {
					return null;
				}

				const { __datatype: datatype } = obj;
				const { type } = obj;

				switch ( type ) {
					case 'string':
						switch ( datatype ) {
							case 'commonsMedia':
							case 'geo-shape':
							case 'tabular-data':
								return 'SnakValuePage';
							default:
								return 'SnakValueString';
						}
					case 'monolingualtext':
						return 'SnakValueMonolingualText';
					case 'wikibase-entityid':
						return 'SnakValueEntity';
					case 'quantity':
						return 'SnakValueQuantity';
					case 'globecoordinate':
						return 'SnakValueGlobeCoordinate';
					case 'time':
						return 'SnakValueTime';
					default:
						// Unkown type.
						return null;
				}
			}
		},
		SnakValueEntity: {
			value: attachSiteToValue
		},
		SnakValuePage: {
			// Attach the site to the value.
			value: async ( { __site, __datatype: datatype, value } ) => {
				const { sites } = await sitematrix;

				const commons = sites.find( site => site.dbname === 'commonswiki' );

				switch ( datatype ) {
					case 'commonsMedia':
						return {
							__site: commons,
							title: `File:${value}`
						};
					case 'geo-shape':
					case 'tabular-data':
						return {
							__site: commons,
							title: value
						};
					default:
						return {
							__site,
							title: value
						};
				}
			}
		},
		SnakValueGlobeCoordinate: {
			value: attachSiteToValue
		},
		SnakValueGlobeCoordinateValue: {
			// Get the entity id from the URI.
			globe: resolveEntityFromUri( 'globe' )
		},
		SnakValueQuantity: {
			value: attachSiteToValue
		},
		SnakValueQuantityValue: {
			unit: resolveEntityFromUri( 'unit' )
		},
		SnakValueTime: {
			value: attachSiteToValue
		},
		SnakValueTimeValue: {
			calendarmodel: resolveEntityFromUri( 'calendarmodel' )
		},
		Entity: {
			pageid: infoResolver( 'pageid' ),
			ns: infoResolver( 'ns' ),
			title: infoResolver( 'title' ),
			lastrevid: infoResolver( 'lastrevid' ),
			modified: infoResolver( 'modified' ),
			type: infoResolver( 'type' ),
			label: labelResolver( 'labels' ),
			labels: labelsResolver( 'labels' ),
			description: labelResolver( 'descriptions' ),
			descriptions: labelsResolver( 'descriptions' ),
			alias: labelResolver( 'aliases', true ),
			aliases: labelsResolver( 'aliases' ),
			claims: async ( { id, __site }, { property }, { dataSources } ) => {
				const entity = await dataSources[ __site.dbname ].getEntity( id, 'claims' );

				if ( !entity || !entity.claims ) {
					return [];
				}

				return resolvePropertyItems( 'claims' )( { ...entity, __site }, { property } );
			},
			sitelinks: async ( { id, __site: { dbname } }, args, { dataSources } ) => {
				const entity = await dataSources[ dbname ].getEntity( id, 'sitelinks' );

				if ( !entity ) {
					return {};
				}

				return entity.sitelinks;
			}
		}
	};
} );

module.exports = {
	schema,
	resolvers
};
