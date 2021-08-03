-- Update to remove main category, which is now supplied by another extension

-- Drop the field and index
ALTER TABLE /*_*/tm_status
	DROP `tms_main_category`,
	DROP INDEX /*i*/tms_main_category;
