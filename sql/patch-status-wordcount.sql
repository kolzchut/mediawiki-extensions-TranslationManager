-- Update to store translation word count

-- Add a word count field to tm_status
ALTER TABLE /*_*/tm_status ADD `tms_wordcount` INT UNSIGNED;
