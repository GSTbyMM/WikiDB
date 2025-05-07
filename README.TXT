Note: This is fork of the original extension compatible with MediaWiki 1.43.1 with PHP 8.4.

== ADDED FEATURES ==
- LIKE operator
In the Criteria field "latin maxim LIKE %Audi%" where "latin maxim" is the field name and "Audi" is the value we are searching for. 
For exact word match, use %Audi%.
For starts with: Audi%
For ends with: %Audi

- Date data type and BETWEEN operator
Date formats: dd-mm-YYYY and dd/mm/YYYY
Syntax for BETWEEN operator: fieldname BETWEEN date1 date2

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

- Mark Clements (HappyDog)
  mclements@kennel17.co.uk
