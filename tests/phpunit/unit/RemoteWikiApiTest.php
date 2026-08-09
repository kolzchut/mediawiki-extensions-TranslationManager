<?php

namespace TranslationManager\Tests\Unit;

use HashConfig;
use JobQueueGroup;
use JobSpecification;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use StatusValue;
use TranslationManager\RemoteWikiApi;

/**
 * @covers \TranslationManager\RemoteWikiApi
 */
class RemoteWikiApiTest extends MediaWikiUnitTestCase {

	private const DEFAULT_CONFIG = [
		'TranslationManagerTargetWikiApiURL' => 'https://target.example/$1/api.php',
		'TranslationManagerTargetWikiUserName' => 'BotUser@TranslationManager',
		'TranslationManagerTargetWikiUserPassword' => 'secret',
	];

	private const PAGE_MISSING = [ 'query' => [ 'pages' => [ '-1' => [ 'missing' => '' ] ] ] ];
	private const PAGE_EXISTS = [ 'query' => [ 'pages' => [ '1' => [ 'pageid' => 1 ] ] ] ];
	private const PAGE_REDIRECT = [ 'query' => [ 'pages' => [ '1' => [ 'pageid' => 1, 'redirect' => '' ] ] ] ];

	private const LOGIN_TOKEN = [ 'query' => [ 'tokens' => [ 'logintoken' => 'LOGINTOK+\\' ] ] ];
	private const LOGIN_SUCCESS = [ 'login' => [ 'result' => 'Success' ] ];
	private const CSRF_TOKEN = [ 'query' => [ 'tokens' => [ 'csrftoken' => 'CSRFTOK+\\' ] ] ];
	private const EDIT_SUCCESS = [ 'edit' => [ 'result' => 'Success', 'pageid' => 42 ] ];
	private const MOVE_SUCCESS = [ 'move' => [ 'from' => 'Old', 'to' => 'New' ] ];

	/**
	 * Build an HttpRequestFactory mock that returns a fresh MWHttpRequest mock
	 * for each call, yielding the supplied response bodies in order.
	 *
	 * @param array[] $responses Sequence of JSON-serializable response payloads.
	 * @param array &$captured Captured (url, options) for each call.
	 */
	private function makeHttpFactory( array $responses, ?array &$captured = null ): HttpRequestFactory {
		if ( $captured === null ) {
			$captured = [];
		}
		$factory = $this->createMock( HttpRequestFactory::class );
		$callIndex = 0;
		$factory->method( 'create' )->willReturnCallback(
			function ( $url, $options = [] ) use ( $responses, &$callIndex, &$captured ) {
				$payload = $responses[$callIndex] ?? [];
				$captured[] = [ 'url' => $url, 'options' => $options ];
				$callIndex++;

				$request = $this->createMock( MWHttpRequest::class );
				$request->method( 'execute' )->willReturn( StatusValue::newGood() );
				$request->method( 'getContent' )->willReturn( json_encode( $payload ) );
				$request->method( 'getResponseHeaders' )->willReturn( [] );
				return $request;
			}
		);
		return $factory;
	}

	private function makeApi(
		HttpRequestFactory $factory,
		?JobQueueGroupFactory $jobFactory = null,
		array $extraConfig = [],
		array $localDatabases = []
	): RemoteWikiApi {
		return new RemoteWikiApi(
			'ar',
			new HashConfig( $extraConfig + self::DEFAULT_CONFIG ),
			$factory,
			$jobFactory ?? $this->createMock( JobQueueGroupFactory::class ),
			$localDatabases
		);
	}

	public function testTargetExistsReturnsFailedExists(): void {
		$factory = $this->makeHttpFactory( [ self::PAGE_EXISTS ], $captured );
		$result = $this->makeApi( $factory )->updateRedirect( null, 'NewSuggestion', 'Origin' );

		$this->assertSame( 'failed-exists', $result );
		$this->assertCount( 1, $captured, 'No login or write should happen when target already exists' );
	}

