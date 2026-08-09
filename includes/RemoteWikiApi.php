<?php

namespace TranslationManager;

use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReferenceValue;
use MWException;
use MWHttpRequest;

class RemoteWikiApi {

	private string $apiUrl;
	private string $apiUser;
	private string $apiPassword;
	private ?string $targetWikiId;
	private HttpRequestFactory $httpRequestFactory;
	private JobQueueGroupFactory $jobQueueGroupFactory;
	/** @var string[] List of wiki IDs allowed as local job-queue targets */
	private array $localDatabases;
	/** @var array<string,string> Cookie name => value, managed by us (MW's CookieJar is broken). */
	private array $cookies = [];
	private ?string $csrfToken = null;

	/**
	 * @param string $lang
	 * @param Config|null $config Optional config override (defaults to TranslationManager config)
	 * @param HttpRequestFactory|null $httpRequestFactory Optional HTTP factory override
	 * @param JobQueueGroupFactory|null $jobQueueGroupFactory Optional job-queue factory override
	 * @param string[]|null $localDatabases Optional override for $wgLocalDatabases (testing)
	 * @throws MWException
	 */
	public function __construct(
		string $lang,
		?Config $config = null,
		?HttpRequestFactory $httpRequestFactory = null,
		?JobQueueGroupFactory $jobQueueGroupFactory = null,
		?array $localDatabases = null
	) {
		$config ??= Hooks::getConfig();

		$apiUrl = $config->get( 'TranslationManagerTargetWikiApiURL' );
		$apiUser = $config->get( 'TranslationManagerTargetWikiUserName' );
		$apiPassword = $config->get( 'TranslationManagerTargetWikiUserPassword' );
		if ( $apiUrl === null || $apiUser === null || $apiPassword === null ) {
			throw new MWException( 'Missing API login details! See README.' );
		}

		$targetWikiId = $config->has( 'TranslationManagerTargetWikiId' )
			? $config->get( 'TranslationManagerTargetWikiId' )
			: null;

		$this->apiUrl = str_replace( '$1', $lang, $apiUrl );
		$this->apiUser = $apiUser;
		$this->apiPassword = $apiPassword;
		$this->targetWikiId = $targetWikiId === null
			? null
			: str_replace( '$1', $lang, $targetWikiId );

		// Touch MediaWikiServices only for the deps the caller did not supply.
		$services = ( $httpRequestFactory === null
				|| $jobQueueGroupFactory === null
				|| $localDatabases === null )
			? MediaWikiServices::getInstance()
			: null;
		$this->httpRequestFactory = $httpRequestFactory
			?? $services->getHttpRequestFactory();
		$this->jobQueueGroupFactory = $jobQueueGroupFactory
			?? $services->getJobQueueGroupFactory();
		$this->localDatabases = $localDatabases
			?? $services->getMainConfig()->get( MainConfigNames::LocalDatabases );
	}

	/**
	 * Whether a move into the $lang target wiki can have its double-redirect cleanup queued.
	 *
	 * Answers the same question `queueDoubleRedirectFix()` asks itself before pushing, but from
	 * config alone, so callers that only need the answer — the status editor, which tells the
	 * translator whether the double redirects a rename leaves behind will be cleaned up — do not
	 * have to build a RemoteWikiApi (which requires API credentials and throws without them).
	 *
	 * Both callers go through `isQueueableTarget()`, so the UI cannot claim a cleanup the write
	 * path would decline to queue.
	 *
	 * @param string $lang Target language code, substituted into the '$1' placeholder
	 * @param Config|null $config Optional config override (defaults to TranslationManager config)
	 * @param string[]|null $localDatabases Optional override for $wgLocalDatabases (testing)
	 * @return bool
	 */
	public static function canQueueDoubleRedirectFix(
		string $lang,
		?Config $config = null,
		?array $localDatabases = null
	): bool {
		$config ??= Hooks::getConfig();
		$targetWikiId = $config->has( 'TranslationManagerTargetWikiId' )
			? $config->get( 'TranslationManagerTargetWikiId' )
			: null;

		return self::isQueueableTarget(
			$targetWikiId === null ? null : str_replace( '$1', $lang, $targetWikiId ),
			$localDatabases ?? MediaWikiServices::getInstance()
				->getMainConfig()->get( MainConfigNames::LocalDatabases )
		);
	}

