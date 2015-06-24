<?php
/**
 * This is a PHP script that is designed to be run from the console.
 * 
 * This script updates a PDO-compliant database so that the tables match 
 * your data model as defined in a public static `schema` attribute of 
 * each of your PHP Model classes.  
 * 
 * The schema should be an associative array in which the name of
 * the column is the key and the SQL datatype and any constraints on the 
 * column are the value.  
 * 
 * Example of `schema` attribute of a `Book` class:
 *     public static $schema = array(
 *         'id'     => 'INTEGER PRIMARY KEY AUTOINCREMENT',
 *         'title'  => 'TEXT NOT NULL',
 *         'author' => 'TEXT',
 *         'isbn'   => 'TEXT NOT NULL'
 *     );
 * 
 * @remark
 *     The current version was designed to work with SQLite3 -- it HAS NOT
 *     been tested against any other DBMS at this point.
 *     SQLite uses a restrictive version of SQL, so some operations could be
 *     done more efficiently for other DBMSs, but that wouldn't have worked
 *     for my application.  The roadmap would include future expansion to other
 *     systems as well.
 * 
 * ****************************************************************************
 * The MIT License (MIT)
 * 
 * Copyright (c) 2015 Jason L Causey, Arkansas State University
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *******************************************************************************/

// ***  CONFIGURATION OPTIONS *************************************************
// You will need to adjust the following variables so that they match your 
// project's direcotry structure and database information.
// 
$models_path            = __DIR__ . '/../Models/';
$model_ns               = 'Models\\';
$db_file                = __DIR__ . '/../db/app.db';
$db_connection_string   = "sqlite:$db_file";
//
// ***  END OF CONFIGURATION  *************************************************

/**
 * creates a table given the schema as an associative array
 * where column names are keys and the values are types followed
 * by any necessary constraints
 * @param $db       PDO database handle
 * @param $table    string  name of the table to create
 * @param $schema   array of column names (keys) and type/constraint info (values)
 * @return boolean  TRUE if the table can be created, or FALSE otherwise
 */
function create_table($db, $table, $schema){
    $sql = "CREATE TABLE $table (";
    foreach($schema as $col => $type){
        $sql .= $col . ' ' . $type . ', ';
    }
    $sql = substr($sql, 0, -2);
    $sql .= ')';
    $res = $db->exec($sql);

    return $res !== FALSE;
}

/**
 * connect to the database, returning the connection handle
 * @return PDO database object
 */
function db_connect(){
    global $db_connection_string;
    return new PDO($db_connection_string);
}

$directory   = new RecursiveDirectoryIterator($models_path);
$iterator    = new RecursiveIteratorIterator($directory);
$regex       = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
$models      = array();
$model_ns_re = '/^' . addslashes($model_ns) . '/';

foreach($regex as $k => $v){
    $before = get_declared_classes();
    include_once "$k";
    $after  = array_diff(get_declared_classes(), $before);
    foreach($after as $key => $class_name){
        if(preg_match($model_ns_re, $class_name)){
            array_push($models, $class_name);
        }
    }
}

$db = db_connect();

