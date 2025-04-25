-- ---------------------------------------------------------------------------------
-- TABLE DEFINITIONS FOR THE WikiDB EXTENSION.
-- $Rev: 2411 $
-- ---------------------------------------------------------------------------------
-- You are strongly recommended to use the supplied install/update scripts rather
-- than running this code directly.
--
-- /*_*/ should be replaced with the DB prefix you are using for your MediaWiki
-- installation (if relevant).
-- This will be done automatically if you use the install/update scripts.
--
-- /*i*/ is used to prefix indexes, but is probably unnecessary.  MediaWiki uses
-- this to manage a couple of renamed indexes, but that doesn't apply to us so it
-- seems a bit unnecessary.  However, we include it anyway as it seems to be
-- best-practice, and may potentially have some future use.
--
-- /*$wgDBTableOptions*/ is used by MediaWiki to set the default table options,
-- and will vary depending on the type of DB being used (MySQL/PostgreSQL/etc.).
-- Currently these just set the type of storage engine for the table, and
-- this doesn't really matter from the point of view of the extension code (though
-- it may make a difference to the speed and/or storage space requirements).
--
-- In earlier versions of WikiDB (before the SQL code and maintenance scripts were
-- included as part of the extension, and were just available on-wiki) I recommended
-- MyISAM table type, and now it will default to InnoDB.  However this change is not
-- considered relevant, and so is not checked for by the install script.  If this
-- distinction is important (and I am no DB expert!), somebody should let me know
-- and I'll update those scripts to test and fix the table type if necessary.
-- ---------------------------------------------------------------------------------

--
-- Table structure for table `wikidb_tables`.
-- This table holds the field definitions for all pages located in any
-- of the defined table namespaces.  There is one row per page.
--

CREATE TABLE IF NOT EXISTS /*_*/wikidb_tables (

-- ----- COLUMNS -----

-- These two fields point to the page on which the table is defined.
-- This will be a page in one of the table namespaces, as described in
-- $wgWikiDBNamespaces.
-- These (as a pair) are a foreign key into the standard MediaWiki `page` table,
-- though we don't actually create any JOINs between the two.
	table_namespace			int NOT NULL,
	table_title				varchar(255) binary NOT NULL,

-- This is the table definition itself.  It is a serialized array containing the
-- result of parsing the page for field definitions.
	table_def				mediumblob NOT NULL default '',

-- If this page is a redirect to a page within a table namespace then we store
-- the details about the redirect here.  It would be far too expensive to have to
-- process the page text when trying to find all pages that redirect to a specific
-- table (particularly as we need to follow chains of redirects) therefore we
-- store these two fields which point to the destination of the redirect so we can
-- use it as a quick look-up for this information.
-- We only log details about redirects which point to another page in a table
-- namespace.  Pages pointing to non-table pages do not have their redirect
-- stored here as they are not relevant to the extension.
-- Note that a `redirect` table was added to MediaWiki in version 1.9, which could
-- be used instead of these fields, but for compatibility with earlier versions
-- we implement our own redirect cache instead.
-- These (as a pair) are a foreign key into the standard MediaWiki `page` table,
-- though we don't actually create any JOINs between the two.
-- TODO: Investigate whether we can drop these in favour of the built-in `redirect`
--		 table, now that our minimum MediaWiki version is > MW 1.9
	redirect_namespace		int default NULL,
	redirect_title			varchar(255) binary default NULL,

-- ----- PRIMARY KEY -----

-- Each page will have at most one row in this table, so we set this as
-- the primary key.  We nearly always pull the data out of this table using an
-- exact match on these two fields, so it is important that they are indexed.
	PRIMARY KEY				(table_namespace, table_title)

) /*$wgDBTableOptions*/;

-- ----- INDEXES -----

-- We will also need to perform lookups on the destination page (to get pages that
-- point to it), so these need to be indexed too.
CREATE INDEX /*i*/wikidb_tables_redirect_ns_title
	ON /*_*/wikidb_tables (redirect_namespace, redirect_title);

-- ---------------------------------------------------------------------------------

--
-- Table structure for table `wikidb_rowdata`.
-- This table holds one record per row of data.  Data may defined on any page
-- on the wiki, and multiple data rows may be defined on a single page.
--

