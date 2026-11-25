<?php

namespace TranslationManager\Tests\Integration;

use MediaWiki\Request\FauxRequest;
use SpecialPageTestBase;
use TranslationManager\Personnel;
use TranslationManager\Specials\SpecialPersonnel;

/**
 * Deactivating a person must need a POST with a valid edit token.
 *
 * @group Database
 * @covers \TranslationManager\Specials\SpecialPersonnel
 */
class SpecialPersonnelTest extends SpecialPageTestBase {

	protected function setUp(): void {
		parent::setUp();
		$this->setGroupPermissions( 'user', 'translation-manager-overview', true );
	}

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'TranslationManagerPersonnel' );
	}

	private function createPerson(): int {
		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( Personnel::TABLE_NAME )
			->row( [
				'tmp_name' => 'TranslationManager delete test',
				'tmp_types' => json_encode( [ 'translator' ] ),
				'tmp_languages' => json_encode( [ 'ar' ] ),
				'tmp_is_active' => 1,
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->insertId();
	}

	private function isActive( int $id ): bool {
		return (bool)$this->getDb()->newSelectQueryBuilder()
			->select( 'tmp_is_active' )
			->from( Personnel::TABLE_NAME )
			->where( [ 'tmp_id' => $id ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * @param array $params
	 * @param bool $posted
	 * @return string HTML
	 */
	private function executePersonnel( array $params, bool $posted ): string {
		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( $params, $posted ),
			null,
			$this->getTestUser()->getUser()
		);
		return $html;
	}

	public function testGetShowsConfirmationAndKeepsPersonActive() {
		$id = $this->createPerson();

		$html = $this->executePersonnel( [ 'wpaction' => 'delete', 'wpid' => $id ], false );

		$this->assertTrue( $this->isActive( $id ), 'a GET does not deactivate' );
		$this->assertStringContainsString( SpecialPersonnel::DELETE_FORM_ID, $html );
		$this->assertStringContainsString( 'wpEditToken', $html, 'the confirmation form carries a token' );
	}

	public function testOldStylePostWithBadTokenKeepsPersonActive() {
		$id = $this->createPerson();

		// The field the confirmation form used to post, without this form's identifier
		$this->executePersonnel( [
			'wpaction' => 'delete',
			'wpid' => $id,
			'confirm_delete' => '1',
			'wpEditToken' => 'not-a-token',
		], true );

		$this->assertTrue( $this->isActive( $id ) );
	}

	public function testPostWithBadTokenKeepsPersonActive() {
		$id = $this->createPerson();

		$this->executePersonnel( [
			'wpaction' => 'delete',
			'wpid' => $id,
			'wpFormIdentifier' => SpecialPersonnel::DELETE_FORM_ID,
			// SpecialPageExecutor adds a valid token to a POST that has none; send a bad one.
			'wpEditToken' => 'not-a-token',
		], true );

		$this->assertTrue( $this->isActive( $id ), 'a POST with a bad token does not deactivate' );
	}

	public function testPostWithValidTokenDeactivatesPerson() {
		$id = $this->createPerson();

		// No wpEditToken: SpecialPageExecutor supplies the user's valid one.
		$this->executePersonnel( [
			'wpaction' => 'delete',
			'wpid' => $id,
			'wpFormIdentifier' => SpecialPersonnel::DELETE_FORM_ID,
		], true );

		$this->assertFalse( $this->isActive( $id ), 'a POST with a valid token deactivates' );
	}
}
