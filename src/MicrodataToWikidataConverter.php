<?php

namespace Tptools;

use DataValues\DataValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use linclark\MicrodataPHP\MicrodataPhp;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiFactory;
use Mediawiki\Api\Service\PageGetter;
use Serializers\Serializer;
use Wikibase\Api\Service\RevisionGetter;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\ReferenceList;
use Wikibase\DataModel\SiteLink;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;

class MicrodataToWikidataConverter {

	private static $TYPE_MAPPING = [
		'http://schema.org/Book' => 'Q3331189',
		'http://schema.org/Thesis' => 'Q1266946',
		'http://schema.org/PublicationVolume' => 'Q28869365',
		'http://schema.org/Article' => 'Q191067',
		'http://schema.org/Chapter' => 'Q1980247',
		'http://schema.org/Collection' => 'Q3331189',
		'http://schema.org/CreativeWork' => 'Q3331189'
	];

	/** @var RevisionGetter */
	private $wikidataRevisionGetter;
	/** @var ItemId */
	private $wikiItem;
	/** @var Serializer */
	private $itemSerializer;
	/** @var SparqlClient */
	private $sparqlClient;
	/** @var EntityIdParser */
	private $entityUriParser;
	/** @var PageGetter */
	private $commonsPageGetter;

	public function __construct() {
		$wikidataUtils = new WikidataUtils();
		$this->wikidataRevisionGetter = $wikidataUtils->getWikibaseFactory()->newRevisionGetter();
		$this->wikiItem = new ItemId( 'Q15156541' );
		$this->itemSerializer = $wikidataUtils->newSerializerFactory()->newItemSerializer();
		$this->sparqlClient = new SparqlClient();
		$this->entityUriParser = $wikidataUtils->newEntityUriParser();
		$this->commonsPageGetter = ( new MediawikiFactory( new MediawikiApi(
			'https://commons.wikimedia.org/w/api.php'
		) ) )->newPageGetter();
	}

	public function toWikidata( $title ) {
		$microdata = ( new MicrodataPhp( [
			'url' => 'https://fr.wikisource.org/wiki/' . $this->wfUrlencode( $title )
		] ) )->obj();

		$entitiesById = [ null => (object)[ 'properties' => [] ] ];
		foreach ( $microdata->items as $item ) {
			$id = property_exists( $item, 'id' ) ? $item->id : null;
			if ( array_key_exists( $id, $entitiesById ) ) {
				$this->mergeInto( $entitiesById[$id], $item );
			} else {
				$entitiesById[$id] = $item;
			}
		}

		// We find the Wikidata revision
		$revision = $this->wikidataRevisionGetter->getFromSiteAndTitle( 'frwikisource', $title );
		if ( !$revision ) {
			// print("$title has no item\n");
			$item = new Item();
			$item->getSiteLinkList()->addNewSiteLink( 'frwikisource', $title );
		} else {
			/** @var Item $item */
			$item = $revision->getContent()->getData();
		}

		// We find the entity to process
		$uri = $this->getUriForEntity( $item );
		if ( !array_key_exists( $uri, $entitiesById ) ) {
			// Nothing found
			return $this->itemSerializer->serialize( $item );
		}
		$entity = $entitiesById[$uri];

		// We normalize pagination
		if (
			array_key_exists( 'pageStart', $entity->properties ) &&
			array_key_exists( 'pageEnd', $entity->properties )
		) {
			foreach ( $entity->properties['pageStart'] as $start ) {
				foreach ( $entity->properties['pageEnd'] as $end ) {
					$entity->properties['pagination'][] = trim( $start ) . '-' . trim( $end );
				}
			}
		}

		$fingerprint = $item->getFingerprint();
		if ( array_key_exists( 'name', $entity->properties ) && !$fingerprint->hasLabel( 'fr' ) ) {
			$fingerprint->setLabel( 'fr', $entity->properties['name'][0] );
		}

		$types = property_exists( $entity, 'type' )
			? array_unique( array_map( 'trim', $entity->type ) )
			: [];
		foreach ( $types as $type ) {
			if ( array_key_exists( $type, self::$TYPE_MAPPING ) ) {
				$typeId = new ItemId( self::$TYPE_MAPPING[$type] );
				$this->addStatement( $item, new EntityIdValue( $typeId ), 'P31' );
			}
		}

		$this->addItemRelation( $entity, $item, 'exampleOfWork', 'P629', 'Q386724' );
		$this->addItemRelation( $entity, $item, 'translationOfWork', 'P629', 'Q386724' );
		if ( in_array( 'http://schema.org/Chapter', $types, true ) ) {
			$this->addItemRelation( $entity, $item, 'isPartOf', 'P361' );
		} else {
			$this->addItemRelation( $entity, $item, 'isPartOf', 'P1433' );
		}
		$this->addItemRelation( $entity, $item, 'hasPart', 'P527' );
		$this->addItemRelation( $entity, $item, 'author', 'P50' );
		$this->addItemRelation( $entity, $item, 'translator', 'P655' );
		$this->addItemRelation( $entity, $item, 'illustrator', 'P110' );
		$this->addItemRelation( $entity, $item, 'editor', 'P98' );
		if ( $item->getStatements()->getByPropertyId( new PropertyId( 'P361' ) )->isEmpty() ) {
			$this->addItemRelation( $entity, $item, 'publisher', 'P123', 'Q2085381' );
		}
		if ( !in_array( 'http://schema.org/Chapter', $types, true ) ) {
			$this->addYearRelation( $entity, $item, 'datePublished', 'P577' );
		}
		$this->addLanguageRelation( $entity, $item, 'inLanguage', 'P407' );
		$this->addStringRelation( $entity, $item, 'volumeNumber', 'P478' );
		$this->addStringRelation( $entity, $item, 'pagination', 'P304' );
		$this->addItemRelation( $entity, $item, 'previousItem', 'P155' );
		$this->addItemRelation( $entity, $item, 'nextItem', 'P156' );
		$this->addItemRelation( $entity, $item,
			'http://purl.org/library/placeOfPublication', 'P291', 'Q2221906'
		);
		$this->addScanRelation( $entity, $item );

		// Badge
		$item->getSiteLinkList()->setSiteLink( $this->extractBadge(
			$item->getSiteLinkList()->getBySiteId( 'frwikisource' ),
			$entitiesById
		) );

		return $this->itemSerializer->serialize( $item );
	}

