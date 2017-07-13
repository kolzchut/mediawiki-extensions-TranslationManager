-- MySQL version of the database schema for the Translation Manager extension.
-- Licence: GNU GPL v2+

-- Project status
CREATE TABLE IF NOT EXISTS /*_*/tm_status (
  tms_page_id INT unsigned NOT NULL PRIMARY KEY,
  -- Number of pageviews, imported from an Analytics system
  tms_pageviews INT UNSIGNED,
  -- 0 indicates the article is untranslated.
  tms_status varchar(255) binary,
  tms_translator varchar(255) binary,
  tms_comments varchar(255) binary,
  tms_suggested_name varchar(255) binary,
  tms_project varchar(255) binary
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/tms_suggested_name ON /*_*/tm_status (tms_suggested_name);
CREATE INDEX /*i*/tms_status ON /*_*/tm_status (tms_status);
CREATE INDEX /*i*/tms_translator ON /*_*/tm_status (tms_translator);
CREATE INDEX /*i*/tms_project ON /*_*/tm_status (tms_project);


