<?php
/**
 * Header file for WikiDB command-line scripts.
 * Used to include necessary files and setup any common variables, etc.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

////////////////////////////////////////
// INITIALISE MEDIAWIKI CLI
////////////////////////////////////////

// Constant holding the path to the CLI entry-point, relative to the
// MediaWiki root folder.
	define("MWEntryPoint", "/maintenance/Maintenance.php");

// Check the MW_INSTALL_PATH environment variable, to see if it is set.  If so,
// this is the root folder of the MediaWiki installation.
	$MWPath = getenv("MW_INSTALL_PATH");

// If it is not set, look in the standard location relative to this extension file,
// assuming that the extension was installed in the expected place.
	if ($MWPath === false) {
		$MWPath = dirname(__FILE__) . "/../../..";

	// If the entry point can't be found, die with a warning message and suggest
	// the user sets MW_INSTALL_PATH.
		if (!file_exists($MWPath . MWEntryPoint)) {
			die("Unable to locate MediaWiki installation.  "
				. "Try setting the MW_INSTALL_PATH environment variable.\n");
		}
	}

// If MW_INSTALL_PATH was set, but we can't find the entry point, then die with
// a message indicating that the setting is no good.
	elseif (!file_exists($MWPath . MWEntryPoint)) {
		die("MediaWiki not found at MW_INSTALL_PATH (" . $MWPath . ").\n");
	}

// If we get here, then MediaWiki was found, so load the entry point.
// Note that the above checks also mean that an error message will be thrown if
// running on old, unsupported MediaWiki versions, so we don't need an explicit
// check for this.
// TODO: It would be nice if we gave a more helpful error message in this situation,
//		 but that is a not completely trivial, as we would need an additional check
//		 in order to tell the difference between 'MediaWiki not found' and 'MediaWiki
//		 found, but Maintenance class not present' (i.e. an old MW version).  Not
//		 sure how we would do that.
	require_once($MWPath . MWEntryPoint);

////////////////////////////////////////
// BACKWARDS-COMPATIBILITY
////////////////////////////////////////

// The 'DO_MAINTENANCE' constant was used in the original implementation in MW 1.16,
// but it was renamed to 'RUN_MAINTENANCE_IF_MAIN' in MW 1.17.
// We define this constant if it is not already defined, so that the code works as
// expected on MW 1.16.
// @back-compat MW < 1.17
	if (!defined('RUN_MAINTENANCE_IF_MAIN')) {
		define('RUN_MAINTENANCE_IF_MAIN', DO_MAINTENANCE);
	}

////////////////////////////////////////
// INCLUDE SUPPORT FILES
////////////////////////////////////////

// Currently only one support file, as not many support functions required.
// If this grows, they may be split into several files.
	require_once(dirname( __FILE__ ) . "/install_functions.inc");