	private function addItemRelation( $entity, $item, $schemaRelation, $wdProperty, $wdClass = null ) {
		if ( array_key_exists( $schemaRelation, $entity->properties ) ) {
			foreach ( $entity->properties[$schemaRelation] as $childEntity ) {
				$authorItemId = $this->getItemIdForEntity( $childEntity, $wdClass );
				if ( $authorItemId !== null ) {
					$this->addStatement( $item, new EntityIdValue( $authorItemId ), $wdProperty );
				}
			}
		}
	}

	private function getItemIdForEntity( $entity, $wdClass = null ) {
		if ( is_string( $entity ) ) {
			$entity = (object)[
				'properties' => [ 'name' => [ $entity ] ]
			];
		}
		if ( property_exists( $entity, 'id' ) ) {
			return $this->entityUriParser->parse( $entity->id );
		}
		if ( array_key_exists( 'mainEntityOfPage', $entity->properties ) ) {
			$title = str_replace(
				'https://fr.wikisource.org/wiki/', '',
				$entity->properties['mainEntityOfPage'][0]
			);
			$revision = $this->wikidataRevisionGetter->getFromSiteAndTitle(
				'frwikisource', urldecode( $title )
			);
			if ( $revision ) {
				return $revision->getContent()->getData()->getId();
			}
		} elseif ( $wdClass !== null && array_key_exists( 'name', $entity->properties ) ) {
			$name = json_encode( $entity->properties['name'][0] );
			$itemIds = $this->sparqlClient->getEntityIds(
				'{ ?entity rdfs:label ' . $name . '@fr } UNION 
				{ ?entity skos:altLabel ' . $name . '@fr } .
				?entity wdt:P31/wdt:P279* wd:' . $wdClass,
				2
			);
			if ( count( $itemIds ) === 1 ) {
				return $itemIds[0];
			}
		}
		return null;
	}

	private function addStatement( Item $item, DataValue $value, $prop ) {
		$propertyId = new PropertyId( $prop );
		if ( !$item->getStatements()->getByPropertyId( $propertyId )->isEmpty() ) {
			// We already have a value, we skip
			return;
		}

		$statement = new Statement( new PropertyValueSnak( $propertyId, $value ) );
		// TODO $statement->setGuid((new GuidGenerator())->newGuid($item->getId()));
		$statement->setReferences( $this->buildImportedFromReferences( $this->wikiItem ) );
		$item->getStatements()->addStatement( $statement );
	}

	private function buildImportedFromReferences( ItemId $itemId ) {
		$snaks = new SnakList();
		$snaks->addSnak( new PropertyValueSnak(
			new PropertyId( 'P143' ),
			new EntityIdValue( $itemId )
		) );
		$references = new ReferenceList();
		$references->addReference( new Reference( $snaks ) );
		return $references;
	}

	private function addYearRelation( $entity, $item, $schemaRelation, $wdProperty ) {
		if ( array_key_exists( $schemaRelation, $entity->properties ) ) {
			foreach ( $entity->properties[$schemaRelation] as $year ) {
				if ( is_numeric( $year ) && strlen( $year ) === 4 ) {
					$date = new TimeValue(
						'+' . $year . '-00-00T00:00:00Z',
						0, 0, 0,
						TimeValue::PRECISION_YEAR,
						TimeValue::CALENDAR_GREGORIAN
					);
					$this->addStatement( $item, $date, $wdProperty );
				}
				// TODO: broader support?
			}
		}
	}

