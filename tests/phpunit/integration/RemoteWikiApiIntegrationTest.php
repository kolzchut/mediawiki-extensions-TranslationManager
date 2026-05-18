<?php

namespace TranslationManager\Tests\Integration;

use HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Http\Telemetry;
use MediaWikiIntegrationTestCase;
use TranslationManager\RemoteWikiApi;

/**
 * End-to-end tests against a real target wiki. Opt-in via env vars; skipped otherwise.
 *
 * Required env vars:
 *   TRANSLATIONMANAGER_TEST_API_URL       e.g. http://kz-main-mediawiki/$1/api.php
 *   TRANSLATIONMANAGER_TEST_USER          e.g. TestBot@TMIntegration
 *   TRANSLATIONMANAGER_TEST_PASSWORD      bot-password secret
 *   TRANSLATIONMANAGER_TEST_TARGET_LANG   language code used in $1 (e.g. 'ar')
 *   TRANSLATIONMANAGER_TEST_TARGET_WIKI   wiki/DB id used for verification & cleanup (e.g. 'kz_ar')
 *
 * The bot password must grant: editpage, createeditmovepage (and ideally writeapi/highvolume).
 * The TRANSLATIONMANAGER_TEST_TARGET_WIKI must be a sibling wiki in $wgLocalDatabases, accessible
 * via the LBFactory — used both to verify the page state and to clean up via deleteBatch.php.
 *
 * @group Database
 * @group medium
 * @coversNothing
 */
class RemoteWikiApiIntegrationTest extends MediaWikiIntegrationTestCase {

	private string $apiUrl;
	private string $apiUser;
	private string $apiPassword;
	private string $targetLang;
	private string $targetWikiId;
	private string $titlePrefix;
	/** @var string[] Page titles created during a single test, deleted in tearDown. */
	private array $createdTitles = [];

