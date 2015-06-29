<?php
/**
 * This is a PHP script that is designed to be run from the console.
 * 
 * USAGE:
 *      php update_db.php [options]
 *          Options:
 *              -d           :  Drop tables that are not required by any 
 *              --drop       :  Model (Warning: This cannot be undone!)
 *              
 *              -q           :  Reduced output
 *              --quiet      :
 *              
 *              -v           :  More informative output
 *              --verbose    :
 * 
 * This script updates a PDO-compliant database so that the tables match 
 * your data model as defined in a public static `schema` attribute of 
 * each of your PHP Model classes.
 * 
 * Table names are derived from class names by converting PHP-standard
 * CapCase class names to database-friendly snake_case names; this is 
 * the same algorithm used by the Paris framework: 
 * (https://github.com/j4mie/paris), so this script is compatible with
 * Paris (assuming the "_table_use_short_name" option is set to `true`).
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
 *     The current version was designed to work with SQLite3; it will not
 *     work with other DBMSs without major modification.
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
// DO NOT EDIT BELOW THIS POINT ***********************************************

$vlevel = 1;
define("V_ERR", 0);
define("V_NOTE", 1);
define("V_DBG", 2);
$system_tables = [
        'sqlite' => ['sqlite.*']
    ];
$db_type = explode(':', $db_connection_string)[0];

/**
 * main function - performs all requested operations.
 * 
 * @return int  0 on normal exit or 1 on error.
 */
function main(){
    $raw_options = parse_options();
    $options     = [];
    if(isset($raw_options['v']) || isset($raw_options['verbose'])){
        $options['verbosity'] = 2;
    }
    if(isset($raw_options['q']) || isset($raw_options['quiet'])){
        $options['verbosity'] = 0;
    }
    if(isset($raw_options['d']) || isset($raw_options['drop'])){
        $options['drop'] = TRUE;
    }
    return update_db($options) == TRUE ? 0 : 1;
}

/**
 * performs the database update
 * 
 * @param  array   $options  array of options with keys ('verbosity', 'drop') where
 *                           'verbosity' refers to how much output is produced and
 *                           'drop' is TRUE if the script should drop tables that do 
 *                           not correspond to any model
 * @return boolean TRUE if update is OK, or FALSE on error
 */
function update_db($options=[]){
    global $vlevel, $models_path, $model_ns, $system_tables, $db_type;
    $directory   = new RecursiveDirectoryIterator($models_path);
    $iterator    = new RecursiveIteratorIterator($directory);
    $regex       = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
    $models      = array();
    $tables      = array();
    $model_ns_re = '/^' . addslashes($model_ns) . '/';
    $drop_extra  = FALSE;
    $status      = TRUE;

    if(isset($options['verbosity'])){
        $vlevel = $options['verbosity'];
    }

    if(isset($options['drop'])){
        $drop_extra = TRUE;
    }

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

    $db     = db_connect();
    $tables = get_tables($db);
    
    foreach($models as $full_class_name){
        $class_name = str_replace($model_ns, '', $full_class_name);
        $table      = cap_case_to_snake_case($class_name);
        $schema     = $full_class_name::$schema;
        if(!in_array($table, $tables)){
            $OK = create_table($db, $table, $schema);
            if(!$OK){
                vprint( "[!] Error creating table `$table`: " . $db->errorInfo()[2] . "\n", V_ERR);
                $status = FALSE;
            }
            else{
                vprint( "[+] Created table `$table` for model `$full_class_name`.\n");
            }
        }
        else{
            vprint( "[i] Table `$table` for model `$full_class_name` found.\n");
            // Remove from the $tables list so that we can track un-matched tables:
            $tables = array_diff($tables, array($table));
            $res         = NULL; // release the query result to unlock the db table.
            $col_meta = [];
            foreach($db->query("PRAGMA table_info($table)") as $row){
                $col_meta[$row[1]] = [
                        'type'     => $row['type'],
                        'nullable' => $row['notnull'] == 0,
                        'default'  => $row['dflt_value'],
                        'pk'       => $row['pk']
                    ];
            }

            // Now check the table columns to make sure they match the current
            // schema.  If not, perform alterations as needed.            
            $copy_move   = FALSE;    
            $columns = array();
            foreach($col_meta as $col_name => $meta) {
                vprint( "   [i] checking column `$col_name`\n");
                if(array_key_exists($col_name, $schema)){
                    $schema_nullable   = stripos($schema[$col_name], "NOT NULL") === FALSE;
                    $schema_hasdefault = FALSE;
                    if(preg_match('/DEFAULT (\S+)/', $schema[$col_name], $matches) > 0){
                        $schema_hasdefault = TRUE;
                        $schema_default    = $matches[1];
                    }
                    $schema_pk = FALSE;
                    if(stripos($schema[$col_name], "PRIMARY KEY") !== FALSE){
                        $schema_pk = TRUE;
                    }
                    $schema_type   = explode(" ", $schema[$col_name]);
                    $schema_type   = $schema_type[0];
                    $schema_ortho  = $schema_type;
                    $schema_ortho .= $schema_nullable ? "" : " NOT NULL"; 
                    $schema_ortho .= ($schema_hasdefault ? " DEFAULT " . $schema_default : "" );
                    $schema_ortho .= $schema_pk ? " PRIMARY KEY" : "";

                    $meta_ortho  = $meta['type'];
                    $meta_ortho .= $meta['nullable'] ? "" : " NOT NULL";
                    $meta_ortho .= $meta['default'] !== NULL ? (" DEFAULT " . $meta['default']) : "";
                    $meta_ortho .= $meta['pk'] > 0 ? " PRIMARY KEY" : "";

                    if($schema_ortho != $meta_ortho){
                        // Found type mismatch.
                        vprint( "   [~] Type or constraint mismatch on column " . $col_name . ": ");
                        vprint( "New: " . $schema_ortho . "    Old: " . $meta_ortho . "\n");
                        $copy_move = TRUE;
                    }
                    array_push($columns, $col_name);
                }
                else{
                    // Need to remove this column.
                    vprint( '   [-] Remove column `' .$col_name . "`\n");
                    $copy_move = TRUE;
                }
            }
            // Now look for any new columns we need to create:
            $new_cols = array_keys($schema);
            $new_cols = array_diff($new_cols, $columns);
            foreach($new_cols as $col){
                vprint( "   [+] Adding column `$col`.");
                $sql = "ALTER TABLE $table ADD COLUMN $col " . $schema[$col];
                $res = $db->exec($sql);
                if($res === FALSE){
                    vprint( "    [FAILED]\n");
                    $msg = $db->errorInfo();
                    vprint( "       [i] " . $msg[2] . "\n");
                    $status = FALSE;
                }
                else{
                    vprint( "    [OK]\n");
                }
            }
            // See if we need to remove columns or change types or constraints
            // -- either action will be done by applying a "table-swap" 
            // refactor so that is is SQLite-compatible
            if($copy_move){
                vprint(("   [~] Refactoring table `$table`.  "));
                $tmptable = $table . "_tmp";
                $cols     = implode(", ", $columns);
                $db->beginTransaction();
                // Refactor by creating a new table, copying in the data, dropping the old table, then re-naming the new one back to the original name.
                $OK = create_table($db, $tmptable, $schema);
                if($OK){
                    // Move all the data over.
                    $res = $db->query("INSERT INTO $tmptable ($cols) SELECT $cols FROM $table");
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
                    vprint( "   [OK] \n");
                    $db->commit();
                }
                else{
                    vprint( "   [FAILED]: " . $db->errorInfo()[2] . "\n");
                    $status = FALSE;
                }
            }        
            vprint( "\n");
        }
    }
    // Now drop tables that aren't system tables and don't correspond to any model (only
    // if the user requested this action).
    if($drop_extra && count($tables) > 0){
        foreach($tables as $table){
            if(preg_match('/(' . implode(')|(', $system_tables[$db_type]) . ')/', $table) < 1){
                vprint("[-] Removing table `$table` that does not correspond to any Model: ");
                $OK = drop_table($db, $table);
                if($OK){
                    vprint("[OK]\n");
                }
                else{
                    vprint("[FAILED]" . $db->errorInfo()[2] . "\n");
                    $status = FALSE;
                }
            }
        }
    }

    vprint( "Done.\n");
    return $status;
}

