<?php
/**
 * Wiq_MySQLDiff class definition
 *
 * @author     Jorik Schouten  <jorik@webiq.nl>
 * @author     Hugo Zonderland <hugo@webiq.nl>
 * @link       http://www.webiq.nl
 * @copyright  2009-2014 WebIQ
 * @license    Proprietary/Closed Source
 * By viewing, using, or actively developing this application in any way, you are
 * henceforth bound to a confidentiality agreement, and all of its changes, set forth by
 * WebIQ.
 */
class Wiq_MySQLDiff
{
    /**
     * @var string SQL contents
     */
    public $sql;

    /**
     * Drops db
     *
     * @param $db
     */
    public function drop_schema_db($db)
    {
        if (!$db->schema) {
            return;
        }

        mysql_query("drop database {$db->database}", $db->link);
    }

    /**
     * Creates scheme
     *
     * @param $db
     */
    public function create_schema_db($db)
    {
        if (!$db->schema) {
            return;
        }

        if (!mysql_query("create database {$db->database}", $db->link)) {
            $this->error('Error of create database ' . mysql_error());
        }
    }

    /**
     * Loads scheme
     *
     * @param $db
     */
    public function load_schema_db(&$db)
    {
        if (!$db->schema) return;
        $sql = explode(";", file_get_contents($db->schema));
        foreach ($sql as $q) {
            if (!trim($q)) {
                continue;
            }
            if (preg_match('/^\s*\/\*.*\*\/\s*$/', $q)) {
                continue;
            }
            if (preg_match('/^\s*drop /i', $q)) {
                continue;
            }
            if (!mysql_query($q, $db->link)) {
                $this->error("Error in load schema db '$q'" . mysql_error());
            }
        }
    }

    /**
     * Gets scheme info
     *
     * @param $db
     */
    public function populate_schemata_info(&$db)
    {
        if (!($result = mysql_query("select * from information_schema.schemata where schema_name='$db->database'", $db->link))) {
            return FALSE;
        }

        if ($info = mysql_fetch_object($result)) {
            $db->charset = $info->DEFAULT_CHARACTER_SET_NAME;
            $db->collation = $info->DEFAULT_COLLATION_NAME;
        }
    }

    /**
     * Lists tables
     *
     * @param $db
     * @return array|bool
     */
    public function list_tables($db)
    {
        if (!($result = mysql_query("select TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, CHECKSUM, TABLE_COMMENT from information_schema.tables where table_schema='$db->database'", $db->link))) {
            return FALSE;
        }

        $tables = array();
        while ($row = mysql_fetch_object($result)) {
            $tables[$row->TABLE_NAME] = $row;
        }

        return $tables;
    }

    /**
     * List columns
     *
     * @param $table
     * @param $db
     * @return array|bool
     */
    public function list_columns($table, $db)
    {
        // Note the columns are returned in ORDINAL_POSITION ascending order.
        if (!($result = mysql_query("select * from information_schema.columns where table_schema='$db->database' and table_name='$table' order by ordinal_position", $db->link))) {
            return FALSE;
        }

        $columns = array();
        while ($row = mysql_fetch_object($result)) {
            $columns[$row->COLUMN_NAME] = $row;
        }

        return $columns;
    }

    /**
     * Lists indexes
     *
     * @param $table
     * @param $db
     * @return array
     */
    public function list_indexes($table, $db)
    {
        if (!($result = mysql_query("show indexes from `$table`", $db->link))) {
            return FALSE;
        }

        $indexes = array();
        $prev_key_name = NULL;
        while ($row = mysql_fetch_object($result)) {
            // Get the information about the index column.
            $index_column = (object)array(
                'sub_part' => $row->Sub_part,
                'seq' => $row->Seq_in_index,
                'type' => $row->Index_type,
                'collation' => $row->Collation,
                'comment' => $row->Comment,
            );

            if ($row->Key_name != $prev_key_name) {
                // Add a new index to the list.
                $indexes[$row->Key_name] = (object)array(
                    'key_name' => $row->Key_name,
                    'table' => $row->Table,
                    'non_unique' => $row->Non_unique,
                    'columns' => array($row->Column_name => $index_column)
                );
                $prev_key_name = $row->Key_name;
            } else {
                // Add a new column to an existing index.
                $indexes[$row->Key_name]->columns[$row->Column_name] = $index_column;
            }
        }

        return $indexes;
    }

