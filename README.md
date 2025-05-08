Note: This is fork of the original extension compatible with MediaWiki 1.43.1 with PHP 8.4.

== ADDED FEATURES IN THIS FORK ==
1. **Free Text Search (with options) in SourceArticles with Conditions**

If you not only want to filter the rows on the basis of Meta data(property=value) you entered using <data> tag on the pages but also want to query the text of those pages to further narrow down the results then you can simply search your text along with conditions in the Criteria field.

* Syntax: "word1 word2 word3 word4" CONDITION
* Options: > Match any word > Match all words > Match exact phrase 
* Example: "Hello World" Formation Date BETWEEN 04-10-2017 05-11-2025 - Option selected: Match all words - This will display all rows where Date in Formation Date field is BETWEEN(including) 04-10-2017 and 05-11-2025 AND their SourceArticle pages text consists of "Hello" and "World" both anywhere in any sequence. In other words, it will eliminate all rows where "Hello" and "World" both do not exists in the text of the SourceArticle pages evenif their Formation Date field consists of Date falls between(including) 04-10-2017 and 05-11-2025.
* Options explanation: <br>
-> Match any word - Search database with any of the words entered !<br>
-> Match all words - Search database with "all words" present together but in any order !<br>
-> Match exact phrase - Search database with "all words" present together and in the same order !<br>

2. **LIKE operator**

In the Criteria field "latin maxim LIKE %Audi%" where "latin maxim" is the field name and "Audi" is the value we are searching for. <br>
For exact word match, use %Audi%.<br>
For starts with: Audi%<br>
For ends with: %Audi<br>

3. **Date data type and BETWEEN operator**

Date formats: dd-mm-YYYY and dd/mm/YYYY<br>
Syntax for BETWEEN operator: fieldname\<space\>BETWEEN\<space\>date1\<space\>date2<br>
Example: Enactment Date BETWEEN 13-04-2017 30-10-2027

4. **"Col" attribute for \<repeat> tag**

If you want to render only a selected columns of the table then you can use "col" attribute in <repeat> tag to specify those columns. <br>
Syntax: \<repeat table="tableName" col="col1, col3, col5"></repeat><br>
Example: \<repeat table="CountriesData" criteria="country=India AND state=rajasthan" col="cities, longitude, latitude" order="cities"></repeat> will display table with only 3 columns Cities, Longitude, Latitude where names of cities are sorted alphabatically.

== SUMMARY ==

WikiDB is a MediaWiki extension which can be used to add database
functionality to your wiki.  Its core principal is to do this whilst
still following a wiki-like workflow for creating and managing data.

Data is therefore defined in-page via the standard editing process and,
just as you can create links to pages that don't exist, you can put
data into a table that doesn't exist, and you are able to display and
query that data without requiring any formal structure to be defined.

== FURTHER INFORMATION ==

* Overview:         http://www.kennel17.co.uk/testwiki/WikiDB
* Setup guide:      http://www.kennel17.co.uk/testwiki/WikiDB/Installation
* Changelog:        http://www.kennel17.co.uk/testwiki/WikiDB/CHANGELOG
* Issue tracker:    http://www.kennel17.co.uk/testwiki/WikiDB/Bugs

== DISCLAIMER ==

This code is provided as-is, without any warranty of any kind, either
express or implied.  Although the software is tested before release,
and it is in regular use on a number of wikis (including my own), as
with all software it is inevitable that some bugs will slip through.

As the software author, I cannot be held liable for any loss or damage,
database corruption, alien invasion, bad weather or any other problem
that arises directly, indirectly or co-incidentally from the use of
this software.  Did I include database corruption in that list?

By installing or using this extension, you are accepting that you
are doing so at your own risk.  If you do not agree with this then
do not install or use WikiDB.

Originally Developed by
- Mark Clements (HappyDog)
  mclements@kennel17.co.uk