/**
 * creates a table given the schema as an associative array
 * where column names are keys and the values are types followed
 * by any necessary constraints
 * 
 * @param  $db       PDO database handle
 * @param  $table    string  name of the table to create
 * @param  $schema   array of column names (keys) and type/constraint info (values)
 * @return boolean   TRUE if the table can be created, or FALSE otherwise
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
 * drops a table from the database
 * 
 * @param  $db       PDO database handle
 * @param  $table    string  name of the table to drop
 * @return boolean   TRUE if the table was dropped, or FALSE otherwise
 */
function drop_table($db, $table){
    $sql = "DROP TABLE $table";
    $res = $db->exec($sql);
    return $res !== FALSE;
}

/**
 * connect to the database, returning the connection handle
 * 
 * @return PDO database object
 */
function db_connect(){
    global $db_connection_string;
    return new PDO($db_connection_string);
}

/**
 * get an array listing all tables in the current database
 * 
 * @param  $db       PDO database handle
 * @return array     names of all tables in the current database
 */
function get_tables($db){
    $tables       = array();
    $tablesquery  = $db->query("SELECT name FROM sqlite_master WHERE type='table';");
    while ($table = $tablesquery->fetch(PDO::FETCH_ASSOC)) {
        $tables[] = $table['name'];
    }
    return $tables;
}

/**
 * CapCase will be converted to snake_case table names,
 * using the same algorithm as in the fantastic Paris framework:
 * (https://github.com/j4mie/paris) 
 * 
 * Example: BookList would be converted to book_list.
 * 
 * @param  string   $class_name  the class name to convert
 * @return string   the snake_case version of `$class_name`
 */
function cap_case_to_snake_case($class_name) {
    return strtolower(preg_replace(
        array('/(?<=[a-z])([A-Z])/', '/__/'),
        array('_$1',                 '_'),
        $class_name
    ));
}

/**
 * parse command-line options
 * 
 * @return associative array of command line options
 */
function parse_options(){
    $shortopts  = "";
    $shortopts .= "dqv";   // drop, quiet, verbose

    $longopts  = array(
        "drop",            // drop tables not needed by a model
        "quiet",           // minimal output (only errors)
        "verbose"          // more output than usual
    );
    return getopt($shortopts, $longopts);
}

/**
 * print message according to the level of verbosity the user
 * has selected: 0 (only errors), 1 (normal), 2 (verbose)
 * 
 * @param string  $msg     the message to display
 * @param integer $urgency urgency level: 0: serious, 1: normal, 2: verbose-mode only
 */
function vprint($msg, $urgency=1){
    global $vlevel;
    if($vlevel >= $urgency){
        echo $msg;
    }
}

//***********DRIVER****************************************************
// Execute the main function:
if (php_sapi_name() == "cli") {
    // In cli-mode
    main();
} 
