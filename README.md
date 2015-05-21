# php_model_to_db
Console PHP script to setup database tables corresponding to PHP Model classes.

## Purpose

This is a PHP script that is designed to be run from the console.

This script updates a PDO-compliant database so that the tables match 
your data model as defined in a public static `schema` attribute of 
each of your PHP Model classes.  

The schema should be an associative array in which the name of
the column is the key and the SQL datatype and any constraints on the 
column are the value.  

Example of `schema` attribute of a `Book` class:

```php
    public static $schema = array(
        'id'     => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'title'  => 'TEXT NOT NULL',
        'author' => 'TEXT',
        'isbn'   => 'TEXT NOT NULL'
    );
```

## Limitations

The current version was designed to work with SQLite3 --- it HAS NOT
been tested against any other DBMS at this point.
SQLite uses a restrictive version of SQL, so some operations could be
done more efficiently for other DBMSs, but that wouldn't have worked
for my application.  The roadmap would include future expansion to other
systems as well.