CREATE TABLE IF NOT EXISTS /*_*/wikidb_rowdata (

-- ----- COLUMNS -----

-- An auto-generated unique RowID, used as the primary key.
	row_id					int(8) unsigned NOT NULL PRIMARY KEY auto_increment,

-- These two fields point to the page on which the data was defined.  This page
-- should always exist on the wiki.
	page_namespace			int NOT NULL,
	page_title				varchar(255) binary NOT NULL,

-- These two fields point to the page in one of the table namespaces which
-- contains the definition.  This page may or may not actually exist.
	table_namespace			int NOT NULL,
	table_title				varchar(255) binary NOT NULL,

-- This is the parsed data for the row, stored as a serialized PHP array.
-- It consists of a set of key => value pairs, where key is the field name
-- (normalised) and value is the data for the field (as entered).
	parsed_data				mediumblob NOT NULL default '',

-- This flag is set to true whenever the table definition for this row is changed.
-- The table definition is correctly resolved across table aliases, and if any
-- of the tables in the alias chain are modified, then this flag is set.
-- The system then works through the stale records in batches (a certain number per
-- page request) to avoid excessive load when a definition changes.
-- This is required because we format wikidb_fielddata.field_value according to the
-- current definition so that we can do efficient searches/sorting within MySQL, so
-- these fields will need updating whenever the field definition changes (as this
-- affects the sort/search order).
	is_stale				tinyint(1) unsigned NOT NULL default '0'

) /*$wgDBTableOptions*/;

-- ----- INDEXES -----

-- We most commonly pull data out of this table by performing an exact match on the
-- table that the data is from, so it is very important that this pair of fields
-- is indexed properly.
CREATE INDEX /*i*/wikidb_rowdata_table_ns_title
	ON /*_*/wikidb_rowdata (table_namespace, table_title);

-- We only really use the source article fields as criteria when running the
-- DELETE queries after a page is updated (we delete all existing rows for that
-- page, re-parse the page and re-populate the table with any data records found).
-- This pair of fields is therefore still worth indexing, but less critical than
-- the above.
CREATE INDEX /*i*/wikidb_rowdata_ns_title
	ON /*_*/wikidb_rowdata (page_namespace, page_title);

-- I don't know how useful it is to index on a boolean field, but we will be
-- performing lookups on this, so an index will hopefully speed things up a little
-- bit, at least.
CREATE INDEX /*i*/wikidb_rowdata_is_stale
	ON /*_*/wikidb_rowdata (is_stale);

-- ---------------------------------------------------------------------------------

--
-- Table structure for table `wikidb_fielddata`
-- This table holds one row per data field for each row of data. It is used
-- to create the temporary tables used for running queries on the WikiDB data.
-- The data here is also held in the parsed_data field of the appropriate row
-- in wikidb_rowdata, in the form of a serialized PHP array.
--

CREATE TABLE IF NOT EXISTS /*_*/wikidb_fielddata (

-- ----- COLUMNS -----

-- Foreign key to wikidb_rowdata.row_id, indicating the row of data to which
-- this field belongs.
	row_id					int(8) unsigned NOT NULL,

-- Field name - normalised from the original data entered on the wiki.
	field_name				varchar(255) NOT NULL default '',

-- Field value - formatted for searching/sorting.
	field_value				varchar(255) NOT NULL default ''

) /*$wgDBTableOptions*/;

-- ----- INDEXES -----

-- Create some indexes to speed up queries.
-- Index on row_id and the namespace/title pair, as these are used frequently when
-- querying/modifying the table.

CREATE INDEX /*i*/wikidb_fielddata_row_id
	ON /*_*/wikidb_fielddata (row_id);

-- Create an index on the name/value pairs as these will be used to when creating
-- the temporary tables.
-- TODO: Actually, I'm not sure about this - this comes from my local DB but I can't
--		 remember setting it up, so can't comment on how correct/useful it actually
--		 is - to be investigated.
CREATE INDEX /*i*/wikidb_fielddata_field_name
	ON /*_*/wikidb_fielddata (field_name(75), field_value(75));
