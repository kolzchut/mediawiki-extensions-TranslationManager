<?php

namespace TranslationManager\Tests\Unit;

use MediaWikiUnitTestCase;
use TranslationManager\Specials\Overview\OverviewPager;

/**
 * @covers \TranslationManager\Specials\Overview\OverviewPager::formatValue
 */
class OverviewPagerFormatValueTest extends MediaWikiUnitTestCase {

	private const MARKUP = '<i data-x=1>';

	/**
	 * A pager built without its constructor, which needs a request context and a database.
	 * Every personnel lookup returns the given name.
	 *
	 * @param string|null $personnelName
	 * @return OverviewPager
	 */
	private function newPager( ?string $personnelName = self::MARKUP ): OverviewPager {
		$pager = $this->getMockBuilder( OverviewPager::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getPersonnelName' ] )
			->getMock();
		$pager->method( 'getPersonnelName' )->willReturn( $personnelName );
		return $pager;
	}

	public static function provideFreeTextFields(): array {
		return [
			'project' => [ 'project', self::MARKUP ],
			'comments' => [ 'comments', self::MARKUP ],
			'content area' => [ 'content_area', self::MARKUP ],
			'suggested name' => [ 'suggested_name', self::MARKUP ],
			'editor name' => [ 'editor_id', '3' ],
			'translator name' => [ 'translator_id', '4' ],
		];
	}

	/**
	 * @dataProvider provideFreeTextFields
	 */
	public function testFreeTextIsEscaped( string $field, string $value ) {
		$html = $this->newPager()->formatValue( $field, $value );

		$this->assertSame( htmlspecialchars( self::MARKUP ), $html );
		$this->assertStringNotContainsString( '<i', $html );
	}

	public function testUnknownFieldIsEscaped() {
		$html = $this->newPager()->formatValue( 'some_future_column', self::MARKUP );

		$this->assertStringNotContainsString( '<i', $html );
	}

	public function testMissingValuesPrintNothing() {
		$pager = $this->newPager( null );

		$this->assertSame( '', $pager->formatValue( 'editor_id', null ) );
		$this->assertSame( '', $pager->formatValue( 'project', null ) );
		$this->assertSame( '', $pager->formatValue( 'comments', '' ) );
	}

	public function testHtmlBuiltByThePagerIsKept() {
		// formatRow() builds these columns as HTML before formatValue() sees them
		$actions = '<a href="/x"><i class="fa fa-edit" aria-hidden="true"></i></a>';

		$this->assertSame( $actions, $this->newPager()->formatValue( 'actions', $actions ) );
		$this->assertSame( '', $this->newPager()->formatValue( 'actual_translation', '' ) );
	}
}
