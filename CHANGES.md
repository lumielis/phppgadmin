# phpPgAdmin Change Log

## Version 8.0.rc1 (Unreleased)

### Features

- **New Catalogs Navigation**: System catalog schemas (`pg_catalog` and `information_schema`) are now displayed in a separate "Catalogs" section in the tree navigation, matching the familiar convention used in pgAdmin III/4 since 2011. This provides clearer organization by separating system metadata from user-created schemas.
- Add support for Postgres 15-18

### Bugs

- TBD

### Incompatibilities

- PostgreSQL minimum version: 9.0
- PHP minimum version: 7.2

---

## Version 7.13.0

**Released**: November 7th, 2020

### Features

- Add support for Postgres 13
- Add provisional support for Postgres 14
- Upgrade jQuery library to 3.4.1 (Nirgal)
- Allow users to see group owned databases when using "owned only"

### Bugs

- Fix bug where sorting on selects dumped you to the table screen (MichaMEG)

### Incompatibilities

- This release drops support for PHP 7.1
- This will be the last release to support PHP 7.2

---

## Version 7.12.1

**Released**: December 10th, 2019

### Features

- Add support for granting USAGE on sequences
- Update French translation

### Bugs

- Fix issues with OID removal in Postgres 12+
- Remove broken tree branch from table/view browse option
- Properly escape identifiers when browsing tables/views/schemas
- Fix truncation of long multibyte strings
- Clean up a number of misspellings and typos from codespell report

### Incompatibilities

- Require mbstring module support in PHP

---

## Version 7.12.0

**Released**: September 28, 2019

### Features

- Add Support for PHP 7.x
- Add Support for Postgres 12
- Update Bootstrap to version 3.3.7 (wisekeep)

### Bugs

- Fix several issues with CSS files (wisekeep)
- Clean up file permissions (nirgal)
- Fixed Reflected XSS vulnerability (om3rcitak)
- Fixes with sequence visibility and permission handling

### Incompatibilities

- We no longer support PHP 5
- Change in version numbering system

---

## Version 5.6

**Released**: November 12th, 2018

### Features

- Add support for PostgreSQL 9.3, 9.4, 9.5, 9.6, 10, 11
- Development support for PostgreSQL 12
- Add support for browse/select navigation tabs (firzen)
- Add new theme, "bootstrap" (amenadiel)
- Improved support for json/jsonb

### Bugs

- Fix bug in Turkish translation which caused failed ajax responses
- Account for Blocked field in admin processes Selenium test
- Properly handle column comments
- Fix background css issue
- Additional language updates

### Incompatibilities

- Dropped testing of pre-9.3 versions of Postgres, which are now EOL

---

## Version 5.1

**Released**: April 14th, 2013

### Features

- Full support for PostgreSQL 9.1 and 9.2
- New plugin architecture, including addition of several new hooks (asleonardo, ioguix)
- Support nested groups of servers (Julien Rouhaud & ioguix)
- Expanded test coverage in Selenium test suite
- Highlight referencing fields on hovering Foreign Key values when browsing tables (asleonardo)
- Simplified translation system implementation (ioguix)
- Don't show cancel/kill options in process page to non-superusers
- Add download ability from the History window (ioguix)
- User queries now paginate by default

### Bugs

- Fix several bugs with bytea support, including possible data corruption bugs when updating rows that have bytea fields
- Numerous fixes for running under PHP Strict Standards
- Fix an issue with autocompletion of text based Foreign Keys
- Fix a bug when browsing tables with no unique key

### Translations

- Lithuanian (artvras)

### Incompatibilities

- We have stopped testing against Postgres versions < 8.4, which are EOL
- phpPgAdmin core is now UTF-8 only

---

## Version 5.0

**Released**: November 29th, 2010

### Features

