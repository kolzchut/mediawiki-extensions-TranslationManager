<?php

namespace TranslationManager;

use MWException;
use stdClass;

class Personnel {
	private const VALID_TYPES = [ 'translator', 'editor' ];
	public const TABLE_NAME = 'tm_personnel';
	/** @var int */
	private int $id;
	/** @var string|null */
	private ?string $name = null;
	/** @var ?array */
	private ?array $types = [];
	/** @var ?array */
	private ?array $languages = [];
	/** @var bool */
	private bool $isActive;

	/**
	 * @param int|null $id
	 * @throws MWException
	 */
	public function __construct( ?int $id = null ) {
		if ( $id !== null ) {
			$this->loadFromDatabase( $id );
		}
	}

	/**
	 * @param string $name
	 * @return self|null
	 */
	public static function getByName( string $name ): ?self {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow( self::TABLE_NAME, '*', [ 'tmp_name' => $name ] );

		if ( !$row ) {
			return null;
		}

		return self::newFromRow( $row );
	}

	/**
	 * Create a new object from a database row
	 *
	 * @param stdClass $row Database row
	 * @return self
	 */
	public static function newFromRow( stdClass $row ): self {
		$personnel = new self();
		$personnel->id = $row->tmp_id;
		$personnel->name = $row->tmp_name;
		$personnel->types = json_decode( $row->tmp_types, true );
		$personnel->languages = json_decode( $row->tmp_languages, true );
		$personnel->isActive = (bool)$row->tmp_is_active;
		return $personnel;
	}

	/**
	 * Load personnel data from database
	 *
	 * @param int $id
	 * @return bool
	 * @throws MWException
	 */
	private function loadFromDatabase( int $id ): bool {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow( self::TABLE_NAME, '*', [ 'tmp_id' => $id ] );

		if ( !$row ) {
			throw new MWException( "Personnel with ID $id not found" );
		}

		$personnel = self::newFromRow( $row );
		$this->id = $personnel->id;
		$this->name = $personnel->name;
		$this->types = $personnel->types;
		$this->languages = $personnel->languages;
		$this->isActive = $personnel->isActive;

		return true;
	}

	/**
	 * Save personnel data to database
	 *
	 * @return bool
	 */
	public function save(): bool {
		$dbw = wfGetDB( DB_PRIMARY );

		$data = [
			'tmp_name' => $this->name,
			'tmp_types' => json_encode( $this->types ),
			'tmp_languages' => json_encode( $this->languages ),
			'tmp_is_active' => (int)$this->isActive,
		];

		if ( isset( $this->id ) && $this->id ) {
			$dbw->update( self::TABLE_NAME, $data, [ 'tmp_id' => $this->id ] );
		} else {
			$dbw->insert( self::TABLE_NAME, $data );
			$this->id = $dbw->insertId();
		}

		return true;
	}

	/**
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}

	/**
	 * @param string $name
	 * @return self
	 */
	public function setName( string $name ): self {
		$this->name = $name;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getName(): ?string {
		return $this->name;
	}

	/**
	 * @param array $types
	 * @return self
	 */
	public function setTypes( array $types ): self {
		$this->types = $types;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getTypes(): ?array {
		return $this->types;
	}

	/**
	 * @param array $languages
	 * @return self
	 */
	public function setLanguages( array $languages ): self {
		$this->languages = $languages;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getLanguages(): ?array {
		return $this->languages;
	}

	/**
	 * @param bool $isActive
	 * @return $this
	 */
	public function setIsActive( bool $isActive ): self {
		$this->isActive = $isActive;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function getIsActive(): bool {
		return $this->isActive;
	}

	/**
	 * Check if personnel is of a specific type
	 *
	 * @param string $type
	 * @return bool
	 */
	public function isType( string $type ): bool {
		return in_array( $type, $this->types );
	}

	/**
	 * Check if personnel handles a specific language
	 *
	 * @param string $language
	 * @return bool
	 */
	public function handlesLanguage( string $language ): bool {
		return in_array( $language, $this->languages );
	}

	/**
	 * Get all active translators for a language
	 *
	 * @param string $language
	 * @return Personnel[]
	 */
	public static function getActiveTranslatorsForLanguage( string $language ): array {
		return self::getPersonnelByTypeAndLanguage( 'translator', $language, true );
	}

	/**
	 * Get all active editors for a language
	 *
	 * @param string $language
	 * @return array
	 */
	public static function getActiveEditorsForLanguage( string $language ): array {
		return self::getPersonnelByTypeAndLanguage( 'editor', $language, true );
	}

	/**
	 * Get personnel by type and language
	 *
	 * @param string $type
	 * @param string $language
	 * @param bool|null $active
	 * @return array
	 */
	public static function getPersonnelByTypeAndLanguage(
		string $type, string $language, ?bool $active = null
	): array {
		return self::getPersonnel( $type, $language, $active );
	}

	/**
	 * Get all personnel
	 *
	 * @param string|null $type
	 * @param string|null $langCode
	 * @param bool|null $active
	 * @return self[]
	 */
	public static function getPersonnel( ?string $type = null, ?string $langCode = null, ?bool $active = null ): array {
		$conds = [];
		if ( $active != null ) {
			$conds = [ 'tmp_is_active' => $active ];
		}
		$dbr = wfGetDB( DB_REPLICA );
		$result = $dbr->select(
			self::TABLE_NAME,
			'*',
			$conds
		);

		$personnel = [];
		foreach ( $result as $row ) {
			$person = self::newFromRow( $row );
			if ( $type && !$person->isType( $type ) ) {
				continue;
			}
			if ( $langCode && !$person->handlesLanguage( $langCode ) ) {
				continue;
			}

			$personnel[] = $person;
		}

		return $personnel;
	}

	// @todo maybe 	 * @param int|null $includeId ID to always include regardless of active status

	/**
	 * Common method to get personnel options for selects
	 *
	 * @param string $role Role to filter by
	 * @param string|null $langCode Language code to filter by
	 * @param bool|null $active Whether to include active/inactive/any personnel
	 * @param array $flags Additional flags - include_all, include_empty
	 * @return array
	 */
	public static function getOptionsForSelect(
		string $role = 'editor', ?string $langCode = null, ?bool $active = true, array $flags = []
	): array {
		$personnel = self::getPersonnel( $role, $langCode, $active );
		$personnel = self::getPersonnelIdToNameMap( $personnel );
		return Utils::makeDropdownOptions( $personnel, $flags );
	}

	/**
	 * Check if a type is valid
	 *
	 * @param string $type
	 * @return bool
	 */
	public static function isValidType( string $type ): bool {
		return in_array( $type, self::VALID_TYPES );
	}

	/**
	 * Convert an array of personnel objects to an associative array of ID => name
	 *
	 * @param Personnel[] $personnel Array of personnel objects
	 * @return array<int,string> Associative array mapping personnel IDs to names
	 */
	public static function getPersonnelIdToNameMap( array $personnel ): array {
		$map = [];
		foreach ( $personnel as $person ) {
			$map[$person->getId()] = $person->getName();
		}
		return $map;
	}
}
