-- MySQL version of the database schema for the Translation Project extension.
-- Licence: GNU GPL v2+

-- Project status
CREATE TABLE IF NOT EXISTS /*_*/tp_translation (
  translation_page_id INT unsigned NOT NULL PRIMARY KEY,

  -- Number of pageviews, imported from an Analytics system
  translation_pageviews INT UNSIGNED,
  -- 0 indicates the article is untranslated.
  translation_status TINYINT UNSIGNED NOT NULL DEFAULT 0,

  translation_comments varchar(255) binary,
  translation_suggested_name varchar(255) binary
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/tp_translation_suggested_name ON /*_*/tp_translation (translation_suggested_name);
CREATE INDEX /*i*/tp_translation_status ON /*_*/tp_translation (translation_status);