	public function testCreateWhenNoOldAndTargetMissing(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING,   // status of new
			self::LOGIN_TOKEN,    // login-token fetch
			self::LOGIN_SUCCESS,  // login
			self::CSRF_TOKEN,     // csrf-token fetch
			self::EDIT_SUCCESS,   // edit
		], $captured );

		$result = $this->makeApi( $factory )->updateRedirect( null, 'NewSuggestion', 'Origin' );

		$this->assertSame( 'created', $result );
		$this->assertCount( 5, $captured );

		$editCall = $captured[4];
		$this->assertSame( 'https://target.example/ar/api.php', $editCall['url'] );
		$this->assertSame( 'POST', $editCall['options']['method'] );
		$post = $editCall['options']['postData'];
		$this->assertSame( 'edit', $post['action'] );
		$this->assertSame( 'NewSuggestion', $post['title'] );
		$this->assertSame( '#REDIRECT [[:he:Origin]]', $post['text'] );
		$this->assertSame( '1', $post['createonly'] );
		$this->assertSame( 'CSRFTOK+\\', $post['token'] );
	}

	public function testMoveWhenOldIsRedirect(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING,   // status of new
			self::PAGE_REDIRECT,  // status of old
			self::LOGIN_TOKEN,
			self::LOGIN_SUCCESS,
			self::CSRF_TOKEN,
			self::MOVE_SUCCESS,   // move
		], $captured );

		$result = $this->makeApi( $factory )->updateRedirect( 'OldSuggestion', 'NewSuggestion', 'Origin' );

		$this->assertSame( 'moved', $result );
		$moveCall = $captured[5];
		$post = $moveCall['options']['postData'];
		$this->assertSame( 'move', $post['action'] );
		$this->assertSame( 'OldSuggestion', $post['from'] );
		$this->assertSame( 'NewSuggestion', $post['to'] );
		$this->assertArrayNotHasKey( 'noredirect', $post, 'Must leave a redirect at the old title (default move behaviour)' );
	}

	public function testMoveDoesNotQueueDoubleRedirectJobWhenTargetWikiIdNotConfigured(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING, self::PAGE_REDIRECT, self::LOGIN_TOKEN,
			self::LOGIN_SUCCESS, self::CSRF_TOKEN, self::MOVE_SUCCESS,
		] );
		$jobFactory = $this->createMock( JobQueueGroupFactory::class );
		$jobFactory->expects( $this->never() )->method( 'makeJobQueueGroup' );

		$this->makeApi( $factory, $jobFactory )
			->updateRedirect( 'OldSuggestion', 'NewSuggestion', 'Origin' );
	}

	public function testMoveQueuesDoubleRedirectJobOnTargetWikiQueueWhenConfigured(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING, self::PAGE_REDIRECT, self::LOGIN_TOKEN,
			self::LOGIN_SUCCESS, self::CSRF_TOKEN, self::MOVE_SUCCESS,
		] );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'push' )->with(
			$this->callback( static function ( $spec ): bool {
				if ( !$spec instanceof JobSpecification ) {
					return false;
				}
				$params = $spec->getParams();
				return $spec->getType() === 'fixDoubleRedirect'
					&& ( $params['reason'] ?? null ) === 'move'
					&& ( $params['redirTitle'] ?? null ) === 'NewSuggestion'
					&& ( $params['title'] ?? null ) === 'OldSuggestion';
			} )
		);

		$jobFactory = $this->createMock( JobQueueGroupFactory::class );
		$jobFactory->expects( $this->once() )
			->method( 'makeJobQueueGroup' )
			->with( 'kz_ar' )
			->willReturn( $jobQueueGroup );

		$result = $this->makeApi(
			$factory,
			$jobFactory,
			[ 'TranslationManagerTargetWikiId' => 'kz_$1' ],
			[ 'kz_he', 'kz_ar', 'kz_ru' ]
		)->updateRedirect( 'OldSuggestion', 'NewSuggestion', 'Origin' );

		$this->assertSame( 'moved', $result );
	}

	public function testMoveDoesNotQueueJobWhenTargetWikiIdIsNotInLocalDatabases(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING, self::PAGE_REDIRECT, self::LOGIN_TOKEN,
			self::LOGIN_SUCCESS, self::CSRF_TOKEN, self::MOVE_SUCCESS,
		] );
		$jobFactory = $this->createMock( JobQueueGroupFactory::class );
		$jobFactory->expects( $this->never() )->method( 'makeJobQueueGroup' );

		$this->makeApi(
			$factory,
			$jobFactory,
			[ 'TranslationManagerTargetWikiId' => 'external_ar' ],
			[ 'kz_he', 'kz_ar', 'kz_ru' ]
		)->updateRedirect( 'OldSuggestion', 'NewSuggestion', 'Origin' );
	}

	public function testCreateWhenOldIsMissing(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING,   // status of new
			self::PAGE_MISSING,   // status of old
			self::LOGIN_TOKEN,
			self::LOGIN_SUCCESS,
			self::CSRF_TOKEN,
			self::EDIT_SUCCESS,
		], $captured );

		$result = $this->makeApi( $factory )->updateRedirect( 'OldSuggestion', 'NewSuggestion', 'Origin' );

		$this->assertSame( 'created', $result );
		$this->assertSame( 'edit', $captured[5]['options']['postData']['action'] );
	}

	public function testArticleExistsWhenOldIsNonRedirectPage(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING,   // status of new
			self::PAGE_EXISTS,    // status of old (not a redirect)
		], $captured );

		$result = $this->makeApi( $factory )->updateRedirect( 'OldSuggestion', 'NewSuggestion', 'Origin' );

		$this->assertSame( 'articleexists', $result );
		$this->assertCount( 2, $captured, 'No login or write should happen when old is a real article' );
	}

	public function testFailedCreateWhenEditApiDoesNotReturnSuccess(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING,
			self::LOGIN_TOKEN,
			self::LOGIN_SUCCESS,
			self::CSRF_TOKEN,
			[ 'error' => [ 'code' => 'articleexists', 'info' => 'The article already exists' ] ],
		], $captured );

		$result = $this->makeApi( $factory )->updateRedirect( null, 'NewSuggestion', 'Origin' );

		$this->assertSame( 'failed-create', $result );
	}

	public function testLoginUsesLoginTokenFromFirstCall(): void {
		$factory = $this->makeHttpFactory( [
			self::PAGE_MISSING,
			self::LOGIN_TOKEN,
			self::LOGIN_SUCCESS,
			self::CSRF_TOKEN,
			self::EDIT_SUCCESS,
		], $captured );

		$this->makeApi( $factory )->updateRedirect( null, 'NewSuggestion', 'Origin' );

		$loginPost = $captured[2]['options']['postData'];
		$this->assertSame( 'login', $loginPost['action'] );
		$this->assertSame( self::DEFAULT_CONFIG['TranslationManagerTargetWikiUserName'], $loginPost['lgname'] );
		$this->assertSame( self::DEFAULT_CONFIG['TranslationManagerTargetWikiUserPassword'], $loginPost['lgpassword'] );
		$this->assertSame( 'LOGINTOK+\\', $loginPost['lgtoken'] );
	}

	public function testApiUrlPlaceholderIsReplacedWithLanguage(): void {
		$factory = $this->makeHttpFactory( [ self::PAGE_EXISTS ], $captured );
		$this->makeApi( $factory )->updateRedirect( null, 'NewSuggestion', 'Origin' );

		$this->assertStringContainsString( '/ar/api.php', $captured[0]['url'] );
	}

	/**
	 * The status editor asks this before deciding which help text to show the translator, so a
	 * wrong answer is a UI that promises a cleanup nobody queued (or warns about one that runs
	 * anyway). It has to agree with what the write path actually does — see
	 * testQueueDecisionMatchesCanQueueDoubleRedirectFix below.
	 *
	 * @dataProvider provideCanQueueDoubleRedirectFix
	 */
	public function testCanQueueDoubleRedirectFix(
		bool $expected,
		array $config,
		array $localDatabases,
		string $lang,
		string $why
	): void {
		$this->assertSame(
			$expected,
			RemoteWikiApi::canQueueDoubleRedirectFix( $lang, new HashConfig( $config ), $localDatabases ),
			$why
		);
	}

	public static function provideCanQueueDoubleRedirectFix(): array {
		$farm = [ 'kz_he', 'kz_ar', 'kz_ru' ];
		return [
			'placeholder resolves into the farm' => [
				true, [ 'TranslationManagerTargetWikiId' => 'kz_$1' ], $farm, 'ar',
				'kz_$1 with lang=ar is kz_ar, which is in $wgLocalDatabases',
			],
			'placeholder resolves per language' => [
				true, [ 'TranslationManagerTargetWikiId' => 'kz_$1' ], $farm, 'ru',
				'the same setting has to answer for every target language',
			],
			'placeholder resolves outside the farm' => [
				false, [ 'TranslationManagerTargetWikiId' => 'kz_$1' ], $farm, 'en',
				'kz_en is not in $wgLocalDatabases, so its queue is unreachable',
			],
			'literal id in the farm' => [
				true, [ 'TranslationManagerTargetWikiId' => 'kz_ar' ], $farm, 'ar',
				'the placeholder is optional',
			],
			'id outside the farm' => [
				false, [ 'TranslationManagerTargetWikiId' => 'external_ar' ], $farm, 'ar',
				'a target outside this install has no queue we can push to',
			],
			'unset' => [
				false, [], $farm, 'ar',
				'the setting is absent, which is its documented default',
			],
			'explicitly null' => [
				false, [ 'TranslationManagerTargetWikiId' => null ], $farm, 'ar',
				'null is the extension.json default value',
			],
			'empty string' => [
				false, [ 'TranslationManagerTargetWikiId' => '' ], $farm, 'ar',
				'an empty id must not be treated as configured',
			],
			'empty local databases' => [
				false, [ 'TranslationManagerTargetWikiId' => 'kz_$1' ], [], 'ar',
				'nothing is reachable when the install lists no wikis',
			],
		];
	}

	/**
	 * The predicate the UI reads and the predicate the write path enforces must be one predicate.
	 * If they drift, the help text starts describing a cleanup that does not happen — the exact
	 * failure the reworded message exists to stop.
	 *
	 * @dataProvider provideQueueDecision
	 */
	public function testQueueDecisionMatchesCanQueueDoubleRedirectFix(
		string $targetWikiId,
		array $localDatabases
	): void {
		$config = [ 'TranslationManagerTargetWikiId' => $targetWikiId ];
		$predicted = RemoteWikiApi::canQueueDoubleRedirectFix(
			'ar', new HashConfig( $config + self::DEFAULT_CONFIG ), $localDatabases
		);

		$jobFactory = $this->createMock( JobQueueGroupFactory::class );
		$jobFactory->expects( $predicted ? $this->once() : $this->never() )
			->method( 'makeJobQueueGroup' )
			->willReturn( $this->createMock( JobQueueGroup::class ) );

		$this->makeApi(
			$this->makeHttpFactory( [
				self::PAGE_MISSING, self::PAGE_REDIRECT, self::LOGIN_TOKEN,
				self::LOGIN_SUCCESS, self::CSRF_TOKEN, self::MOVE_SUCCESS,
			] ),
			$jobFactory,
			$config,
			$localDatabases
		)->updateRedirect( 'OldSuggestion', 'NewSuggestion', 'Origin' );
	}

	public static function provideQueueDecision(): array {
		$farm = [ 'kz_he', 'kz_ar', 'kz_ru' ];
		return [
			'in the farm' => [ 'kz_$1', $farm ],
			'outside the farm' => [ 'external_ar', $farm ],
			'empty id' => [ '', $farm ],
			'no local databases' => [ 'kz_$1', [] ],
		];
	}

	/**
	 * Both help messages the status editor can pick have to exist in every shipped language;
	 * a missing one renders as the raw message key in the form.
	 *
	 * @dataProvider provideMessageFiles
	 */
	public function testSuggestedNameHelpMessagesAreTranslated( string $file ): void {
		$messages = json_decode( file_get_contents( $file ), true );
		$this->assertIsArray( $messages, "$file is not valid JSON" );

		foreach ( [
			'ext-tm-statusitem-suggestedname-help',
			'ext-tm-statusitem-suggestedname-help-manual-cleanup',
		] as $key ) {
			$this->assertArrayHasKey( $key, $messages, "$key missing from $file" );
			$this->assertNotSame( '', trim( $messages[$key] ), "$key is empty in $file" );
		}
	}

	public static function provideMessageFiles(): array {
		$dir = dirname( __DIR__, 3 ) . '/i18n';
		return [
			'en' => [ "$dir/en.json" ],
			'he' => [ "$dir/he.json" ],
		];
	}

	public function testMissingConfigThrows(): void {
		$this->expectException( \MWException::class );
		new RemoteWikiApi(
			'ar',
			new HashConfig( [
				'TranslationManagerTargetWikiApiURL' => null,
				'TranslationManagerTargetWikiUserName' => null,
				'TranslationManagerTargetWikiUserPassword' => null,
			] ),
			$this->createMock( HttpRequestFactory::class )
		);
	}
}
