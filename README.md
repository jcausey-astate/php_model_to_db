# php_model_to_db
`update_db.php` is a standalone console PHP script to set up or modify database tables corresponding to PHP Model classes.

## Purpose

This is a PHP script that is designed to be run from the console.

This script updates a PDO-compliant database so that the tables match 
your data model as defined in a public static `schema` attribute of 
each of your PHP Model classes (see below). 

The ideal use of this script is bootstrapping a database in support of a PHP application that uses the Active Record pattern (or an Object-Relational pattern).  This keeps you from having to worry about creating and altering tables in raw SQL and lets you focus on building your application.

### Embedding the Schema 

The schema should be an associative array in which the name of
the column is the key and the SQL datatype and any constraints on the 
column are the value.  

Example of `schema` attribute of a `Book` class:

```php
    public static $schema = [
        'id'     => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'title'  => 'TEXT NOT NULL',
        'author' => 'TEXT',
        'isbn'   => 'TEXT NOT NULL'
    ];
```

## Usage

### Configuration

Before you can use the script, you need to set a few configuration options (located near the top of the script and clearly marked):

* `$models_path` : The directory in which your Model classes are located.
* `$model_ns`    : The PHP Namespace of your Model classes.
* `$db_file`     : The path and name of the file containing your (Sqlite3) database. _NOTE:_ If you are not using Sqlite, you may ignore this option.
* `$db_connection_string` : PHP PDO-style connection string for your database.

In order to use this script, your Model classes must all be accessible within the `$model_path` directory, and they must all be in the same namespace, given by `$model_ns`.

### Executing the Script

`php update_db.php `&nbsp;_`[options]`_

Where _`[options]`_ consist of:<br />
&nbsp;&nbsp;&nbsp;`-d` or `--drop` :  Drop tables that are not required by any Model<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(Warning: This cannot be undone!)   
&nbsp;&nbsp;&nbsp;`-q` or `--quiet` : Reduced output   
&nbsp;&nbsp;&nbsp;`-v` or `--verbose` :  More informative output

The script will report success or failure, and attempt to give an indication of what went wrong if a failure occurs.

## Limitations

The current version was designed to work with SQLite3 &mdash; it will not work with other DBMSs without major modifications.
SQLite uses a restrictive version of SQL, so some operations could be
done more efficiently for other DBMSs, but that wouldn't have worked
for my application.  The roadmap would include future expansion to other
systems as well.

## License

**The MIT License (MIT)**

_Copyright (c) 2015 Jason L Causey, Arkansas State University_

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