    /**
     * Gets create table sql
     *
     * @param $name
     * @param $db
     * @return bool
     */
    public function get_create_table_sql($name, $db)
    {
        if (!($result = mysql_query("show create table `$name`", $db->link))) {
            return FALSE;
        }

        $row = mysql_fetch_row($result);
        return $row[1];
    }

    /**
     * Compares tables
     *
     * @param $db1
     * @param $tables1
     * @param $tables2
     */
    public function create_tables($db1, $tables1, $tables2)
    {
        $sql = '';
        $table_names = array_diff(array_keys($tables1), array_keys($tables2));
        foreach ($table_names as $t) {
            $sql .= $this->get_create_table_sql($t, $db1) . ";\n\n";
        }

        $this->write($sql);
    }

    /**
     * Formats default values
     *
     * @param $value
     * @param $db
     * @return string
     */
    public function format_default_value($value, $db)
    {
        if (strcasecmp($value, 'CURRENT_TIMESTAMP') == 0) {
            return $value;
        } elseif (is_string($value)) {
            return "'" . mysql_real_escape_string($value, $db->link) . "'";
        } else {
            return $value;
        }
    }

    /**
     * Generates drop table sql
     *
     * @param $tables1
     * @param $tables2
     */
    public function drop_tables($tables1, $tables2)
    {
        $sql = '';
        $table_names = array_diff(array_keys($tables2), array_keys($tables1));
        foreach ($table_names as $t) {
            $sql .= "DROP TABLE `$t`;\n";
        }

        if (strlen($sql) > 0) {
            $sql .= "\n";
        }

        $this->write($sql);
    }