	/**
	 * Silence the `strlen(null)` deprecation triggered by MW core's own Cookie.php under PHP 8.3
	 * (it stores a null domain when the wiki's Set-Cookie omits Domain=, and re-serializes via
	 * strlen). PHPUnit's `convertDeprecationsToExceptions=true` would otherwise abort each test.
	 */
	private static ?array $previousErrorHandler = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$previous = set_error_handler( static function ( $errno, $errstr, $errfile ) {
			if ( $errno === E_DEPRECATED && str_contains( $errfile, '/includes/libs/Cookie.php' ) ) {
				return true;
			}
			return false;
		}, E_DEPRECATED );
		self::$previousErrorHandler = $previous ? [ $previous ] : null;
	}

	public static function tearDownAfterClass(): void {
		restore_error_handler();
		self::$previousErrorHandler = null;
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();

		$this->apiUrl       = (string)( getenv( 'TRANSLATIONMANAGER_TEST_API_URL' )      ?: '' );
		$this->apiUser      = (string)( getenv( 'TRANSLATIONMANAGER_TEST_USER' )         ?: '' );
		$this->apiPassword  = (string)( getenv( 'TRANSLATIONMANAGER_TEST_PASSWORD' )     ?: '' );
		$this->targetLang   = (string)( getenv( 'TRANSLATIONMANAGER_TEST_TARGET_LANG' )  ?: '' );
		$this->targetWikiId = (string)( getenv( 'TRANSLATIONMANAGER_TEST_TARGET_WIKI' )  ?: '' );

		if ( $this->apiUrl === '' || $this->apiUser === '' || $this->apiPassword === ''
			|| $this->targetLang === '' || $this->targetWikiId === ''
		) {
			$this->markTestSkipped(
				'Integration test requires TRANSLATIONMANAGER_TEST_API_URL, _USER, _PASSWORD, '
				. '_TARGET_LANG and _TARGET_WIKI env vars pointing at a dedicated test wiki '
				. 'with a bot password.'
			);
		}

		// Verify cross-wiki DB access works before doing anything observable.
		try {
			$this->getTargetDb();
		} catch ( \Throwable $e ) {
			$this->markTestSkipped(
				'Cannot reach target wiki DB ' . $this->targetWikiId . ': ' . $e->getMessage()
			);
		}

		// Unique per test invocation so concurrent runs don't collide.
		$this->titlePrefix = 'TMIT_' . wfTimestampNow() . '_' . random_int( 1000, 9999 ) . '_';

		// MediaWikiIntegrationTestCase installs NullHttpRequestFactory which blocks all HTTP.
		// Restore a real factory so RemoteWikiApi can talk to the wiki under test.
		$this->setService( 'HttpRequestFactory', $this->newRealHttpRequestFactory() );
	}

	private function newRealHttpRequestFactory(): HttpRequestFactory {
		return new HttpRequestFactory(
			new ServiceOptions(
				HttpRequestFactory::CONSTRUCTOR_OPTIONS,
				$this->getServiceContainer()->getMainConfig()
			),
			LoggerFactory::getInstance( 'http' ),
			Telemetry::getInstance()
		);
	}

	protected function tearDown(): void {
		if ( $this->createdTitles ) {
			$this->cleanupTitles( $this->createdTitles );
			$this->createdTitles = [];
		}
		parent::tearDown();
	}

	private function newApi(): RemoteWikiApi {
		$config = new HashConfig( [
			'TranslationManagerTargetWikiApiURL'       => $this->apiUrl,
			'TranslationManagerTargetWikiUserName'     => $this->apiUser,
			'TranslationManagerTargetWikiUserPassword' => $this->apiPassword,
		] );
		return new RemoteWikiApi( $this->targetLang, $config );
	}

	private function uniqueTitle( string $suffix ): string {
		$title = $this->titlePrefix . $suffix;
		$this->createdTitles[] = $title;
		return $title;
	}

	private function getTargetDb(): \Wikimedia\Rdbms\IReadableDatabase {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getMainLB( $this->targetWikiId )
			->getConnection( DB_REPLICA, [], $this->targetWikiId );
	}

	/**
	 * @return array{exists:bool, isRedirect:bool, redirectTarget:?string}
	 */
	private function readPageOnTarget( string $title ): array {
		$dbKey = strtr( $title, ' ', '_' );
		$db = $this->getTargetDb();
		$row = $db->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_is_redirect' ] )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_MAIN, 'page_title' => $dbKey ] )
			->caller( __METHOD__ )->fetchRow();
		if ( !$row ) {
			return [ 'exists' => false, 'isRedirect' => false, 'redirectTarget' => null ];
		}
		$target = null;
		if ( (int)$row->page_is_redirect === 1 ) {
			$rd = $db->newSelectQueryBuilder()
				->select( [ 'rd_interwiki', 'rd_title' ] )
				->from( 'redirect' )
				->where( [ 'rd_from' => (int)$row->page_id ] )
				->caller( __METHOD__ )->fetchRow();
			if ( $rd ) {
				$target = ( $rd->rd_interwiki !== '' ? $rd->rd_interwiki . ':' : '' ) . $rd->rd_title;
			}
		}
		return [
			'exists' => true,
			'isRedirect' => (int)$row->page_is_redirect === 1,
			'redirectTarget' => $target,
		];
	}

	/**
	 * Delete test-created pages via the target wiki's action=delete API. The bot has the
	 * 'delete-redirect' right (granted by 'createeditmovepage'), which is enough because every
	 * page our tests create is a redirect.
	 */
	/**
	 * Delete test-created pages by reusing RemoteWikiApi's own session for action=delete.
	 * The bot's `delete-redirect` right (from the `createeditmovepage` grant) is enough — every
	 * page our tests create is a redirect. We piggy-back on RemoteWikiApi via reflection so we
	 * don't duplicate its login/CSRF dance.
	 */
	private function cleanupTitles( array $titles ): void {
		$api = $this->newApi();
		$ref = new \ReflectionClass( $api );
		$ensureLogin = $ref->getMethod( 'ensureLoggedIn' );
		$ensureLogin->setAccessible( true );
		$post = $ref->getMethod( 'apiPostWithToken' );
		$post->setAccessible( true );
		try {
			$ensureLogin->invoke( $api );
		} catch ( \Throwable $e ) {
			return;
		}
		foreach ( array_unique( $titles ) as $title ) {
			$post->invoke( $api, [
				'action' => 'delete',
				'title' => $title,
				'reason' => 'TranslationManager integration test cleanup',
			] );
		}
	}

	public function testCreatesRedirectWhenTargetIsMissing(): void {
		$title = $this->uniqueTitle( 'Create' );

		$result = $this->newApi()->updateRedirect( null, $title, 'בית' );

		$this->assertSame( 'created', $result );
		$page = $this->readPageOnTarget( $title );
		$this->assertTrue( $page['exists'], 'redirect page should exist on target wiki' );
		$this->assertTrue( $page['isRedirect'], 'page should be flagged as a redirect' );
		$this->assertSame( 'he:בית', $page['redirectTarget'] );
	}

	public function testReturnsFailedExistsWhenTargetIsAnArticle(): void {
		$title = $this->uniqueTitle( 'AlreadyAnArticle' );
		// 'failed-exists' is only triggered when the target is a real (non-redirect) article.
		// Create one directly via the API, then verify updateRedirect refuses to clobber it.
		$this->editPageOnTarget( $title, 'This is a real article, not a redirect.' );

		$result = $this->newApi()->updateRedirect( null, $title, 'בית' );

		$this->assertSame( 'failed-exists', $result );
	}

	private function editPageOnTarget( string $title, string $text ): void {
		$api = $this->newApi();
		$ref = new \ReflectionClass( $api );
		$ensureLogin = $ref->getMethod( 'ensureLoggedIn' );
		$ensureLogin->setAccessible( true );
		$ensureLogin->invoke( $api );
		$post = $ref->getMethod( 'apiPostWithToken' );
		$post->setAccessible( true );
		$post->invoke( $api, [
			'action' => 'edit', 'title' => $title, 'text' => $text,
			'summary' => 'TranslationManager integration test setup',
		] );
	}

	public function testMovesExistingRedirectAndLeavesRedirectAtOldTitle(): void {
		$oldTitle = $this->uniqueTitle( 'Old' );
		$newTitle = $this->uniqueTitle( 'New' );
		$this->assertSame( 'created', $this->newApi()->updateRedirect( null, $oldTitle, 'בית' ) );

		$result = $this->newApi()->updateRedirect( $oldTitle, $newTitle, 'בית' );

		$this->assertSame( 'moved', $result );
		$new = $this->readPageOnTarget( $newTitle );
		$this->assertTrue( $new['exists'] && $new['isRedirect'], 'new title should be a redirect' );
		$old = $this->readPageOnTarget( $oldTitle );
		$this->assertTrue(
			$old['exists'] && $old['isRedirect'],
			'old title should remain as a redirect (move was not noredirect)'
		);
	}

	public function testCreatesWhenOldSuggestionDoesNotExist(): void {
		$missingOld = $this->uniqueTitle( 'Missing' );
		$newTitle = $this->uniqueTitle( 'NewFresh' );

		$result = $this->newApi()->updateRedirect( $missingOld, $newTitle, 'בית' );

		$this->assertSame( 'created', $result );
		$this->assertTrue( $this->readPageOnTarget( $newTitle )['isRedirect'] );
		$this->assertFalse( $this->readPageOnTarget( $missingOld )['exists'] );
	}
}
