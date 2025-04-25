<?php
/**
 * SetupTables.php
 * Installer for the WikiDB table structure.
 *
 * This file installs the tables required for the WikiDB extension, and will
 * also update any existing installations if the table structure has changed
 * between versions.  It does not populate the tables at all, as this can be very
 * time-consuming and may not be required in all instances.  See the other scripts
 * in this directory (ending .php) for the various different methods for
 * populating/updating the actual data.
 *
 * Arguments:
 *		None.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

// TODO: Check the following items:
// 		 * The 'title' fields were changed from 'varchar(255)' to
//		   'varchar(255) BINARY'.  However, this doesn't seem to make any difference
//		   to the mysqldump output for these tables, so I am assuming the change
//		   doesn't require any fixing here.  I need to check if this is
//		   actually the case or whether it is something we need to detect and
//		   repair as part of this script.
//		 * It is possible that old installations of WikiDB had their tables created
//		   using the wrong storage engine.  In the days before this install script,
//		   the table definitions were on the wiki, and these specified that the
//		   MyISAM table type should be used.  In addition, older versions of
//		   MediaWiki didn't implement the $wgDBTableOptions variable, and in this
//		   situation any new tables would use whatever the default storage engine is
//		   set to in the DB configuration, so even with these scripts the wrong
//		   engine may have been used.
//		   Any new installations will use the correct table type, but we currently
//		   don't attempt to fix any existing tables that may have been created
//		   with the wrong type.  To my knowledge, the storage type doesn't actually
//		   matter to the extension, except certain engines may be more stable or
//		   offer speed/storage space advantages.  We therefore don't make any checks
//		   for this, but if it is found to be relevant then we should add the
//		   appropriate checks and fixes to this script.
//		 * I renamed the indexes which were based on namespace/title pairs from
//		   "name_title" to "ns_title", as this is somewhat clearer, however existing
//		   DBs aren't being updated to use these new names.  I don't
//		   think this makes a difference, as index names don't really seem to have
//	 	   any relevance, so I have no plans to add the necessary code to perform
//		   this update.  If a valid reason to update existing schemas arises, then I
//		   will add the necessary code at that point.

// Note that we use the literal table names in this file, rather than our
// pWIKIDB_tbl* constants.  The constants are used in the main extension code so
// that renames may be performed without massive code changes, however in our
// maintenance scripts we need to be clear about exactly which table we are
// referring to, so we use the literal table names instead.

// Include header file required for all WikiDB maintenance scripts.
	require_once(dirname( __FILE__ ) . "/header.inc");

class WikiDB_SetupTables extends WikiDB_Maintenance {

	function __construct() {
		parent::__construct();

		$this->mDescription = "Install or update the database tables required for "
							. "the WikiDB extension.";
	}

// getDbType()
// Schema changes require admin permissions, so raise the required user level.
	function getDbType() {
		return self::DB_ADMIN;
	}

	function execute() {

	// Get a connection to the primary database.
	// Schema changes MUST be applied to the primary.
		$DB = $this->GetDBConnection(DB_PRIMARY);

	///////////////////////////////////
	// CREATE ANY MISSING TABLES
	///////////////////////////////////

	// We check for the existence of each table so that we can report whether it
	// is being created.
		print("Checking existence of tables...\n");

		$Tables = array('wikidb_tables', 'wikidb_rowdata', 'wikidb_fielddata');

		$MissingTables = false;
		foreach ($Tables as $Table) {
			if ($DB->tableExists($Table)) {
				print("..." . $Table . " already exists.\n");
			}
			else {
				print("NOT FOUND: " . $Table . "\n");
				$MissingTables = true;
			}
		}

	// If any tables were missing, run the table creation SQL.  The SQL uses IF NOT
	// EXISTS to ensure that only the missing tables are added, and that no errors
	// are triggered by the other tables.  This saves us having to have a separate
	// .sql file for each table.
	// TODO: This is no longer the case, since we needed to move the CREATE INDEX
	//		 statements outside of the CREATE TABLE blocks, for compatibility with
	//		 non-MySQL databases.  Therefore we will get SQL errors if some but not
	//		 all tables are present.  Need to resolve this, somehow.
		if ($MissingTables)
			self::ApplySQLFile("Creating missing tables", "tables.sql", $DB);

	///////////////////////////////////
	// UPGRADE TABLE STRUCTURE
	// The remainder of this file checks for any changes that have been made to the
	// table structure as WikiDB has developed, and applies any updates that are
	// necessary.
	// TODO: We could be a bit clever and skip any tables that were added by the
	// 		 above script, as we know they are already up-to-date.
	///////////////////////////////////

	// Install table definitions.
		print("Checking table structure is up-to-date...\n");

	// Remove obsolete 'raw_data' field from the fields table (see comments for
	// r221).
		self::RemoveField('wikidb_rowdata', 'raw_data', $DB);

	// Add new redirect-related fields.
		if ($DB->fieldExists('wikidb_tables', 'redirect_namespace')) {
			print("...redirect fields already in place.\n");
		}
		else {
			self::ApplySQLFile("Adding redirect fields to wikidb_tables",
							   "update_AddRedirectCache.sql", $DB);
		}

	// Add is_stale flag to row table.
		if ($DB->fieldExists('wikidb_rowdata', 'is_stale')) {
			print("...is_stale flag already present in wikidb_rowdata.\n");
		}
		else {
			self::ApplySQLFile("Adding is_stale flag to wikidb_rowdata",
							   "update_AddIsStaleFlag.sql", $DB);
		}

	// Remove obsolete namespace/title fields from the fields table.
	// Note that MySQL automatically updates the indexes for us so we don't need to
	// explicitly remove the index as well.
	// This may not be the case for other database backends, but this changes was
	// added at a time when only MySQL was officially supported, so this shouldn't be
	// a problem.
		self::RemoveField('wikidb_fielddata', 'table_namespace', $DB);
		self::RemoveField('wikidb_fielddata', 'table_title', $DB);
	}

} // END class WikiDB_SetupTables

$maintClass = 'WikiDB_SetupTables';
require_once(RUN_MAINTENANCE_IF_MAIN);
