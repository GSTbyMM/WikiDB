-- -----------------------------------------------------------------------------------
-- SCHEMA UPDATE FOR WIKIDB EXTENSION [2010-05-16]
-- $Rev: 2048 $
-- This update adds two columns and one index to wikidb_tables which are used to
-- cache redirect information, in order to enable the table aliases functionality.
-- See the main tables.sql file for more details.
-- -----------------------------------------------------------------------------------
-- You are strongly recommended to use the supplied install/update scripts rather
-- than running this code directly.
--
-- See comments in tables.sql for more information about what the special comment
-- markers mean.
-- -----------------------------------------------------------------------------------

ALTER TABLE /*_*/wikidb_tables
	ADD redirect_namespace		int default NULL;

ALTER TABLE /*_*/wikidb_tables
	ADD redirect_title			varchar(255) binary default NULL;

CREATE INDEX /*i*/wikidb_tables_redirect_ns_title
	ON /*_*/wikidb_tables (redirect_namespace, redirect_title);