foreach($models as $full_class_name){
    $class_name = str_replace($model_ns, '', $full_class_name);
    $table      = strtolower($class_name);
    $schema     = $full_class_name::$schema;
    $res        = $db->prepare("SELECT 1 FROM $table LIMIT 1");
    if($res === FALSE){
        print "Needs to create table $table\n";
        
        $OK = create_table($db, $table, $schema);
        
        if(!$OK){
            print "Error creating table `$table`: " . $db->errorInfo()[2] . "\n";
        }
        else{
            print "Created table `$table` for model $full_class_name.\n";
        }
    }
    else{
        print "Table `$table` for model $full_class_name found.\n";
    
        // Now check the table columns to make sure they match the current
        // schema.  If not, perform UPDATE statements as needed.
        $res         = $db->query("SELECT COUNT(*), * FROM $table LIMIT 1");
        $rowCount    = $res->fetchColumn()[0];
        $db_cols        = array();
        if($rowCount > 0){
            for ($i = 0; $i < $res->columnCount(); $i++) {
                $col = $res->getColumnMeta($i);
                if($col['name'] != 'COUNT(*)'){
                    array_push($db_cols, $col);
                }
            }
        }
        $res         = NULL; // release the query result to unlock the db table.
        $db_type_key = NULL;
        $copy_move   = FALSE;
        if($rowCount > 0){
            $columns = array();
            for ($i = 0; $i < count($db_cols); $i++) {
                $col = $db_cols[$i];
                if(!$db_type_key){
                    $keys = array_keys($col);
                    for($k = 0; $db_type_key == NULL && $k < count($keys); $k++){
                        if(preg_match('/:decl_type/', $keys[$k])){
                            $db_type_key = $keys[$k];
                        }
                    }
                }
                if($col['name'] != 'COUNT(*)'){
                    if(array_key_exists($col['name'], $schema)){
                        $schema_type = explode(" ", $schema[$col['name']]);
                        $schema_type = $schema_type[0];
                        if($schema_type != $col[$db_type_key]){
                            // Found type mismatch.
                            print "   [!] Type mismatch on column " . $col['name'] . ": ";
                            print $schema_type . " <- VS -> " . $col[$db_type_key] . "\n";
                            $copy_move = TRUE;
                        }
                        array_push($columns, $col['name']);
                    }
                    else{
                        // Need to remove this column.
                        print '   [!] Remove column `' .$col['name'] . "`\n";
                        $copy_move = TRUE;
                    }
                }
            }
            // See if we need to remove columns or change types -- either action will be done 
            // by applying a "table-swap" refactor so that is is SQLite-compatible
            if($copy_move){
                print("   [~] Attempting automatic refactor.  ");
                $tmptable = $table . "_tmp";
                $cols     = implode(", ", $columns);
                $db->beginTransaction();
                // Refactor by creating a new table, copying in the data, dropping the old table, then re-naming the new one back to the original name.
                $OK = create_table($db, $tmptable, $schema);
                if($OK){
                    // Move all the data over.
                    $res = $db->query("INSERT INTO $tmptable SELECT $cols FROM $table");
                    if($res === FALSE){
                        $res = NULL;
                        $OK  = FALSE;
                        // If moving the data fails, drop the temp table.
                        $db->rollBack();
                    }
                    if($OK){
                        // Now drop the old table.
                        $res = $db->exec("DROP TABLE $table");
                        if($res === FALSE){
                            $res = NULL;
                            $OK  = FALSE;
                            // If dropping the old table fails, we need to drop the temp one:
                            $db->rollBack();
                        }
                    }
                    if($OK){
                        // Now rename the tmp table to the original name:
                        $res = $db->exec("ALTER TABLE $tmptable RENAME TO $table");
                        if($res === FALSE){
                            $res = NULL;
                            $db->rollBack();
                        }
                    }
                }
                // See if the refactor worked out.  If not, give an error message:
                if($OK){
                    print "   [OK] \n";
                    $db->commit();
                }
                else{
                    print "   [FAILED] \n";
                }
            }
            // Now look for any new columns we need to create:
            $new_cols = array_keys($schema);
            $new_cols = array_diff($new_cols, $columns);
            foreach($new_cols as $col){
                print "   --- Adding column `$col`.";
                $sql = "ALTER TABLE $table ADD COLUMN $col " . $schema[$col];
                $res = $db->exec($sql);
                if($res === FALSE){
                    print "    [FAILED]\n";
                    $msg = $db->errorInfo();
                    print "       [i] " . $msg[2] . "\n";
                }
                else{
                    print "    [OK]\n";
                }
            }
        }
        print "\n";
    }
}

print "Done.\n";