	private function addLanguageRelation( $entity, $item, $schemaRelation, $wdProperty ) {
		if ( array_key_exists( $schemaRelation, $entity->properties ) ) {
			foreach ( $entity->properties[$schemaRelation] as $languageCode ) {
				$languageCode = json_encode( $languageCode );
				$itemIds = $this->sparqlClient->getEntityIds( '?entity wdt:P305 ' . $languageCode, 2 );
				if ( count( $itemIds ) === 1 ) {
					$this->addStatement( $item, new EntityIdValue( $itemIds[0] ), $wdProperty );
				}
			}
		}
	}

	private function addStringRelation( $entity, $item, $schemaRelation, $wdProperty ) {
		if ( array_key_exists( $schemaRelation, $entity->properties ) ) {
			foreach ( $entity->properties[$schemaRelation] as $string ) {
				$this->addStatement( $item, new StringValue( trim( $string ) ), $wdProperty );
			}
		}
	}

	private function addScanRelation( $entity, $item ) {
		if ( array_key_exists( 'associatedMedia', $entity->properties ) ) {
			foreach ( $entity->properties['associatedMedia'] as $media ) {
				if (
					property_exists( $media, 'properties' ) &&
					array_key_exists( 'mainEntityOfPage', $media->properties )
				) {
					foreach ( $media->properties['mainEntityOfPage'] as $filePage ) {
						$parts = explode( ':', $filePage );
						$fileName = trim( str_replace( '_', ' ', urldecode( $parts[count( $parts ) - 1] ) ) );
						if ( $this->commonsFileExists( $fileName ) ) {
							$this->addStatement( $item, new StringValue( $fileName ), 'P996' );
						} else {
							$this->addStatement( $item, new StringValue(
								preg_replace( '/\/wiki\/[^:]+:/', '/wiki/Index:', $filePage )
							), 'P1957' );
						}
					}
				}
			}
		}
	}

	private function commonsFileExists( $fileName ) {
		return $this->commonsPageGetter->getFromTitle(
			'File:' . $fileName
		)->getRevisions()->getLatest() !== null;
	}

	private function mergeInto( $target, $other ) {
		if ( property_exists( $other, 'type' ) ) {
			if ( property_exists( $target, 'type' ) ) {
				$target->type = array_unique( array_merge( $target->type, $other->type ) );
			} else {
				$target->type = $other->type;
			}
		}
		if ( property_exists( $other, 'properties' ) ) {
			if ( property_exists( $target, 'properties' ) ) {
				$target->properties = array_merge( $target->properties, $other->properties );
			} else {
				$target->properties = $other->properties;
			}
		}
	}

	private function extractBadge( SiteLink $siteLink, array $entitiesById ) {
		$siteUri = $this->getUriForSiteLink( $siteLink );
		if ( array_key_exists( $siteUri, $entitiesById ) ) {
			$linkEntity = $entitiesById[$siteUri];
			if ( array_key_exists( 'http://wikiba.se/ontology#badge', $linkEntity->properties ) ) {
				foreach ( $linkEntity->properties['http://wikiba.se/ontology#badge'] as $badge ) {
					if (
						property_exists( $badge, 'id' ) &&
						strpos( $badge->id, 'http://www.wikidata.org/entity/' ) === 0
					) {
						$id = str_replace( 'http://www.wikidata.org/entity/', '', $badge->id );
						$siteLink = $this->siteLinkWithBadge( $siteLink, new ItemId( $id ) );
					}
				}
			}
		}
		return $siteLink;
	}

	private function siteLinkWithBadge( SiteLink $siteLink, ItemId $badge ) {
		return new SiteLink(
			$siteLink->getSiteId(),
			$siteLink->getPageName(),
			array_unique( array_merge( $siteLink->getBadges(), [ $badge ] ) )
		);
	}

	private function getUriForEntity( EntityDocument $entity ) {
		return $entity->getId() === null ? null : 'http://www.wikidata.org/entity/' . $entity->getId();
	}

	private function getUriForSiteLink( SiteLink $siteLink ) {
		// TODO: improve
		$parts = explode( 'wiki', $siteLink->getSiteId() );
		if ( $parts[1] === '' ) {
			$parts[1] = 'pedia';
		}
		return 'https://' . $parts[0] . '.wiki' . $parts[1] . '.org/wiki/' .
			$this->wfUrlencode( $siteLink->getPageName() );
	}

	/**
	 * From MediaWiki
	 */
	private function wfUrlencode( $s ) {
		return str_ireplace(
			[ '%3B', '%40', '%24', '%21', '%2A', '%28', '%29', '%2C', '%2F', '%7E', '%3A' ],
			[ ';', '@', '$', '!', '*', '(', ')', ',', '/', '~', ':' ],
			urlencode( str_replace( ' ', '_', $s ) )
		);
	}
}
