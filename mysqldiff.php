<?php

/**
 * mysqldiff
 *
 * First of all: THIS IS AN ALPHA VERSION. DO NOT USE ON PRODUCTION!
 *
 * Compares the schema of two MySQL databases and produces a script
 * to "alter" the second schema to match the first one.
 *
 * Copyright (c) 2010-2011, Albert Almeida (caviola@gmail.com)
 * All rights reserved.
 *
 * THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 * https://github.com/caviola/mysqldiff
 */
include 'MySQLDiff.clas.php';
$mysqldiff = new Wiq_MySQLDiff();

$options = (object)array(
    'drop_columns' => FALSE,
    'drop_tables' => FALSE,
    'new_table_data' => FALSE,
    'db1' => (object)array(
        'host' => 'localhost',
        'pwd' => NULL,
        'schema' => NULL
    ),
    'db2' => (object)array(
        'host' => 'localhost',
        'pwd' => NULL,
        'schema' => NULL
    ),
    'output_file' => NULL,
    'ofh' => STDOUT, // output file handle
);

date_default_timezone_set('Europe/Amsterdam');
$db1 = &$options->db1;
$db2 = &$options->db2;

if ($argc == 1)
    usage();

// Parse command line arguments.
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--schema-file1':
            $db1->schema = $argv[++$i];
            break;
        case '--host1':
            $db1->host = $argv[++$i];
            break;
        case '--database1':
            $db1->database = $argv[++$i];
            break;
        case '--user1':
            $db1->user = $argv[++$i];
            break;
        case '--pwd1':
            $db1->pwd = $argv[++$i];
            break;
        case '--schema-file2':
            $db2->schema = $argv[++$i];
            break;
        case '--host2':
            $db2->host = $argv[++$i];
            break;
        case '--database2':
            $db2->database = $argv[++$i];
            break;
        case '--user2':
            $db2->user = $argv[++$i];
            break;
        case '--pwd2':
            $db2->pwd = $argv[++$i];
            break;
        case '--host':
            $db1->host = $db2->host = $argv[++$i];
            break;
        case '--database':
            $db1->database = $db2->database = $argv[++$i];
            break;
        case '--user':
            $db1->user = $db2->user = $argv[++$i];
            break;
        case '--pwd':
            $db1->pwd = $db2->pwd = $argv[++$i];
            break;
        case '--drop-columns':
            $options->drop_columns = TRUE;
            break;
        case '--drop-tables':
            $options->drop_tables = TRUE;
            break;
        case '--new-table-data':
            $options->new_table_data = TRUE;
            break;
        case '--output-file':
            $options->output_file = $argv[++$i];
            break;
        case '--overwrite':
            $options->overwrite = TRUE;
            break;
        case '--help':
        case '-h':
            $mysqldiff->usage();
        default:
            $mysqldiff->error("don't know what to do with \"{$argv[$i]}\"");
    }
}

/*
$db1->database = 'diskstoragecatalog';
$db2->database = 'diskstoragecatalog2';
$db1->user = $db2->user = 'root';
$options->output_dir = 'c:/temp/perico';
$options->overwrite = TRUE;
*/

if (!$db1->database && !$db1->schema) {
    $mysqldiff->error("source database or schema file must be specified with --schema-file1, --database1 or --database");
}

if ($db1->schema) {
    if (!file_exists($db1->schema)) {
        $mysqldiff->error("schema file 1 does not exist");
    }
    $db1->database = "tmp_schema_" . uniqid();
}

if (!$db2->database && !$db2->schema) {
    $mysqldiff->error("destination database or schema file must be specified with --schema-file2, --database2 or --database");
}

if ($db2->schema) {
    if (!file_exists($db1->schema)) {
        $mysqldiff->error("schema file 2 does not exist");
    }
    $db2->database = "tmp_schema_" . uniqid();
}

if ($db1->host == $db2->host && $db1->database == $db2->database && !$db1->schema && !$db2->schema) {
    $mysqldiff->error("databases names must be different if they reside on the same host");
}

if ($options->output_file) {

    if (file_exists($options->output_file) && !$options->overwrite) {
        if ($mysqldiff->prompt("Output file $options->output_file exists. Overwrite it (y/n)? ") != 'y') {
            exit(0);
        }
    }
    $options->ofh = @fopen($options->output_file, 'w') or $mysqldiff->error("error creating output file $options->output_file");
}

$db1->link = @mysql_connect($db1->host, $db1->user, $db1->pwd, TRUE) or $mysqldiff->error(mysql_error());
$mysqldiff->create_schema_db($db1);
mysql_selectdb($db1->database, $db1->link) or $mysqldiff->error(mysql_error($db1->link));

$db2->link = @mysql_connect($db2->host, $db2->user, $db2->pwd, TRUE) or $mysqldiff->error(mysql_error());
$mysqldiff->create_schema_db($db2);
mysql_selectdb($db2->database, $db2->link) or $mysqldiff->error(mysql_error($db2->link));

$mysqldiff->load_schema_db($db1);
$mysqldiff->load_schema_db($db2);

$mysqldiff->populate_schemata_info($db1);
$mysqldiff->populate_schemata_info($db2);

$mysqldiff->process_database($db1, $db2);
$mysqldiff->process_tables($db1, $db2);

$mysqldiff->drop_schema_db($db1);
$mysqldiff->drop_schema_db($db2);

// all should have been created now
if (file_get_contents($options->output_file) != '') {
    // should be successfull
    $mysqldiff->success('wrote data');
} else {
    unlink($options->output_file);
    $mysqldiff->success('no diff');
}