	/**
	 * The job queue of another wiki is only reachable when that wiki is part of this install.
	 *
	 * @param string|null $resolvedWikiId Target wiki id with '$1' already substituted
	 * @param string[] $localDatabases
	 * @return bool
	 */
	private static function isQueueableTarget( ?string $resolvedWikiId, array $localDatabases ): bool {
		return $resolvedWikiId !== null
			&& $resolvedWikiId !== ''
			&& in_array( $resolvedWikiId, $localDatabases, true );
	}

	/**
	 * @param string|null $oldSuggestion
	 * @param string $newSuggestion
	 * @param string $originTitle
	 * @return string ('failed-exists', 'moved', 'created', 'failed-create', 'articleexists')
	 */
	public function updateRedirect( ?string $oldSuggestion, string $newSuggestion, $originTitle ): string {
		$newSuggestionPageStatus = $this->getPageStatus( $newSuggestion );
		if ( $newSuggestionPageStatus === 'exists' ) {
			return 'failed-exists';
		}

		// If there's no old suggestion, just create the new one
		if ( !$oldSuggestion ) {
			return $this->createNewRedirect( $originTitle, $newSuggestion );
		}

		// There's an old suggestion; behavior depends on its status:
		// - redirect: move it to the new title
		// - missing: ignore it, create a fresh redirect
		// - article (or anything else): refuse
		$oldSuggestionPageStatus = $this->getPageStatus( $oldSuggestion );
		if ( $oldSuggestionPageStatus === 'redirect' ) {
			return $this->moveRedirect( $oldSuggestion, $newSuggestion );
		} elseif ( $oldSuggestionPageStatus === 'missing' ) {
			return $this->createNewRedirect( $originTitle, $newSuggestion );
		}

		return 'articleexists';
	}

	/**
	 * @return string ('redirect', 'missing', 'exists')
	 */
	private function getPageStatus( string $pageName ): string {
		$response = $this->apiGet( [
			'action' => 'query',
			'titles' => $pageName,
			'prop' => 'info',
		] );
		$pages = $response['query']['pages'] ?? [];
		foreach ( $pages as $page ) {
			if ( isset( $page['redirect'] ) ) {
				return 'redirect';
			}
			if ( isset( $page['missing'] ) ) {
				return 'missing';
			}
		}
		return 'exists';
	}

	/**
	 * @return string ('created', 'failed-create')
	 */
	private function createNewRedirect( string $originTitle, string $redirectTitle ): string {
		$response = $this->apiPostWithToken( [
			'action' => 'edit',
			'title' => $redirectTitle,
			'text' => '#REDIRECT [[:he:' . $originTitle . ']]',
			'summary' => 'יצירת הפניה עבור תרגום מוצע',
			'createonly' => '1',
			'bot' => '1',
		] );
		return ( ( $response['edit']['result'] ?? null ) === 'Success' ) ? 'created' : 'failed-create';
	}

	/**
	 * @return string ('moved', 'articleexists')
	 */
	private function moveRedirect( string $from, string $to ): string {
		$response = $this->apiPostWithToken( [
			'action' => 'move',
			'from' => $from,
			'to' => $to,
			'reason' => 'התרגום השתנה',
		] );
		if ( isset( $response['move']['from'] ) ) {
			$this->queueDoubleRedirectFix( $from, $to );
			return 'moved';
		}
		// MediaWiki returns errors.code = 'articleexists' when target page exists as an article
		if ( ( $response['error']['code'] ?? null ) === 'articleexists' ) {
			return 'articleexists';
		}
		return 'articleexists';
	}

	/**
	 * Queue a fixDoubleRedirect job on the target wiki's queue.
	 *
	 * After a move, MediaWiki leaves a redirect at the old title. When the moved page is itself
	 * a redirect (our case: $oldTitle → $newTitle → :he:Origin), that leave-behind becomes a
	 * double redirect. ApiMove does not queue DoubleRedirectJob automatically (unlike
	 * SpecialMovePage), so we push one ourselves — but only if the target wiki is part of the
	 * same MediaWiki install (in $wgLocalDatabases), since the job queue must be reachable.
	 */
	private function queueDoubleRedirectFix( string $oldTitle, string $newTitle ): void {
		if ( !self::isQueueableTarget( $this->targetWikiId, $this->localDatabases ) ) {
			return;
		}

		// Suggestions are always bare main-namespace titles; build the PageReference directly.
		// Avoid Title::newFromText because it parses against the local wiki's namespace map,
		// not the target wiki's.
		$dbKey = strtr( trim( $oldTitle ), ' ', '_' );
		if ( $dbKey === '' ) {
			return;
		}

		$this->jobQueueGroupFactory
			->makeJobQueueGroup( $this->targetWikiId )
			->push( new JobSpecification(
				'fixDoubleRedirect',
				[
					'reason' => 'move',
					'redirTitle' => $newTitle,
				],
				[],
				PageReferenceValue::localReference( NS_MAIN, $dbKey )
			) );
	}

