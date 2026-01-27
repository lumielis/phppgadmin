# phpPgAdmin Frequently Asked Questions

## Installation Errors

### Q: I've installed phpPgAdmin but when I try to use it I get an error message telling me that I have not compiled proper database support into my PHP installation.

**A:** This means that you have not properly compiled Postgres support into your PHP. The correct configure flag to use is `--with-pgsql`. Read the [PHP manual](http://www.php.net/manual/en/pgsql.setup.php) and website for more help with this.

Postgres support can be also compiled into PHP as a dynamic extension, so if you have a precompiled version (Linux RPM, or Windows binary), there are still chances that all you need to do is enable loading it automatically.

This can be done by editing your `php.ini` file:

- **Windows:** Usually in `C:\WINDOWS` or `C:\WINNT`
- **Linux:** `/etc/php.ini`

Uncomment this line:

```ini
;extension=php_pgsql.dll    ;under Windows
;extension=pgsql.so         ;under Linux
```

So it looks like:

```ini
extension=php_pgsql.dll     ;under Windows
extension=pgsql.so          ;under Linux
```

In Linux distributions based on Red Hat or Fedora, PHP extensions are automatically configured in `/etc/php.d/pgsql.ini`. Simply install the `php-pgsql` package:

```bash
sudo yum install php-pgsql    # Red Hat/CentOS/Fedora
sudo dnf install php-pgsql    # Fedora 22+
```

See the [PostgreSQL setup documentation](http://www.php.net/manual/en/pgsql.setup.php) for more information.

---

### Q: I get a warning like this when using phpPgAdmin on Windows:

```
Warning: session_start() [function.session-start]:
  open(/tmp\sess_5a401ef1e67fb7a176a95236116fe348, O_RDWR) failed
```

**A:** You need to edit your `PHP.INI` file (usually in `c:\windows`) and change this line:

```ini
session.save_path = "/tmp"
```

To:

```ini
session.save_path = "c:\windows\temp"
```

Make sure that the folder `c:\windows\temp` actually exists.

---

## Login Errors

### Q: I always get "Login failed" even though I'm _sure_ I'm using the right username and password.

**A:** There are several reasons why you might not be able to connect, typically having nothing to do with phpPgAdmin itself. First, check the PostgreSQL log on your server. It should contain a `FATAL` error message detailing the exact reason why the login is failing.

You will probably need to either:

- Adjust the username or password
- Add LOGIN permissions to the role
- Adjust your `pg_hba.conf` file in your PostgreSQL data directory

Follow the directions laid out in the FATAL error message.

If you do not have any FATAL error messages and you have verified that you are looking at the properly configured log file, then this means you are not connecting to your database.

**If connecting via TCP/IP sockets** (for example, if phpPgAdmin is installed on a different computer than your database):

Make sure PostgreSQL is accepting connections over TCP/IP:

- **Older PostgreSQL versions:** Change this line in `postgresql.conf`:

    ```
    #tcpip_socket = false
    ```

    To:

    ```
    tcpip_socket = true
    ```

- **Newer PostgreSQL versions:** Change the `listen_addresses` setting:
    ```
    listen_addresses = '*'    # or specific IP addresses
    ```

**Important:** Be sure to restart PostgreSQL after changing either of these settings!

If that still doesn't get you connected, there is likely something interfering between PHP and PostgreSQL:

- Check for firewalls preventing connectivity
- Check for security policies (e.g., SELinux) that prevent PHP from connecting
- Verify network connectivity between the web server and database server

---

### Q: For some users I get a "Login disallowed for security" message.

**A:** Logins via phpPgAdmin with no password or certain usernames (`pgsql`, `postgres`, `root`, `administrator`) are denied by default for security reasons.

Before changing this behavior (setting `$conf['extra_login_security']` to `false` in `config.inc.php`), please read the [PostgreSQL documentation about client authentication](https://www.postgresql.org/docs/current/client-authentication.html) and understand how to change PostgreSQL's `pg_hba.conf` to enable password-protected local connections.

---

### Q: I can use any password to log in!

**A:** PostgreSQL, by default, runs in "trust" mode. This means that it doesn't ask for passwords for local connections. We highly recommend that you:

1. Edit your `pg_hba.conf` file
2. Change the login type to `md5` (or `scram-sha-256` for PostgreSQL 10+)

**Note:** If you change the `local` login type to require passwords, you might need to enter a password to start PostgreSQL. Work around this by using a `.pgpass` file — see the [PostgreSQL documentation](https://www.postgresql.org/docs/current/libpq-pgpass.html) for details.

---

## Other Errors

### Q: When I enter non-ASCII data into the database via a form, it's inserted as hexadecimal or `&#1234;` format!

**A:** You have not created your database in the correct encoding. This problem will occur when you try to enter:

- An umlaut into an `SQL_ASCII` database
- SJIS Japanese into an `EUC-JP` database
- Any character outside the database's encoding

Solution: Recreate your database with the correct encoding:

```sql
CREATE DATABASE mydb ENCODING 'UTF8' LOCALE 'en_US.utf8';
```

---

### Q: When I drop and re-create a table with the same name, it fails.

**A:** You need to drop the sequence attached to the `SERIAL` column of the table as well.

PostgreSQL 7.3 and above handle this automatically. If you have upgraded to PostgreSQL 7.3 from an earlier version, you need to run the `contrib/adddepend` script to record all dependencies.

---

### Q: When browsing a table, the 'edit' and 'delete' links do not appear.

**A:** In order of preference, phpPgAdmin uses the following as unique row identifiers:

1. **Primary Keys** (preferred)
2. **Unique Keys** (cannot be partial or expressional indexes)
3. **OID Column** (will require a sequential scan to update, unless you index the OID column)

Additionally:

- Any `NULL` values in the unique index will make that row uneditable
- Since OIDs can become duplicated in a table, phpPgAdmin will alter the row and check that exactly one row has been modified — otherwise it will rollback

To ensure rows can be edited, make sure your tables have:

- A primary key, OR
- A unique constraint with no `NULL` values

---

## Questions on Dumps

### Q: What happened to the database dump feature?

**A:** You need to configure phpPgAdmin (in `config.inc.php`) to point to the location of the `pg_dump` and `pg_dumpall` utilities on your web server. Once you have done that, the database export feature will appear.

Example configuration:

```php
$conf['servers'][0]['pg_dump_path'] = '/usr/bin/pg_dump';
$conf['servers'][0]['pg_dumpall_path'] = '/usr/bin/pg_dumpall';
```

---

### Q: I would like to use the pg_dump integration for database and table dumps on Windows. How do I get pg_dump.exe on Windows?

**A:** To get the `pg_dump` utilities on Windows:

1. Install **PostgreSQL 8.0 or higher** (we recommend the latest release) for Windows
    - Download from the [PostgreSQL website](http://www.postgresql.org/download/windows)

2. Set the `pg_dump` and `pg_dumpall` locations in `config.inc.php`:

    ```php
    $conf['servers'][0]['pg_dump_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe';
    $conf['servers'][0]['pg_dumpall_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dumpall.exe';
    ```

    Adjust the version number (18) and path as appropriate for your installation.

---

### Q: Why can't I reload the SQL script I dumped in the SQL window?

**A:** The following limitations currently exist in SQL script execution:

- **COPY commands:** Only uploaded SQL scripts can contain `COPY` commands, and PHP 4.2 or higher is required
- **psql commands:** Commands such as `\connect` will not work
- **Multiline statements:** Statements split across multiple lines will not work

    Example that won't work:

    ```sql
    CREATE TABLE example (
        a INTEGER
    );
    ```

- **Database/user switching:** You cannot change the current database or current user during script execution

For these limitations, we recommend using the `psql` utility to restore your full SQL dumps:

```bash
psql -U username -d database_name -f dump.sql
```

We intend to work on some of these limitations in the future, but some are PostgreSQL restrictions.

---

## Other Questions

### Q: When inserting a row, what does the 'Value' or 'Expression' box mean?

**A:** There are two options for entering data:

- **'Expression':** You can use functions, operators, field names, etc. in your value
    - You must properly quote any literal values yourself
    - Example: `current_timestamp`, `table2.column_name`, `UPPER('text')`

- **'Value':** The data is inserted as-is into the database
    - No interpretation occurs
    - Example: The string `123` is inserted as text, not a number

Choose 'Expression' for database functions and references, 'Value' for literal data.

---

### Q: Why is there never any information on the 'Info' page of a table?

**A:** The Info page displays:

- Tables that have foreign keys to the current table
- Data from the PostgreSQL statistics collector

In older versions of PostgreSQL, the statistics collector is not enabled by default. To enable it:

1. Look in your `postgresql.conf` file for the `stats_*` options
2. Set them all to `true`:
    ```
    track_activities = on
    track_counts = on
    track_io_timing = on
    track_functions = 'all'
    ```
3. Restart PostgreSQL

---

### Q: Why can't I download data from queries executed in the SQL window?

**A:** You need to check the **'Paginate results'** option to allow downloads.

When 'Paginate results' is enabled, phpPgAdmin will provide download options for query results in various formats (SQL, CSV, JSON, etc.).

---

### Q: I would like to help out with the development of phpPgAdmin. How should I proceed?

**A:** We really would like your help! Please read the following files:

- [DEVELOPERS](DEVELOPERS) — Development guidelines, git workflow, and coding standards
- [TRANSLATORS](TRANSLATORS) — How to contribute translations
- [README.md](README.md) — Contributing section with more details

**Quick start:**

1. Fork the repository on [GitHub](https://github.com/phppgadmin/phppgadmin)
2. Read [DEVELOPERS](DEVELOPERS) for the development workflow
3. Make your changes on a feature branch
4. Submit a Pull Request with a clear description

We welcome contributions in many areas:

- Bug fixes
- New features
- Documentation improvements
- Translations
- Theme designs
- Test cases

See [README.md - Contributing](README.md#contributing) for detailed guidelines.

---

## Need More Help?

If your question isn't answered here:

1. Check the [PostgreSQL documentation](https://www.postgresql.org/docs/)
2. Review the [README.md](README.md)
3. Search [GitHub Issues](https://github.com/phppgadmin/phppgadmin/issues)
4. Check the PostgreSQL and PHP server logs for error messages
5. Create a [new issue](https://github.com/phppgadmin/phppgadmin/issues/new) with detailed information

When reporting issues, please include:

- phpPgAdmin version
- PostgreSQL version
- PHP version
- Detailed error messages
- Steps to reproduce the problem
