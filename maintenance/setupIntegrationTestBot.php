<?php
/**
 * Provision a bot user + bot password for the RemoteWikiApi integration test suite.
 *
 * Usage:
 *   php maintenance/run.php \
 *     extensions/WikiRights/TranslationManager/maintenance/setupIntegrationTestBot.php \
 *     --wiki=<targetWikiId> [--user=<name>] [--app=<appId>] [--force-new-password]
 *
 * What it does:
 *   1. If the user does not exist on the target wiki, inserts a row into the local `user` table
 *      with a `user_id` that does not collide with any existing actor_user in the shared `actor`
 *      table — works around the kz dev setup where actor is shared but `user` is per-wiki and
 *      `createAndPromote.php` therefore fails with `CannotCreateActorException`.
 *   2. Adds the user to the `editor`, `sysop` and `bot` groups (idempotent).
 *   3. Creates a bot password for app id `TMIntegration` with grants
 *      `basic, highvolume, editpage, createeditmovepage`. If one already exists and
 *      --force-new-password is given, the secret is rotated.
 *   4. Prints the env vars the integration test needs.
 *
 * Not for production. This is a dev-only test fixture.
 */

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 4 );
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\BotPassword;

class SetupTranslationManagerIntegrationTestBot extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Provision a bot user + bot password on the target wiki for the RemoteWikiApi ' .
			'integration test suite. Dev-only.'
		);
		$this->addOption( 'user', 'Username to create/use', false, true );
		$this->addOption( 'app', 'Bot-password app id', false, true );
		$this->addOption(
			'force-new-password',
			'Rotate the bot password if one already exists',
			false, false
		);
	}

	public function execute() {
		$userName = $this->getOption( 'user', 'TMIntBot_' . $GLOBALS['wgDBname'] );
		$appId    = $this->getOption( 'app',  'TMIntegration' );

		$services = MediaWikiServices::getInstance();
		$userFactory = $services->getUserFactory();
		$groupManager = $services->getUserGroupManager();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();

		// 1. Ensure the user exists locally.
		$user = $userFactory->newFromName( $userName );
		if ( !$user ) {
			$this->fatalError( "Invalid username: $userName" );
		}
		if ( $user->getId() === 0 ) {
			$this->output( "Creating user $userName on " . $db->getDomainID() . "\n" );
			$this->insertUserAvoidingActorCollision( $db, $userName );
			$user = $userFactory->newFromName( $userName );
			if ( !$user || $user->getId() === 0 ) {
				$this->fatalError( "User creation failed for $userName" );
			}
		} else {
			$this->output( "Reusing existing user $userName (id={$user->getId()})\n" );
		}

		// 2. Ensure groups.
		foreach ( [ 'editor', 'sysop', 'bot' ] as $group ) {
			if ( !in_array( $group, $groupManager->getUserGroups( $user ), true ) ) {
				$groupManager->addUserToGroup( $user, $group );
				$this->output( "  added to group: $group\n" );
			}
		}

		// 3. Create or rotate the bot password.
		$centralId = $services->getCentralIdLookupFactory()->getLookup()
			->centralIdFromLocalUser( $user );
		if ( $centralId === 0 ) {
			$this->fatalError( "Could not resolve a central ID for $userName" );
		}

		$existing = BotPassword::newFromUser( $user, $appId );
		$shouldRotate = $existing === null || $this->hasOption( 'force-new-password' );
		if ( !$shouldRotate ) {
			$this->output(
				"\nBot password already exists for $userName@$appId. " .
				"Re-run with --force-new-password to rotate the secret.\n"
			);
			return;
		}

		$bp = BotPassword::newUnsaved( [
			'centralId'    => $centralId,
			'appId'        => $appId,
			'restrictions' => MWRestrictions::newDefault(),
			'grants'       => [ 'basic', 'highvolume', 'editpage', 'createeditmovepage' ],
		] );
		$secret = BotPassword::generatePassword( $services->getMainConfig() );
		$passObj = $services->getPasswordFactory()->newFromPlaintext( $secret );
		$status = $bp->save( $existing ? 'update' : 'insert', $passObj );
		if ( !$status->isOK() ) {
			$this->fatalError(
				'Bot password save failed: ' . $status->getWikiText( false, false, 'en' )
			);
		}

		$wikiId = $db->getDomainID();
		$this->output(
			"\n=== Integration test env vars ===\n" .
			"TRANSLATIONMANAGER_TEST_USER='{$userName}@{$appId}'\n" .
			"TRANSLATIONMANAGER_TEST_PASSWORD='{$secret}'\n" .
			"TRANSLATIONMANAGER_TEST_TARGET_WIKI='{$wikiId}'\n" .
			"# Also set TRANSLATIONMANAGER_TEST_API_URL and TRANSLATIONMANAGER_TEST_TARGET_LANG\n" .
			"# to match the target wiki, e.g.:\n" .
			"#   TRANSLATIONMANAGER_TEST_API_URL='http://kz-main-nginx/w/\$1/api.php'\n" .
			"#   TRANSLATIONMANAGER_TEST_TARGET_LANG='ar'\n"
		);
	}

	/**
	 * Insert a user row with a user_id that does not collide with any existing actor_user in
	 * the shared `actor` table. Without this, MediaWiki tries to auto-assign a user_id that's
	 * already taken in `actor` (shared) and fails with CannotCreateActorException.
	 */
	private function insertUserAvoidingActorCollision( $db, string $userName ): void {
		$maxActorUser = (int)$db->newSelectQueryBuilder()
			->select( 'MAX(actor_user)' )
			->from( 'actor' )
			->caller( __METHOD__ )->fetchField();
		$maxLocalUser = (int)$db->newSelectQueryBuilder()
			->select( 'MAX(user_id)' )
			->from( 'user' )
			->caller( __METHOD__ )->fetchField();
		$newUserId = max( $maxActorUser, $maxLocalUser ) + 1;

		$ts = $db->timestamp();
		$db->newInsertQueryBuilder()->insertInto( 'user' )->row( [
			'user_id' => $newUserId, 'user_name' => $userName, 'user_real_name' => '',
			'user_email' => '', 'user_password' => '', 'user_newpassword' => '',
			'user_email_authenticated' => null, 'user_email_token' => '',
			'user_email_token_expires' => null, 'user_registration' => $ts,
			'user_editcount' => 0, 'user_touched' => $ts, 'user_token' => '0',
		] )->caller( __METHOD__ )->execute();

		// The actor table is shared; insert only if our chosen user_id isn't already there.
		$existingActor = $db->newSelectQueryBuilder()->select( 'actor_id' )
			->from( 'actor' )->where( [ 'actor_name' => $userName ] )
			->caller( __METHOD__ )->fetchField();
		if ( !$existingActor ) {
			$db->newInsertQueryBuilder()->insertInto( 'actor' )
				->row( [ 'actor_user' => $newUserId, 'actor_name' => $userName ] )
				->caller( __METHOD__ )->execute();
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = SetupTranslationManagerIntegrationTestBot::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