    /**
     * Generates column definiation
     *
     * @param $column
     * @param $db
     * @return string
     */
    public function build_column_definition_sql($column, $db)
    {
        $result = $column->COLUMN_TYPE;

        if ($column->COLLATION_NAME) {
            $result .= " COLLATE '$column->COLLATION_NAME'";
        }

        $result .= strcasecmp($column->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';

        if (isset($column->COLUMN_DEFAULT)) {
            $result .= ' DEFAULT ' . $this->format_default_value($column->COLUMN_DEFAULT, $db);
        }

        if ($column->EXTRA) {
            $result .= " $column->EXTRA";
        }

        if ($column->COLUMN_COMMENT) {
            $result .= " COMMENT '" . mysql_real_escape_string($column->COLUMN_COMMENT, $db->link) . "'";
        }

        return $result;
    }

    /**
     * Generates add column sql
     *
     * @param $column
     * @param $after_column
     * @param $table
     * @param $db
     */
    public function alter_table_add_column($column, $after_column, $table, $db)
    {
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column->COLUMN_NAME` " .
            $this->build_column_definition_sql($column, $db) .
            ($after_column ? " AFTER `$after_column`" : ' FIRST') .
            ";\n";

        $this->write($sql);
    }

    /**
     * Generated modify column sql
     *
     * @param $column1
     * @param $column2
     * @param $after_column
     * @param $table
     * @param $db
     */
    public function alter_table_modify_column($column1, $column2, $after_column, $table, $db)
    {
        $modify = array();

        if ($column1->COLUMN_TYPE != $column2->COLUMN_TYPE) {
            $modify['type'] = " $column1->COLUMN_TYPE";
        }

        if ($column1->COLLATION_NAME != $column2->COLLATION_NAME) {
            $modify['collation'] = " COLLATE $column1->COLLATION_NAME";
        }

        if ($column1->IS_NULLABLE != $column2->IS_NULLABLE) {
            $modify['null'] = strcasecmp($column1->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';
        }

        if ($column1->COLUMN_DEFAULT != $column2->COLUMN_DEFAULT) {
            // FALSE is an special value that indicates we should DROP this column's default value,
            // causing MySQL to assign it the "default default".
            $modify['default'] = isset($column1->COLUMN_DEFAULT) ? ' DEFAULT ' . $this->format_default_value($column1->COLUMN_DEFAULT, $db) : FALSE;
        }

        if ($column1->EXTRA != $column2->EXTRA) {
            $modify['extra'] = " $column1->EXTRA";
        }

        if ($column1->COLUMN_COMMENT != $column2->COLUMN_COMMENT) {
            $modify['comment'] = " COMMENT '$column1->COLUMN_COMMENT'";
        }

        if ($column1->ORDINAL_POSITION != $column2->ORDINAL_POSITION) {
            $modify['position'] = $after_column ? " AFTER `$after_column`" : ' FIRST';
        }

        if ($modify) {
            $sql = "ALTER TABLE `$table` MODIFY `$column1->COLUMN_NAME`";

            $sql .= isset($modify['type']) ? $modify['type'] : " $column2->COLUMN_TYPE";

            if (isset($modify['collation'])) {
                $sql .= $modify['collation'];
            }

            if (isset($modify['null'])) {
                $sql .= $modify['null'];
            } else {
                $sql .= strcasecmp($column2->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';
            }

            if (isset($modify['default']) && $modify['default'] !== FALSE) {
                $sql .= $modify['default'];
            } elseif (isset($column2->COLUMN_DEFAULT)) {
                $sql .= ' DEFAULT ' . $this->format_default_value($column2->COLUMN_DEFAULT, $db);
            }

            if (isset($modify['extra'])) {
                $sql .= $modify['extra'];
            } elseif ($column2->EXTRA != '') {
                $sql .= " $column2->EXTRA";

            } if (isset($modify['comment'])) {
                $sql .= $modify['comment'];
            } elseif ($column2->COLUMN_COMMENT != '') {
                $sql .= " COMMENT '$column2->COLUMN_COMMENT'";
            }

            if (isset($modify['position'])) {
                $sql .= $modify['position'];
            }

            if (strlen($sql) > 0) {
                $sql .= ";\n";
            }

            $this->write($sql);
        }
    }

    /**
     * Generates drop column sql
     *
     * @param $columns1
     * @param $columns2
     * @param $table
     */
    public function alter_table_drop_columns($columns1, $columns2, $table)
    {
        $sql = '';
        $columns = array_diff_key($columns2, $columns1);
        foreach ($columns as $c) {
            $sql .= "ALTER TABLE `$table` DROP COLUMN `$c->COLUMN_NAME`;\n";
        }

        $this->write($sql);
    }

    /**
     * Global alter table function
     *
     * @param $db1
     * @param $db2
     */
    public function alter_tables_columns($db1, $db2)
    {
        global $options;

        $tables1 = $this->list_tables($db1);
        $tables2 = $this->list_tables($db2);

        $tables = array_intersect(array_keys($tables1), array_keys($tables2));
        foreach ($tables as $t) {
            $columns1 = $this->list_columns($t, $db1);
            $columns2 = $this->list_columns($t, $db2);
            $columns_index = array_keys($columns1);

            foreach ($columns1 as $c1) {
                $after_column = $c1->ORDINAL_POSITION == 1 ? NULL : $columns_index[$c1->ORDINAL_POSITION - 2];

                if (!isset($columns2[$c1->COLUMN_NAME])) {
                    $this->alter_table_add_column($c1, $after_column, $t, $db2);
                } else {
                    $this->alter_table_modify_column($c1, $columns2[$c1->COLUMN_NAME], $after_column, $t, $db2);
                }
            }

            if ($options->drop_columns) {
                $this->alter_table_drop_columns($columns1, $columns2, $t);
            }
        }
    }

    /**
     * @param $tables1
     * @param $tables2
     */
    public function alter_tables($tables1, $tables2)
    {
        $sql = '';
        $table_names = array_intersect(array_keys($tables2), array_keys($tables1));
        foreach ($table_names as $t) {
            $t1 = $tables1[$t];
            $t2 = $tables2[$t];

            if ($t1->ENGINE != $t2->ENGINE) {
                $sql .= "ALTER TABLE `$t` ENGINE=$t1->ENGINE;\n";
            }

            if ($t1->TABLE_COLLATION != $t2->TABLE_COLLATION) {
                $sql .= "ALTER TABLE `$t` COLLATE=$t1->TABLE_COLLATION;\n";
            }

            if ($t1->ROW_FORMAT != $t2->ROW_FORMAT) {
                $sql .= "ALTER TABLE `$t` ROW_FORMAT=$t1->ROW_FORMAT;\n";
            }

            if ($t1->CHECKSUM != $t2->CHECKSUM) {
                $sql .= "ALTER TABLE `$t` CHECKSUM=$t1->CHECKSUM;\n";
            }

            /*if ($t1->TABLE_COMMENT != $t2->TABLE_COMMENT)
                $sql .= "ALTER TABLE `$t` COMMENT='$t1->TABLE_COMMENT';\n";
            */

            if (strlen($sql) > 0) {
                $sql .= "\n";
            }
        }

        $this->write($sql);
    }

    /**
     * Compares indexes
     *
     * @param $index1
     * @param $index2
     * @return bool
     */
    public function are_indexes_eq($index1, $index2)
    {
        if ($index1->non_unique != $index2->non_unique) {
            return FALSE;
        }

        if (count($index1->columns) != count($index2->columns)) {
            return FALSE;
        }

        foreach ($index1->columns as $name => $column1) {
            if (!isset($index2->columns[$name])) {
                return FALSE;
            }

            if ($column1->seq != $index2->columns[$name]->seq) {
                return FALSE;
            }

            if ($column1->sub_part != $index2->columns[$name]->sub_part) {
                return FALSE;
            }

            if ($column1->type != $index2->columns[$name]->type) {
                return FALSE;
            }

            /*if ($column1->collation != $index2->columns[$name]->collation)
                return FALSE;*/
        }

        return TRUE;
    }

    /**
     * Drop index sql
     *
     * @param $index
     * @return string
     */
    public function build_drop_index_sql($index)
    {
        return $index->key_name == 'PRIMARY' ?
            "ALTER TABLE `$index->table` DROP PRIMARY KEY;" :
            "ALTER TABLE `$index->table` DROP INDEX $index->key_name;";
    }

    /**
     * Create index sql
     *
     * @param $index
     * @return string
     */
    public function build_create_index_sql($index)
    {
        $column_list = array();
        foreach ($index->columns as $name => $column) {
            $column_list[] = $name . ($column->sub_part ? "($column->sub_part)" : '');
        }
        $column_list = '(' . implode(',', $column_list) . ')';

        if ($index->key_name == 'PRIMARY') {
            $result = "ALTER TABLE `$index->table` ADD PRIMARY KEY $column_list;";
        } else {
            if ($index->type == 'FULLTEXT') {
                $index_type = ' FULLTEXT';
            } elseif (!$index->non_unique) {
                $index_type = ' UNIQUE';
            } else {
                $index_type = '';
            }

            $result = "CREATE$index_type INDEX $index->key_name ON `$index->table` $column_list;";
        }

        return $result;
    }

    /**
     * Add indexes function
     *
     * @param $idx1
     * @param $idx2
     */
    public function alter_table_add_indexes($idx1, $idx2)
    {
        $indexes = array_diff_key($idx1, $idx2);
        $sql = '';
        foreach ($indexes as $index_name => $index) {
            $sql .= $this->build_create_index_sql($index) . "\n";
        }

        $this->write($sql);
    }

    /**
     * Drop indexes
     *
     * @param $idx1
     * @param $idx2
     */
    public function alter_table_drop_indexes($idx1, $idx2)
    {
        $indexes = array_diff_key($idx2, $idx1);
        $sql = '';
        foreach ($indexes as $index_name => $index) {
            $sql .= $this->build_drop_index_sql($index) . "\n";
        }

        $this->write($sql);
    }

    /**
     * Alter indexes
     *
     * @param $idx1
     * @param $idx2
     */
    public function alter_table_alter_indexes($idx1, $idx2)
    {
        $sql = '';
        $indexes = array_intersect_key($idx1, $idx2);
        foreach ($indexes as $index_name => $index) {
            if (!$this->are_indexes_eq($index, $idx2[$index_name])) {
                $sql .= $this->build_drop_index_sql($idx2[$index_name]) . "\n";
                $sql .= $this->build_create_index_sql($index) . "\n";
            }
        }

        $this->write($sql);
    }

    /**
     * Compares databases
     *
     * @param $db1
     * @param $db2
     */
    public function process_database($db1, $db2)
    {
        $sql = '';

        if (!$db2->schema) {
            $sql .= "USE `$db2->database`;\n";
        }

        if ($db1->charset != $db2->charset) {
            $sql .= "ALTER DATABASE `$db2->database` CHARACTER SET=$db1->charset;\n";
        }

        if ($db1->collation != $db2->collation) {
            $sql .= "ALTER DATABASE `$db2->database` COLLATE=$db1->collation;\n";
        }

        if (strlen($sql) > 0) {
            $sql .= "\n";
        }

        $this->write($sql);
    }

    /**
     * processes indexes
     *
     * @param $tables1
     * @param $tables2
     * @param $db1
     * @param $db2
     */
    public function process_indexes($tables1, $tables2, $db1, $db2)
    {
        $tables = array_intersect_key($tables1, $tables2);
        foreach (array_keys($tables) as $t) {
            $idx1 = $this->list_indexes($t, $db1);
            $idx2 = $this->list_indexes($t, $db2);

            $this->alter_table_drop_indexes($idx1, $idx2);
            $this->alter_table_add_indexes($idx1, $idx2);
            $this->alter_table_alter_indexes($idx1, $idx2);
        }
    }

    /**
     * processes tables
     *
     * @param $db1
     * @param $db2
     */
    public function process_tables($db1, $db2)
    {
        global $options;

        $tables1 = $this->list_tables($db1);
        $tables2 = $this->list_tables($db2);

        $this->create_tables($db1, $tables1, $tables2);

        if ($options->drop_tables) {
            $this->drop_tables($tables1, $tables2);
        }

        $this->alter_tables($tables1, $tables2);
        $this->alter_tables_columns($db1, $db2);

        $this->process_indexes($tables1, $tables2, $db1, $db2);
    }

    /**
     * Outputs commands
     */
    public function usage()
    {
        echo <<<MSG

        THIS IS AN ALPHA VERSION. DO NOT USE ON PRODUCTION!
            
        Usage:    
          php mysqldiff.php <options>
        
        Options:
          --schema-file1 <schema-file>  Filename of the file which contain the db schema in sql
                                        Program will create a temp database and load schema
          --database1 <database-name>   Name of source db.
          --host1 <hostname>            Server hosting source db.
          --user1 <username>            Username for connectiong to source db.
          --pwd1 <pwd>                  Password for connectiong to source db.
          
          --schema-file2 <schema-file>  Filename of the file which contain the db schema in sql
                                        Program will create a temp database and load schema
          --database2 <database-name>   Name of destination db.
          --host2 <hostname>            Server hosting destination db.
          --user2 <username>            Username for connectiong to destination db.
          --pwd2 <pwd>                  Password for connectiong to destination db.
          
          --drop-tables                 Whether to generate DROP TABLE statements
                                        for tables present in destination but not 
                                        on source database.
                                        Note this can happen when you simply rename
                                        a table. Default is NOT TO DROP.
        
          --drop-columns                Whether to generate ALTER TABLE...DROP COLUMN 
                                        statements for columns present in destination 
                                        but not on source database. 
                                        Note this can happen when you simply rename
                                        a column. Default is NOT TO DROP.
                                        
          --output-file <filename>      Filename to save the generated MySQL script.
                                        Default is to write to SDTOUT.
                                        
          --overwrite                   Overwrite the output file without asking for 
                                        confirmation. Default is to ask.
        
        If source and destination databases share some connection data,
        you can specify them using:
        
          --database <database-name>    Name of both dbs.
          --host <hostname>             Server hosting both dbs.
          --user <username>             Username for connectiong to both dbs.
          --pwd <pwd>                   Password for connectiong to both dbs.
          
        The default hostname is "localhost".
        Both passwords are empty by default.

MSG;

        exit(0);
    }

    /**
     * writes to output file
     *
     * @param $sql
     */
    public function write($sql)
    {
        global $options;

        // only write when we have sql
        if (strlen($sql) > 0) {
            fputs($options->ofh, $sql);
        }
    }

    public function error($msg)
    {
        fputs(STDERR, "mysqldiff: $msg\n");
        exit(1);
    }

    public function success($msg)
    {
        fputs(STDOUT, "mysqldiff: $msg\n");
        exit;
    }

    public function prompt($msg)
    {
        echo $msg;
        return trim(fgets(STDIN));
    }
} 