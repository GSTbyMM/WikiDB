-- -----------------------------------------------------------------------------------
-- SCHEMA UPDATE FOR WIKIDB EXTENSION [2010-05-18]
-- $Rev: 2048 $
-- This update adds an is_stale flag to wikidb_rowdata, which is used to batch-update
-- field data when the table definition is changed, so we can defer updates and
-- save the wiki from collapsing when large tables have their definition modified.
-- See the main tables.sql file for more details on this field.
-- -----------------------------------------------------------------------------------
-- You are strongly recommended to use the supplied install/update scripts rather
-- than running this code directly.
--
-- See comments in tables.sql for more information about what the special comment
-- markers mean.
-- -----------------------------------------------------------------------------------

ALTER TABLE /*_*/wikidb_rowdata
	ADD is_stale		tinyint(1) unsigned NOT NULL default '0';

CREATE INDEX /*i*/wikidb_rowdata_is_stale
	ON /*_*/wikidb_rowdata (is_stale);
