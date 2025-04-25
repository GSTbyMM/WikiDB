<?php
if (!defined('MEDIAWIKI'))
	die("MediaWiki extensions cannot be run directly.");
if (!defined('WikiDB')) {
	die("The WikiDB extension has not been set up correctly.<br>'"
		. basename(__FILE__) . "' should not be included directly.<br>Instead, "
		. "add the following to LocalSettings.php: "
		. "<tt><b>include_once(\"WikiDB/WikiDB.php\");</b></tt>");
}
/**
 * Hook functions required for WikiDB.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

// efWikiDB_SkinHooks()
// Handler for the 'SkinTemplateNavigation::Universal' hook.
//
// Purpose:
//	To provide the functionality of efWikiDB_AddActionTabs() in MW 1.18 and above.
//  We simply pass the appropriate bit of the tab array (by reference) to that
//  function, which works pretty-much the same in both contexts.
//
// @back-compat MW < 1.18
	function efWikiDB_SkinHooks(&$SkinTemplate, &$Links) {
		$ActionTabs =& $Links['namespaces'];
		$Title = $SkinTemplate->getTitle();
		efWikiDB_AddActionTabs($ActionTabs, $Title);
		return true;
	}

// efWikiDB_AddActionTabs()
// Handler for the 'SkinTemplateContentActions' hook and (via the above wrapper
// function) the 'SkinTemplateNavigation::Universal' hook.
//
// Purpose:
// 	If viewing a table namespace, add a 'data' tab after the subject-page tab.
//	This tab displays the number of records and is a redlink if there are none.
//	It provides the 'viewdata' action for the page in question (always the subject
//  page even if viewing the talk page).
//  Note that in MW >= 1.18, the efWikiDB_SkinHooks() function is called instead,
//  but it simply acts as a wrapper to this function, which is the same for both
//  situations, except for the method it uses to work out which tab the new tab
//  should follow.
//
// TODO: Each namespace is allowed its own default text for the subject page tab, so
//		we should respect that in a way that allows us to be clear that this is the
//		definition tab... maybe we just need sensible defaults?
// TODO: Should we remove the 'edit' tab when action='viewdata'?  Any other
//		 non-applicable tabs which should be removed?
	function efWikiDB_AddActionTabs(&$ContentActions, $Title = null) {
	// Get the requested action.
	// We use this to check whether we are on the 'viewdata' tab or not, in order
	// to ensure the appropriate styling is applied.
		$Action = WikiDB_GetAction();

	// Use $wgTitle in old versions of MediaWiki where we can't get the Title object
	// in any other way.  From 1.18 onwards this function is wrapped by
	// efWikiDB_SkinHooks() which can get the correct title from the context and
	// which populates the $Title argument.  Older versions will need to retrieve
	// the Title from the global scope.
	// Note that, as of MW 1.19 $wgTitle is deprecated and it may be removed entirely
	// in some future version.
	// @back-compat MW < 1.18
		if (!isset($Title)) {
		// To avoid confusion, we put the global declaration in this
		// backwards-compatibility code branch, not in the general scope.
			global $wgTitle;
			$Title = $wgTitle;
		}

	// The data tab is the same for the main page and the talk page, so if we're on
	// the talk page then we need to change $Title to point to the subject page
	// instead.
		if ($Title->isTalkPage())
			$Title = WikiDB_GetSubjectPage($Title);

	// If we're in a DB namespace, add a new 'data' tab.
		if (WikiDB_IsValidTable($Title)) {
		// Get URL for 'data' tab.
			$Link = $Title->getLocalURL('action=viewdata');

		// Count records for this table, to display the number of rows in the text
		// for the tab.
			$Table = new WikiDB_Table($Title);
			$Count = $Table->CountRows();

		// If we are on the 'data' tab, then the class should be marked as selected.
			if ($Action == "viewdata")
				$Class = "selected";
			else
				$Class = "";

		// If no records exist, colour tab 'red' in the same way as a link to a
		// missing page, indicating that there is no data. If there are records it
		// will have the standard link style for existing pages.
			if ($Count == 0)
				$Class .= " new";

		// Add new tab to the array.  Because we want to add it after the 'content'
		// tab, rather than at the end, we need to rebuild the array.
			$NewActions = array();
			foreach ($ContentActions as $Key => $Value) {
				$NewActions[$Key] = $Value;

			// If we are viewing the data page, make sure that no other action is
			// selected.  (If we don't do this then the subject page tab will also
			// be selected.)
				if ($Action == "viewdata") {
					$NewActions[$Key]['class']
							= preg_replace('/selected/', "",
										   $NewActions[$Key]['class']);
				}

			// If this is the subject tab then change its label to the text
			// "table definition" and add the 'data' tab as the next tab.
			// In MediaWiki < 1.19, the subject tab was identified by the array key,
			// which would always begin "nstab-".  From 1.19 onwards, it is
			// identified by having its 'context' property set to "subject".
			// For full compatibility we therefore check for both of these.
			// @back-compat MW < 1.19
				if (substr($Key, 0, 6) == "nstab-"
					|| (isset($Value['context']) && $Value['context'] == "subject"))
				{
				// Change label of subject page tab.
					$NewActions[$Key]['text'] = WikiDB_Msg('nstab-wikidb-tabledef');

				// Add the 'data' tab between the subject-page tab and the talk-page
				// tab.
					$NewActions['viewdata'] = array(
						'class' => $Class,
						'text' => WikiDB_Msg('nstab-wikidb-tabledata', $Count),
						'href' => $Link,
					// These two are only present in MW 1.18 and above, but there
					// is no harm in adding them for lower versions so we don't need
					// to do a version check here.
					// @back-compat MW < 1.18 (comment only)
						'primary' => 1,
						'context' => "viewdata",
					);
				}
			}
			$ContentActions = $NewActions;
		}
		return true;
	}

// efWikiDB_SpecialActions()
// Handler for the 'UnknownAction' hook, for MW < 1.19.
//
// Purpose:
//	To handle the 'viewdata' action.  This action only applies in a table namespace,
//	and is ignored in other namespaces.
//	If this action is requested, we output all table data for the specified table
//	in the standard WikiDB format.
//
// This function is only used on MW < 1.19 - later versions use a different method
// to achieve the same thing.  Therefore, this function doesn't need to worry about
// compatibility with modern MediaWiki versions.
//
// @back-compat MW < 1.19
	function efWikiDB_SpecialActions($action, $article) {
		global $wgOut, $wgRequest;

	// If the requested action is not 'viewdata' then don't do anything.
	// We return true, indicating that MediaWiki should carry on as normal.
		if ($action != "viewdata")
			return true;

		$Title = $article->getTitle();

		return efWikiDB_HandleViewDataAction($Title, $wgOut, $wgRequest);
	}

// efWikiDB_HandleViewDataAction()
// Function to handle the 'viewdata' action.  Called from the UnknownAction hook
// in MW < 1.19 and from the WikiDB_ViewDataAction class in MW >= 1.19.
// TODO: Should we raise an error if the action happens in a non-table namespace, or
//		is the current behaviour OK?
// TODO: Currently you get the same output whether the requested page is the subject
//		page or the talk page.  Is this the best behaviour?  One potential problem
//		is that this means there are two URLs for the same page content - does this
// 		matter?  If so, there are two other options: (1) disallow 'viewdata' for
//		talk pages.  Not a problem when using the interface, but makes URL wrangling
//		a big more fussy (2) perform a hard redirect so that the URL changes to the
//		subject page, when accessed from the talk page.
	function efWikiDB_HandleViewDataAction($Title, $objOutput, $objRequest) {
	// The data is the same for the main page and the talk page, so if we're on the
	// talk page then we need to change $Title to point to the subject page instead.
	// In practice this won't happen through the interface, as the tab always links
	// to the subject page, but it may occur through URL wrangling.
	// We set a flag to show that this happened, so that we can redirect to the
	// canonical address if this is in the table namespace.  (We don't want to
	// redirect if in a non-table namespace, where this action is invalid.)
		$WasTalkPageRequested = false;
		if ($Title->isTalkPage()) {
			$WasTalkPageRequested = true;
			$Title = WikiDB_GetSubjectPage($Title);
		}

	// If this is not a table namespace then ignore the action.
	// We return true, indicating that MediaWiki should carry on as normal.
	// This will result in an 'invalid action' error message.
		if (!WikiDB_IsValidTable($Title))
			return true;

	// If a talk page was requested, redirect to the 'viewdata' action for the
	// associated subject page.  Without this, there would be two URLs for the same
	// content.  This only happens for talk pages within a table namespace.  For
	// talk pages in other namespaces, we have already bailed-out with an 'invalid
	// action' error message.
		if ($WasTalkPageRequested) {
		// Set up the redirect.  Note that this doesn't perform the redirect
		// straight away - it sets everything up, but the redirect will be performed
		// when page output takes place.
			$objOutput->redirect($Title->getFullURL("action=viewdata"));

		// Return false to stop further checks from happening.  Without this, you
		// will get an error that an invalid action was requested (as the action has
		// not been handled).
		// This is necessary because the redirect doesn't take place until later on
		// in the processing, after parsing is completed.
			return false;
		}

	// Set page title.
		$objOutput->setPageTitle(WikiDB_Msg('wikidb-tabledata-heading',
											$Title->getFullText()));

	// Set robot policy to avoid this page being indexed or linked from as the
	// dynamic nature of the content means subsequent views may not contain the
	// same data.
		$objOutput->setRobotPolicy("noindex, nofollow");

	// Setup the table object.
		$Table = new WikiDB_Table($Title->getFullText());

	// If the table is an alias, it holds no data directly.  We instead add a link
	// to the table it is an alias of.
	// TODO: Or should we automatically redirect?
		if ($Table->IsAlias()) {
			$Dest = $Table->GetDataDestination();
			$Title = $Dest->GetTitle();

		// Ensure any arguments are passed-through to the destination page.
			$arrQueryArgs = $objRequest->getValues('action', 'order', 'criteria',
												   'sourceArticle', 'offset',
												   'limit');

			$URL = $Title->getInternalURL(wfArrayToCGI($arrQueryArgs));

			$Link = "[" . $URL . " " . $Dest->GetFullName() . "]";
			WikiDB_AddWikiText(WikiDB_Msg('wikidb-tabledata-redirect', $Link),
							   $objOutput);
			return false;
		}

	// Get range parameters from the URL
		$ItemsPerPage = $objRequest->getIntOrNull('limit');
		if (!$ItemsPerPage)
			$ItemsPerPage = 50;
		$Offset = $objRequest->getInt('offset');

		$Criteria = $objRequest->getText('criteria');
		$Order = $objRequest->getText('order');
		$SourceArticle = $objRequest->getText('sourceArticle');

	// Get the table data, possibly filtered/sorted.
		$Query = new WikiDB_Query($Table, $Criteria, $Order, $SourceArticle);
		$TotalRows = $Query->CountRows();
		$objResult = $Query->GetRows($Offset, $ItemsPerPage);

	// Output the options form, which should go above all other content.
		$objOutput->addHTML(pefWikiDB_GetViewDataForm($objRequest, $Criteria,
													  $Order));

	// Report an error if it occurred.
		if ($Query->HasErrors()) {
			$Message = $Query->GetErrorMessage();
			$Message = nl2br(WikiDB_EscapeHTML($Message));
			$objOutput->addHTML('<p class="error">' . $Message . '</p>');
		}
	// Otherwise, if no data found, report the fact.
		elseif ($objResult->CountRows() == 0) {
			WikiDB_AddWikiText(WikiDB_Msg('wikidb-nodata'), $objOutput);
		}
	// Otherwise, output the table data in the standard format.
		else {
		// Add total number of results returned.
			WikiDB_AddWikiText(WikiDB_Msg('wikidb-viewdata-record', $TotalRows),
										  $objOutput);

		// Output pagination controls.
			$start = $Offset + 1;
            $end = min($Offset + $ItemsPerPage, $TotalRows);
            $objOutput->addHTML('<p>Showing results ' . $start . '–' . $end . ' of ' . $TotalRows . '.</p>');

			$LastRowOnPage = $Offset + $ItemsPerPage;
			if ($LastRowOnPage >= $TotalRows)
				$IsLastPage = true;
			else
				$IsLastPage = false;

			$arrQueryString = array(
								'action' => "viewdata",
							);

			if (!IsBlank($Criteria))
				$arrQueryString['criteria'] = $Criteria;

			if (!IsBlank($Order))
				$arrQueryString['order'] = $Order;

			$Output = WikiDB_GetPaginationLinks(
									 $Offset,
									 $ItemsPerPage,
									 $Title,
									 $arrQueryString,
									 $IsLastPage
									);

			$objOutput->addHTML( '<p>' . $Output . '</p>' );

		// Output the data.
			$Output = WikiDB_HTMLRenderer::FormatTableData($objResult, true);
			WikiDB_AddWikiText($Output, $objOutput);
		}

	// Return false to tell MediaWiki that we have successfully generated the page
	// contents and to skip processing of other hooks.
		return false;
	}

// pefWikiDB_GetViewDataForm()
// Helper function for efWikiDB_SpecialActions() which returns the HTML for the
// options form that is presented at the top of the page.
// The arguments provide default/current values for the fields that are included
// in the form.
	function pefWikiDB_GetViewDataForm($objRequest, $Criteria, $Order) {
		global $wgScript;

	// Open form.
		$Form = '<form id="ViewDataOptions" method="get" action="'
			  . WikiDB_EscapeHTML($wgScript) . '">';

	// Get current query parameters which we will want to include as hidden inputs,
	// so that we end up on the right page with the right action, etc.
	// We remove the fields that we are outputting visible, plus the offset parameter
	// as any new search should start again at the first page.
		$arrHiddenFields = $objRequest->getValues();
		unset($arrHiddenFields['criteria'],
			  $arrHiddenFields['order'],
			  $arrHiddenFields['offset']);

	// Add hidden inputs for existing GET variables.
		foreach ($arrHiddenFields as $Name => $Value) {
			$Form .= '<input type="hidden" name="' . WikiDB_EscapeHTML($Name)
				   . '" value="' . WikiDB_EscapeHTML($Value) . '">';
		}

	// We wrap the output in a fieldset tag, to group the fields.
		$Form .= '<fieldset>'
			   . '<legend>' . WikiDB_Msg('wikidb-viewdata-options') . '</legend>';

	// Add the visible form fields.  We use a table-based layout, derived from the
	// markup generated for the built-in MediaWiki special pages.
		$Form .= '<table>'
			   . '<tr>'
				   . '<td align="right"><label for="criteria">'
						. WikiDB_Msg('wikidb-formfield-criteria') . '</label></td>'
				   . '<td align="left"><input type="text" name="criteria" value="'
						. WikiDB_EscapeHTML($Criteria) . '" size="52"></td>'
			   . '</tr>'
			   . '<tr>'
				   . '<td align="right"><label for="criteria">'
						. WikiDB_Msg('wikidb-formfield-order') . '</label></td>'
				   . '<td align="left"><input type="text" name="order" value="'
						. WikiDB_EscapeHTML($Order) . '" size="40"> '
						. '<input type="submit" value="'
						. WikiDB_Msg('wikidb-formfield-submit') . '">'
						. '</td>'
			   . '</tr>'
			   . '</table>';

	// Close fieldset/form.
		$Form .= '</fieldset>'
			   . '</form>';

		return $Form;
	}

// efWikiDB_LinksUpdate()
// Handler for the 'LinksUpdate' hook.
//
// Purpose:
//	To update the WikiDB tables whenever an article changes.  This happens when the
//	article is saved, or (in a deferred update, via the job queue) whenever a
//	template that it uses is saved.
//	It is essentially a wrapper to WikiDB::PageUpdated().
//
// Notes:
// * This hook is called when an article is created, edited and rolled-back or
//	 whenever a template (or any transcluded article) in the article is created,
//	 edited or rolled-back.
// * The whole article text is passed, even if the edit was to a section.
// * As of MW 1.33, this is also called when an article is deleted.
// * In MW 1.37 this is also called when an article is undeleted - it is not clear
//	 when this behaviour was introduced - maybe it has always been the case (the
//	 alternative hook was added before we started using LinksUpdate).  This obsoletes
//	 the need for the 'ArticleUndelete' hook, so we should remove that, with an
//	 appropriate back-compat shim, if needed.
// * This hook cannot be used for page moves.  Whilst it will be called for the
//	 destination location, it is only called for the source location if a redirect
//	 is left behind.  Therefore, the separate hook to handle page moves is still
//	 required.  We could, however, reduce it so it only applies to the original page
//	 and only if it no longer exists, which would give a slight performance
//	 improvement.
// TODO: Find out when LinksUpdate started firing for undeletions and update our
//		 use of ArticleUndelete, accordingly.
// TODO: Consider updating the Move hooks to only update the source page and only
//		 when it has been deleted.
	function efWikiDB_LinksUpdate(&$LinksUpdate) {
		$objTitle = $LinksUpdate->getTitle();

		if ($objTitle->exists())
			WikiDB::PageUpdated($objTitle);
		else
			WikiDB::PageDeleted($objTitle);

		return true;
	}

// efWikiDB_ArticleUndelete()
// Handler for the 'ArticleUndelete' hook.
//
// Purpose:
//	To update the WikiDB tables when an article is undeleted.  It is essentially a
//	wrapper to WikiDB::PageUpdated().
	function efWikiDB_ArticleUndelete($title, $create) {
		WikiDB::PageUpdated($title);
		return true;
	}

// efWikiDB_ParserBeforeInternalParse()
// Handler for the 'ParserBeforeInternalParse' hook.
//
// Purpose:
//	To render pages in the table namespace (to format definitions correctly, and to
//	add the summary box at the top).
	function efWikiDB_ParserBeforeInternalParse(&$parser, &$text, &$strip_state) {
	// Static flag to stop us re-running this code if any subsequent code invokes
	// the parser for any reason.  We set this to true once we know this is a
	// page in the table namespace, so that it isn't run a second time (resulting
	// in duplicate headers etc. being added to the page.
	// This can happen via WikiDB if, for example, a <data> tag is added to a
	// table definition page.  It is also possible that other extensions might
	// trigger a reparse, so even if WikiDB is modified to avoid this, the check
	// should remain.
	// We only skip if we are not parsing for a save - save operations should always
	// be allowed through so that the DB is updated properly!
		static $ParsingComplete = false;
		if ($ParsingComplete && !WikiDB::IsParsingForSave())
			return true;

		$Title = WikiDB_GetTitleFromParser($parser);

	// We only apply the formatting to pages in a table namespace (and not their
	// talk pages, either), so skip rest of function if not in table namespace.
		if (!WikiDB_IsValidTable($Title))
			return true;

	// Register the table definition so that external code can do something with it,
	// e.g. saving it to the DB.
	// It's not technically a tag, as such, but it is handled in the same way.
		WikiDB_Parser::RegisterMatchedTag($parser, $Title,
										  'tabledef', array(), $text);

	// If the page is being parsed for a save operation, no need to perform the
	// rendering, so we simply return.
		if (WikiDB::IsParsingForSave()) {
			return true;
		}

	// Don't add to data if we're editing the page or performing some other action
	// (including our own 'viewdata' action).
		switch (WikiDB_GetAction()) {
		// These are OK
			case "purge":
			case "submit":
			case "view":
				break;
		// The rest are bad
			default:
				return true;
		}

	// We only want to parse the page content - not any system messages, etc.
		$ParserOptions = $parser->getOptions();

	// Until I resolve the TODO note in the subsequent code-block, I have kept some
	// debugging code handy.  I used this to do a basic smoke test of some pages on
	// my development wiki, and got the results I expected.  However, it is likely
	// that some more in-depth testing is required, in which case this code will
	// come in handy.
	// Set the if() condition to true to enable the debugging output.
	// TODO: Remove this debugging code once I have resolved the TODO in the next
	//		 code block, and have a robust way of detecting interface messages.
		if (false) {
			print("<pre>");
			print("[" . ($ParserOptions->getInterfaceMessage() ? "YES" : "no")
				  . "-interface]");
			print("[" . ($ParserOptions->getEnableLimitReport() ? "YES" : "no")
				  . "-limitreport]");
			print("\n");
			print(WikiDB_EscapeHTML($text));
			print("</pre>");
		}

	// According to IAlex (see IRC logs for #mediawiki, @19:06 on 5th Jan 2013),
	// we can tell if this is a system message by checking if either
	// getInterfaceMessage() is true or getEnableLimitReport() is false.
	// Otherwise it is page content.
	// Note that the enableLimitReport() function has been deprecated in MW 1.38 and
	// now always returns false.  Therefore, this function can't be used on 1.38 and
	// later.  I have added a version check for this, so that behaviour is unchanged
	// on older versions, but it is not clear to me whether (a) this check is not
	// actually required at all, and getInterfaceMethod() is actually sufficient; or
	// (b) this setting is required and we have now introduced a potential breakage
	// on modern MediaWiki Versions.
	// TODO: Investigate this in more detail.  Certainly, from some basic tests I
	//		 can confirm that things are working as expected, but there might be
	//		 some edge-cases (e.g. when using languages that differ from the content
	//		 language) where this flag is not set as expected.  Is there another way
	//		 to detect whether this is the page content or something else that is
	//		 being output?  If getInterfaceMessage() is not reliable then perhaps we
	//		 need a completely different approach.
	// @back-compat MW < 1.38
		if ($ParserOptions->getInterfaceMessage()
			|| (version_compare(MW_VERSION, "1.38", "<")
				&& !$ParserOptions->getEnableLimitReport()))
		{
			return true;
		}

	// Parse the text to get the field definitions (including any extra text) ready
	// for outputting.
	// Previously we pulled this from the table definition, but in practice this
	// code is called before our ArticleSaveComplete hook, therefore we end up
	// displaying the previous table definition instead of the new one (requiring
	// a page purge in order to update properly).  Now we always use the text as
	// passed into the function, even though this results in an extra parse.
	// TODO: We no longer use the ArticleSaveComplete hook, but instead use
	//		 LinksUpdate.  Does this comment therefore still apply?
//		$Table = new WikiDB_Table($Title);
//		$Fields = $Table->GetFields();
		$Fields = WikiDB_Parser::ParseTableDef($text);

	// Build the summary table to go at the top of the page.
		$Summary = WikiDB_HTMLRenderer::FormatTableDefinitionSummary($Title,
																	 $Fields);

	// Format the page contents using the WikiDB rendering function.
		$Source = WikiDB_HTMLRenderer::FormatTableDefinitionPage($Title, $Fields);

	// Generate the page content.
	// We show the summary, followed by the original source code, in each case
	// prefixed by an appropriate heading.
	// We include a __NOEDITSECTION__ tag at the start, so that the 'edit'
	// buttons don't display, as our code adds a couple of headings and this
	// means that they don't work properly.
	// TODO: Can we fix this somehow?
		$text = "__NOEDITSECTION__\n"
			  . "== " . WikiDB_Msg('wikidb-tabledef-definitionheading') . " ==\n"
			  . $Summary . "\n"
			  . "== " . WikiDB_Msg('wikidb-tabledef-sourceheading') . " ==\n"
			  . $Source;

	// Set the $ParsingComplete flag to stop any reparse.
		$ParsingComplete = true;
		return true;
	}

// efWikiDB_ArticleDeleteComplete()
// Handler for the 'ArticleDeleteComplete' hook, for MW < 1.33.
//
// Purpose:
//	To tidy up our database when a page is deleted.
	function efWikiDB_ArticleDeleteComplete(&$article, &$user, $reason) {
		WikiDB::PageDeleted($article);
		return true;
	}

// fnWikiDB_PageMoveComplete()
// Handler for the 'PageMoveComplete' hook.
//
// Purpose:
//	To update the WikiDB tables with the new article title when an article is moved,
//  on MediaWiki >= 1.35.  (TitleMoveComplete is used for MW < 1.35.)
	function efWikiDB_PageMoveComplete(&$old, &$new, $userIdentity, $pageid,
									   $redirid, $reason, $revision)
	{
		$OldTitle = Title::newFromLinkTarget($old);
		$NewTitle = Title::newFromLinkTarget($new);

		efWikiDB_PageMoved($OldTitle, $NewTitle);
		return true;
	}

// fnWikiDB_TitleMoveComplete()
// Handler for the 'TitleMoveComplete' hook.
//
// Purpose:
//	To update the WikiDB tables with the new article title when an article is moved,
//  on MediaWiki < 1.35.  (PageMoveComplete is used for MW >= 1.35.)
//
// @back-compat MW < 1.35
	function efWikiDB_TitleMoveComplete(&$title, &$newtitle, $user, $oldid, $newid) {
		efWikiDB_PageMoved($title, $newtitle);
		return true;
	}

// efWikiDB_PageMoved()
// Helper function to handle the 'page moved' operation, as there are several
// alternative hook functions, depending on the MediaWiki version in use.
// Previously this function worked by manipulating the data in the DB so that we
// avoided having to do a full re-parse.  However I kept encountering scenarios that
// I hadn't catered for, and which would leave the DB containing incorrect data.
// I have therefore re-written the function to simply re-parse both the old and new
// locations, which should guarantee that the DB is fully and correctly updated, at
// the (assumed) expense of execution speed.
	function efWikiDB_PageMoved($title, $newtitle) {
	// Re-parse the original location, to update the WikiDB tables for that page.
	// Note that this code assumes that WikiDB::PageUpdated() will correctly
	// handle situations where the title doesn't exist (because leaving behind a
	// redirect is optional).  This situation has not been fully tested.  If it
	// causes problems then WikiDB::PageUpdated() is the place that needs fixing,
	// not this function.
	// TODO: Test this properly.
		WikiDB::PageUpdated($title);

	// Need to reset the article ID for the new title, because currently it is not
	// considered to exist because of stale data in the object.  Calling this
	// function causes a proper refresh, so we get the correct page content.
		$newtitle->resetArticleID(false);

	// Reparse the new location, to update the WikiDB tables for the page.
		WikiDB::PageUpdated($newtitle);
	}

// efWikiDB_EditPage_showEditForm_initial()
// Handler for the 'EditPage::showEditForm:initial' hook.
// Adds details about related tables/data sources underneath the edit box, similar
// to how the list of templates used is output.
// The code we use to generate the output (and therefore the structure of the HTML
// that we generate) is based on Linker::formatTemplates(), which is the function
// that creates the similar list for templates used in the text.
// Note that the $output argument was added in MW 1.20.0 and so we need to default
// to null for compatibility with older MW versions.
// @back-compat MW < 1.20
	function efWikiDB_EditPage_showEditForm_initial($editPage, $output = null) {
	// On older MW versions, the $output argument is not passed.  However, we need
	// this object for our call to WikiDB_MsgExpanded() so we ensure to populate
	// it from the global $wgOut variable if it is not present.
	// Note that, in the long-term, we want to remove the reliance on an OutputPage
	// object from the WikiDB_MsgExpanded() function.  If that happens, then this
	// backwards-compatibility code can also be removed, even if we still need to
	// support older MW versions.
	// @back-compat MW < 1.20
		if ($output === null) {
			global $wgOut;
			$output = $wgOut;
		}

	// Get the Title and Article object from the EditPage object.
		$PageTitle = WikiDB_GetEditPageTitle($editPage);
		$PageArticle = $editPage->getArticle();

	// It is not clear what the best thing is for getting the text of the edit box.
	// getPreviewText() returns the text tidied for display in the preview and
	// getContent() seems to do a lot of work, which I don't want to repeat.
	// I therefore access the textbox1 variable directly, to get the raw text that
	// is being displayed in the edit box.
		$arrTags = WikiDB_Parser::ParseTagsFromText($editPage->textbox1,
													$PageTitle,
													$PageArticle);

		if (count($arrTags['data']) > 0) {
			$arrTitles = array();
			$arrTitleCounts = array();
			foreach ($arrTags['data'] as $arrDataTag) {
				$objTable = new WikiDB_Table($arrDataTag['Arguments']['table']);

				$objTitle = $objTable->GetTitle();
				$arrDataRows = WikiDB_Parser::ParseDataTag(
											$arrDataTag['Content'],
											$arrDataTag['Arguments']['fields'],
											$arrDataTag['Arguments']['separator']
										);
				$RowCount = count($arrDataRows);

			// Add to the array of titles and the array of title counts.
			// We need to keep them separate so the titles array is properly
			// sortable.
				$TitleKey = $objTitle->getDBkey();

				if (!isset($arrTitleCounts[$TitleKey])) {
					$arrTitles[] = $objTitle;
					$arrTitleCounts[$TitleKey] = 0;
				}

				$arrTitleCounts[$TitleKey] += $RowCount;
			}

		// Register the links as a link batch.  This is a mechanism used to populate
		// the link data for all provided links via a single DB query, instead of
		// requiring a separate query for each link (with the obvious performance
		// degradation that implies).
			$objBatch = new LinkBatch(
				[], // links
				\MediaWiki\MediaWikiServices::getInstance()->getLinkCache(),
				\MediaWiki\MediaWikiServices::getInstance()->getTitleFormatter(),
				\MediaWiki\MediaWikiServices::getInstance()->getContentLanguage(),
				\MediaWiki\MediaWikiServices::getInstance()->getGenderCache(),
				\MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider(),
				\MediaWiki\MediaWikiServices::getInstance()->getLinksMigration(),
				\MediaWiki\Logger\LoggerFactory::getInstance('LinkBatch')
			);
			foreach ($arrTitles as $objTitle) {
				$objBatch->addObj($objTitle);
			}
			$objBatch->execute();

		// Sort the list.
			usort($arrTitles, array('Title', 'compare'));

		// Work out the appropriate message to show at the top of the list, based on
		// whether this is a preview, a new edit to a page section or a new edit to
		// the full page.
			if ($editPage->preview) {
				$Msg = 'wikidb-tablesusedpreview';
			}
			elseif ($editPage->section != '') {
				$Msg = 'wikidb-tablesusedsection';
			}
			else {
				$Msg = 'wikidb-tablesused';
			}

		// Build the HTML output.
		// We use the same class as used for the 'templates used' section, so
		// that any CSS or JS code should continue to work correctly.
			$HTML = '<div class="mw-templatesUsedExplanation">'
				  . WikiDB_MsgExpanded($Msg, count($arrTitles), $output)
				  . "</div><ul>\n";

			foreach ($arrTitles as $objTitle) {
				$HTML .= '<li>';

				$objTable = new WikiDB_Table($objTitle);
				if ($objTable->IsAlias()) {
					$HTML .= '<span class="WikiDB_Redirect">'
						   . WikiDB_EscapeHTML($objTable->GetFullName())
						   . '</span> &rarr; ';
					$objTable = $objTable->GetDataDestination();
					$objTitle = $objTable->GetTitle();
				}

			// Show info about the number of data rows defined for this table in the
			// current text.
				$RowCountInfo = WikiDB_Msg('wikidb-tablesused-rowcount',
										   $arrTitleCounts[$objTitle->getDBkey()]);

			// If this is not a new article, we make the row count into a link to
			// view existing data defined by the article.
				if (WikiDB_ArticleExists($PageArticle)) {
					$SourceArticle = $PageTitle->getPrefixedText();
					$arrLinkParams = array(
								'action' => 'viewdata',
								'sourceArticle' => $SourceArticle,
							);
					$Link = $objTitle->getLocalURL($arrLinkParams);

				// Build the link manually, rather than using WikiDB_MakeLink(),
				// as we don't want the link to be red when the table doesn't exist.
					$RowCountInfo = '<a href="' . WikiDB_EscapeHTML($Link) . '">'
								  . $RowCountInfo
								  . '</a>';
				}

				$HTML .= WikiDB_MakeLink($objTitle)
					   . ' (' . $RowCountInfo . ')'
					   . '</li>';
			}

			$HTML .= '</ul>';

		// Wrap in a <div> tag - this is not part of formatTemplates() but done
		// afterwards at the point it is output by the EditPage class.
		// We include the same class as used for the 'templates used' section, so
		// that any CSS or JS code should continue to work correctly, but with a
		// custom ID so that it can also be directly targeted.
			$HTML = '<div id="WikiDB_DataTablesUsed" class="templatesUsed">'
					. $HTML
					. '</div>';

			$editPage->editFormTextAfterTools .= $HTML;
		}

		return true;
	}

// efWikiDB_AddCSS()
// Handler for the 'BeforePageDisplay' hook.
// Adds the extension's CSS file to the skin's CSS output.  The output is generated
// as part of the <head> section, which is not ideal, but I've only tested it on
// v1.6.10 - perhaps it has been changed to a remote inclusion in later versions of
// MW.  Either way, I don't think we have much choice, as we can't guarantee that
// the extension's directory is web-accessible.  This method ensures the CSS works
// out-of-the-box, rather than requiring any additional steps to embed the CSS.
// TODO: We should use the resource loader, which was added in MW 1.17 (though still
//		 with this fallback for 1.16).
	function efWikiDB_AddCSS(&$Output) {
	// Add extension CSS.
		pWikiDB_AddCSSFile($Output, "WikiDB.css");

	// Return true, to allow other hooks to run.
		return true;
	}

// pWikiDB_AddCSSFile()
// Internal function to perform the actual CSS inclusion.
// $Output is the OutputPage object passed into the BeforePageDisplay hook.
// $Path is the path to the CSS file you want to include, relative to the extension's
// include folder.
// Note that we keep this as a separate function to efWikiDB_AddCSS() to avoid
// duplication if we have multiple CSS files that need to be added (as has been the
// case in the past).
	function pWikiDB_AddCSSFile(&$Output, $Path) {
	// Get the contents of the specified CSS file.
		$NewCSS = file_get_contents(dirname(__FILE__) . "/../" . $Path);

		$Output->addInlineStyle($NewCSS);
	}

// DisplayHookInput()
// Testing function.  Add this to a hook and it will print out the value
// of the arguments passed into the hook (actually, it displays the arguments of the
// calling function, whether it is a hook or not).
// Passing true into the function will cause the script to die() after the arguments
// have been displayed, otherwise it will return false (allowing you to use
//   return DisplayHookInput();
// to cancel the hook having displayed the arguments.
// As this is purely for debugging, there is no point using the MW message system
// for the strings.
// For debugging only, so no need to internationalise.
	function DisplayHookInput($KillScript = false) {
		$bt = debug_backtrace();

		print("<pre>\n");
		if (isset($bt[1]['args']))
			print_r($bt[1]['args']);
		else
			print("No arguments passed to function.\n");
		print("</pre>\n");

		if ($KillScript)
			die();
		else
			return false;
	}

// LogHookInput()
// Place at the start of your hook function to write a log entry to "hooks.log"
// whenever the hook is called.
// For debugging purposes only.  Not written to be totally robust and does not have
// any guards against race conditions due to concurrency.
	function LogHookInput() {
		$bt = debug_backtrace();

		$LogOutput = "[" . date("Y-m-d H:m:s") . "] "
				   . "Hook called: " . $bt[1]['function'] . "(";

		if (isset($bt[1]['args'])) {
			foreach ($bt[1]['args'] as $Arg) {
				if (is_object($Arg)) {
					if ($Arg instanceof LinksUpdate) {
						$Title = $Arg->getTitle();

						$LogOutput .= "LinksUpdate["
									. $Title->getFullText()
									. ($Title->exists() ? "" : " (deleted)")
									. "]";
					}
					else {
						$LogOutput .= get_class($Arg) . " object";
					}
				}
				elseif (is_array($Arg)) {
					$LogOutput .= "array(" . count($Arg) . ")";
				}
				elseif (is_string($Arg)) {
					$LogOutput .= "'" . $Arg . "'";
				}
				elseif (is_bool($Arg)) {
					$LogOutput .= $Arg ? "true" : "false";
				}
				else {
					$LogOutput .= $Arg;
				}

				$LogOutput .= ", ";
			}
			$LogOutput = substr($LogOutput, 0, -2);
		}

		$LogOutput .= ")";

		$fh = fopen("hooks.log", "a");
		fwrite($fh, $LogOutput . PHP_EOL);
		fclose($fh);
	}
