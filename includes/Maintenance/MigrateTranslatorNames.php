<?php

namespace TranslationManager\Maintenance;

use LoggedUpdateMaintenance;
use TranslationManager\Personnel;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Migrates translator names from tms_translator to tm_personnel records
 * and updates tms_translator_id accordingly
 */
class MigrateTranslatorNames extends LoggedUpdateMaintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Migrates translator names to the tm_personnel table' );
		$this->setBatchSize( 100 );
	}

	/**
	 * Get the update key name to go in the update log table
	 *
	 * @return string
	 */
	protected function getUpdateKey(): string {
		return 'migrate-translator-names';
	}

	/**
	 * Do the actual work
	 *
	 * @return bool True to log the update as done
	 */
	protected function doDBUpdates(): bool {
		$dbw = $this->getDB( DB_PRIMARY );

		$this->output( "Migrating translator names to tm_personnel table...\n" );

		// Step 1: Get all unique translator names
		$res = $dbw->select(
			'tm_status',
			[ 'DISTINCT tms_translator' ],
			$dbw->makeList(
				[
					'tms_translator IS NOT NULL',
					'tms_translator != ""',
					'tms_translator_id IS NULL'
				],
				LIST_AND
			)
		);

		$translatorMap = [];
		$uniqueCount = $res->numRows();
		$this->output( "Found $uniqueCount unique translator names to process\n" );

		// Step 2: Create or update personnel records for each unique name
		$this->beginTransaction( $dbw, __METHOD__ );
		foreach ( $res as $row ) {
			$cleanName = trim( $row->tms_translator );
			// Remove invisible characters and normalize whitespace
			$cleanName = preg_replace( '/\s+/', ' ', preg_replace( '/[\x00-\x1F\x7F\xA0]/u', '', $cleanName ) );

			$personnel = Personnel::getByName( $cleanName );

			// If not, create a new personnel record
			if ( !$personnel ) {
				$personnel = new Personnel();
				$personnel->setName( $cleanName );
				// Inactive by default
				$personnel->setIsActive( false );
				$personnel->setTypes( [ 'translator' ] );
				$personnel->save();

				$personnelId = $personnel->getId();
				$this->output( "	Created personnel record for '$cleanName' (ID: $personnelId)\n" );
			}

			// Build a mapping of translator names to personnel IDs
			$translatorMap[$row->tms_translator] = $personnel->getId();
		}

		$this->commitTransaction( $dbw, __METHOD__ );

		// Step 3: Update all tm_status records using the mapping
		$batchSize = $this->getBatchSize();
		$start = 0;
		$totalUpdated = 0;

		do {
			$res = $dbw->select(
				'tm_status',
				[ 'tms_page_id', 'tms_lang', 'tms_translator' ],
				$dbw->makeList(
					[
						'tms_translator IS NOT NULL',
						'tms_translator != ""',
						'tms_translator_id IS NULL'
					],
					LIST_AND
				),
				__METHOD__,
				[
					'LIMIT' => $batchSize,
					'OFFSET' => $start,
				]
			);

			if ( $res->numRows() === 0 ) {
				break;
			}

			$this->beginTransaction( $dbw, __METHOD__ );
			foreach ( $res as $row ) {
				if ( isset( $translatorMap[$row->tms_translator] ) ) {
					$dbw->update(
						'tm_status',
						[ 'tms_translator_id' => $translatorMap[$row->tms_translator] ],
						[
							'tms_page_id' => $row->tms_page_id,
							'tms_lang' => $row->tms_lang
						],
						__METHOD__
					);
					$totalUpdated++;
				}
			}
			$this->commitTransaction( $dbw, __METHOD__ );

			$start += $batchSize;
			$this->output( "	Updated $totalUpdated records...\n" );

		} while ( $res->numRows() === $batchSize );

		$this->output( "Migration completed. Updated $totalUpdated total records.\n" );
		return true;
	}
}

$maintClass = MigrateTranslatorNames::class;
require_once RUN_MAINTENANCE_IF_MAIN;