- Support for PostgreSQL 8.4 and 9.0
- Support for database level collation for 8.4+
- Support for schema level export
- Add ability to alter schema ownership
- Clean up domain support and improve interface
- Add support for commenting on functions
- Allow user to rename role/users and set new passwords at the same time
- Greatly enhanced Full-Text-Search capabilities (ioguix, Loomis_K)
- Overhauled Selenium Test suite to support multiple database versions
- Optimized application graphics (Limo Driver)
- Support for Column Level Privileges
- Allow users to specify a template database at database creation time
- Support killing processes
- Add ability to create indexes concurrently
- Much better support of autovacuum configuration
- Add an admin page for table level
- Refactored autocompletion:
    - Fix support for cross-schema objects
    - Support multi-field FK
    - Support for pagination of values in the auto-complete list
- Allow user to logically group their server under custom named node in the browser tree
- New themes (Cappuccino and Gotar) and a theme switcher on the introduction page
- Auto refresh Locks page
- Auto refresh Processes page
- Link in the bottom of the page to go to top of page
- Browsing on Foreign Keys (When browsing a table, clicking on a FK value, jump to the PK row)

### Bugs

- Fix problems with query tracking on overly long queries
- Ensure pg_dump paths are valid
- Fix multiple bugs about quoting and escaping database objects names with special chars
- Fix multiple bugs in the browser tree
- Fix multiple bugs on the SQL and script file import form
- Three security fix about code injection
- Don't allow inserting on a table without fields
- Some fix about commenting databases
- Removed deprecated functions from PHP 5.3
- Lot of code cleanup
- Many other small minor bugs found on our way
- Fix the operator property page

### Translations

- Czech (Marek Cernocky)
- Greek (Adamantios Diamantidis)
- Brazilian Portuguese (Fernando Wendt)
- Galician (Adrián Chaves Fernández)

### Incompatibilities

- No longer support PHP < 5.0
- No longer support Postgres < 7.4

---

## Version 4.2

### Features

- Add Analyze to Table Level Actions (ioguix)
- Add support for multiple actions on main pages (ioguix, Robert Treat)
- Added favicon for Mozilla and a backwards compatible version for IE
- Allow browsers to save different usernames and passwords for different servers
- Pagination selection available for reports
- You can configure reports db, schema and table names
- Add support for creating a table using an existing one (ioguix)
- Auto-expand a node in the tree browser if there are no other nodes (Tomasz Pala)
- Add column about fields constraints type + links in table properties page (ioguix)
- Support for built-in Full Text Search (Ivan Zolotukhin)
- Add alter name, owner & comment on views (ioguix)
- Add column about called procedure + links to their definition in the triggers properties page (ioguix)
- Add Support for Enum type creation (ioguix,xzilla)
- Add alter name, owner, comment and properties for sequences (ioguix)
- Add function costing options (xzilla)
- Add alter owner & schema on function (xzilla)
- Add a popup window for the session requests history (karl, ioguix)
- Add alter table, view, sequence schema (ioguix)

### Bugs

- Fix inability to assign a field type/domain of a different schema
- Can't edit a report and set its comment to empty
- Fix PHP5 Strict mode complaints
- Fix IN/NOT IN to accept text input lists 'a','b'
- Fix bytea doesn't display as NULL when NULL
- Schema qualifying every object to avoid non wanted behaviour about users' rights and schema_path
- Remove shared credentials when logging out of single server, to prevent automatic re-login
- Improved SSL connection handling, fix problems with connections from older php builds
- Fix bug with long role name truncation
- Fix bug with DELETE FROM when dropping a row (spq)
- Fix problems when deleting PUBLIC schema
- Fix several bugs in aggregate support
- Improve autocompletion support
- Tighten up use of global scope variables

### Translations

- UTF Traditional Chinese (Kuo Chaoyi)
- UTF Simplified Chinese (Kuo Chaoyi)
- Italian (Nicola Soranzo)
- Catalan (Bernat Pegueroles)
- French (ioguix)
- German (Albe Laurenz, spq)
- Japanese (Tadashi Jokagi)
- Hungarian (Sulyok Peti)

---

_For versions 4.1.3 and earlier, see the original HISTORY file_
