<?php

namespace TranslationManager\Tests\Integration;

use DOMDocument;
use DOMXPath;
use ExtensionRegistry;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWikiIntegrationTestCase;
use TranslationManager\Personnel;
use TranslationManager\Specials\Overview\OverviewPager;
use TranslationManager\StatusItem;

/**
 * Renders the overview table for a status item whose free-text fields hold markup.
 *
 * @group Database
 * @covers \TranslationManager\Specials\Overview\OverviewPager
 */
class OverviewPagerTest extends MediaWikiIntegrationTestCase {

	private const MARKUP = '<i data-x=1>';
	private const LANG = 'ar';

	/**
	 * @return int[] Page ID and personnel ID
	 */
	private function seedStatusItem(): array {
		$page = $this->getExistingTestPage( 'TranslationManager overview escaping test' );
		$dbw = $this->getDb();

		$dbw->newInsertQueryBuilder()
			->insertInto( Personnel::TABLE_NAME )
			->row( [
				'tmp_name' => self::MARKUP,
				'tmp_types' => json_encode( [ 'translator', 'editor' ] ),
				'tmp_languages' => json_encode( [ self::LANG ] ),
				'tmp_is_active' => 1,
			] )
			->caller( __METHOD__ )
			->execute();
		$personId = $dbw->insertId();

		$dbw->newInsertQueryBuilder()
			->insertInto( StatusItem::TABLE_NAME )
			->row( [
				'tms_page_id' => $page->getId(),
				'tms_lang' => self::LANG,
				'tms_status' => 'progress',
				'tms_project' => self::MARKUP,
				'tms_comments' => self::MARKUP,
				'tms_suggested_name' => self::MARKUP . ' suggested',
				'tms_translator_id' => $personId,
				'tms_editor_id' => $personId,
			] )
			->caller( __METHOD__ )
			->execute();

		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$dbw->newReplaceQueryBuilder()
				->replaceInto( 'page_props' )
				->uniqueIndexFields( [ 'pp_page', 'pp_propname' ] )
				->row( [
					'pp_page' => $page->getId(),
					'pp_propname' => 'ArticleContentArea',
					'pp_value' => self::MARKUP,
					'pp_sortkey' => null,
				] )
				->caller( __METHOD__ )
				->execute();
		}

		return [ $page->getId(), $personId ];
	}

	/**
	 * @return string The table's HTML
	 */
	private function renderTable(): string {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest() );
		$context->setTitle( SpecialPage::getTitleFor( 'TranslationManagerOverview' ) );
		$context->setUser( $this->getTestSysop()->getUser() );
		$context->setLanguage( 'en' );

		$special = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'TranslationManagerOverview' );
		$special->setContext( $context );

		$pager = new OverviewPager( $special, [
			'lang' => self::LANG,
			'status' => 'all',
			'page_title' => 'TranslationManager overview escaping test',
		] );
		return $pager->getBody();
	}

	public function testFreeTextFieldsAreEscapedInTheTable() {
		$this->seedStatusItem();

		$html = $this->renderTable();

		$doc = new DOMDocument();
		// The table is an HTML fragment; libxml warns about HTML5 tags it does not know
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $doc );

		$this->assertSame( 1, $xpath->query( '//td[contains(@class, "TablePager_col_project")]' )->length,
			'the seeded row is in the table' );
		$this->assertSame( 0, $xpath->query( '//i[@data-x]' )->length, 'no injected <i> element anywhere' );

		$columns = [ 'project', 'comments', 'suggested_name', 'editor_id', 'translator_id' ];
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$columns[] = 'content_area';
		}
		foreach ( $columns as $column ) {
			$cells = $xpath->query( "//td[contains(@class, 'TablePager_col_$column')]" );
			$this->assertSame( 1, $cells->length, "one $column cell" );
			$cell = $cells->item( 0 );
			$this->assertSame( 0, $xpath->query( './/i', $cell )->length, "no <i> element in the $column cell" );
			$this->assertStringContainsString( self::MARKUP, $cell->textContent, "$column shows the text as typed" );
		}
	}
}