	private function apiGet( array $params ): array {
		$params['format'] = 'json';
		$url = $this->apiUrl . '?' . http_build_query( $params );
		$req = $this->httpRequestFactory->create( $url, [ 'method' => 'GET' ], __METHOD__ );
		return $this->executeJson( $req );
	}

	private function apiPost( array $params ): array {
		$params['format'] = 'json';
		$req = $this->httpRequestFactory->create(
			$this->apiUrl,
			[ 'method' => 'POST', 'postData' => $params ],
			__METHOD__
		);
		return $this->executeJson( $req );
	}

	private function apiPostWithToken( array $params ): array {
		$this->ensureLoggedIn();
		$params['token'] = $this->csrfToken;
		return $this->apiPost( $params );
	}

	private function executeJson( MWHttpRequest $req ): array {
		// MediaWiki's CookieJar silently drops cookies whose Set-Cookie omits a Domain attribute
		// (and triggers a PHP 8.3 deprecation on the way), so we manage cookies ourselves: send
		// our accumulated Cookie header, and parse any Set-Cookie response headers back into
		// $this->cookies.
		if ( $this->cookies ) {
			$req->setHeader( 'Cookie', $this->serializeCookies() );
		}
		$status = $req->execute();
		$this->captureCookies( $req );
		if ( !$status->isOK() ) {
			return [];
		}
		$decoded = json_decode( $req->getContent(), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private function serializeCookies(): string {
		$parts = [];
		foreach ( $this->cookies as $name => $value ) {
			$parts[] = $name . '=' . $value;
		}
		return implode( '; ', $parts );
	}

	private function captureCookies( MWHttpRequest $req ): void {
		$headers = $req->getResponseHeaders();
		$setCookies = $headers['set-cookie'] ?? [];
		if ( !is_array( $setCookies ) ) {
			$setCookies = [ $setCookies ];
		}
		foreach ( $setCookies as $line ) {
			$first = explode( ';', $line, 2 )[0];
			[ $name, $value ] = array_pad( explode( '=', $first, 2 ), 2, '' );
			$name = trim( $name );
			if ( $name === '' || $value === 'deleted' ) {
				continue;
			}
			$this->cookies[$name] = trim( $value );
		}
	}

	private function ensureLoggedIn(): void {
		if ( $this->csrfToken !== null ) {
			return;
		}

		// 1. Get a login token.
		$loginTokenResponse = $this->apiGet( [
			'action' => 'query',
			'meta' => 'tokens',
			'type' => 'login',
		] );
		$loginToken = $loginTokenResponse['query']['tokens']['logintoken'] ?? null;
		if ( $loginToken === null ) {
			throw new MWException( 'Failed to obtain a login token from the target wiki.' );
		}

		// 2. Log in with bot password credentials.
		$loginResponse = $this->apiPost( [
			'action' => 'login',
			'lgname' => $this->apiUser,
			'lgpassword' => $this->apiPassword,
			'lgtoken' => $loginToken,
		] );
		if ( ( $loginResponse['login']['result'] ?? null ) !== 'Success' ) {
			throw new MWException( 'Login to target wiki failed: '
				. ( $loginResponse['login']['reason'] ?? 'unknown reason' ) );
		}

		// 3. Fetch the CSRF token, reused for all subsequent edit/move calls.
		$csrfResponse = $this->apiGet( [
			'action' => 'query',
			'meta' => 'tokens',
			'type' => 'csrf',
		] );
		$csrfToken = $csrfResponse['query']['tokens']['csrftoken'] ?? null;
		if ( $csrfToken === null || $csrfToken === '+\\' ) {
			throw new MWException( 'Failed to obtain a CSRF token from the target wiki.' );
		}
		$this->csrfToken = $csrfToken;
	}
}
