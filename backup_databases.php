<?php
/**
 * Script for backing up MySQL database dumps to an Amazon S3 bucket. Will (optionally) split
 * Wordpress Multisite dumps into individual files.
 *
 * @author Jonathan Persson
 * @version 2010-11-27
 */

$timestamp = date('Y-m-d H:i');

define('UPLOAD_TO_AWS', true); // upload the database dumps to the Amazon S3 storage
define('DELETE_AFTER_UPLOAD', false); // delete the database dumps from the file system after they have been uploaded to the Amazon S3 storage
define('GZIP_DUMP_FILES', true); // compress the database dumps

define('ZF_PATH', '/usr/share/php/ZendFramework-1.10.5/library'); // path to the Zend Framework library
define('MYSQLDUMP', '/usr/bin/mysqldump'); // path to the the mysqldump binary
define('DUMPS_PATH', '/home/jonathanp/backup_tmp/' . $timestamp); // path to where the database dumps are stored

define('DB_ADAPTER', 'PDO_MYSQL');
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('WORDPRESS_MULTISITE_DB_NAME', 'wordpress');

define('AWS_ACCESS_KEY_ID', '');
define('AWS_SECRET_ACCESS_KEY', '');
define('AWS_BUCKET_NAME', '');

ini_set('include_path', ZF_PATH);

require_once 'Zend/Db.php';
require_once 'Zend/Service/Amazon/S3.php';

try {
	if (!file_exists(DUMPS_PATH)) {
		mkdir(DUMPS_PATH);
	}

	$dumps = array();

	$db = Zend_Db::factory(DB_ADAPTER, array('host' => DB_HOST, 'username' => DB_USERNAME, 'password' => DB_PASSWORD, 'dbname' => WORDPRESS_MULTISITE_DB_NAME));

	$databases = $db->fetchCol('SHOW DATABASES WHERE `Database` != "' . WORDPRESS_MULTISITE_DB_NAME . '"');

	foreach ($databases as $database) {
		$file_name = $database . '.sql' . (GZIP_DUMP_FILES ? '.gz' : '');

		printLog('Dumping database ' . $database);
		mysqldump($database, $file_name);

		$dumps[] = $file_name;
	}

	if ($wp_blogs_table = $db->fetchOne('SHOW TABLES FROM `' . WORDPRESS_MULTISITE_DB_NAME . '` LIKE "%_blogs"')) {

		$wp_table_prefix = preg_replace('/_blogs/', '', $wp_blogs_table);

		$wp_blogs = $db->fetchPairs('SELECT blog_id, domain FROM ' . $wp_blogs_table);

		foreach ($wp_blogs as $wp_blog_id => $wp_blog_domain) {
			if ($wp_blog_tables = $db->fetchCol('SHOW TABLES FROM `' . WORDPRESS_MULTISITE_DB_NAME . '` LIKE "' . $wp_table_prefix . '_' . $wp_blog_id . '%"')) {
				$file_name = 'wordpress_' . $wp_blog_domain . '.sql' . (GZIP_DUMP_FILES ? '.gz' : '');

				printLog('Dumping database tables for the wordpress site ' . $wp_blog_domain);
				mysqldump(WORDPRESS_MULTISITE_DB_NAME, $file_name, $wp_blog_tables);

				$dumps[] = $file_name;
			}
		}

		if ($wp_main_tables = $db->fetchCol('SHOW TABLES FROM `' . WORDPRESS_MULTISITE_DB_NAME . '` WHERE Tables_in_' . WORDPRESS_MULTISITE_DB_NAME . ' REGEXP "' . $wp_table_prefix . '_[a-z]"')) {
			$file_name = 'wordpress.sql' . (GZIP_DUMP_FILES ? '.gz' : '');

			printLog('Dumping database tables for the wordpress core');
			mysqldump(WORDPRESS_MULTISITE_DB_NAME, $file_name, $wp_main_tables);

			$dumps[] = $file_name;
		}
	}

	if (UPLOAD_TO_AWS) {
		$s3 = new Zend_Service_Amazon_S3(AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY);

		foreach ($dumps as $dump) {
			$file_contents = file_get_contents(DUMPS_PATH . '/' . $dump);

			printLog('Uploading ' . $dump . ' to the Amazon S3 storage');
			$upload_succesful = $s3->putObject(AWS_BUCKET_NAME . '/' . $timestamp . '/' . $dump, $file_contents);

			if (DELETE_AFTER_UPLOAD && $upload_succesful === true) {
				printLog('Deleting ' . $dump . ' from the local file system');
				unlink(DUMPS_PATH . '/' . $dump);
			}
		}

		if (DELETE_AFTER_UPLOAD) {
			rmdir(DUMPS_PATH);
		}
	}

} catch (Exception $e) {
	printLog($e->getMessage());
}

function mysqldump($db_name, $file_name, $tables = null) {
	$mysqldump = sprintf('%s "%s" -u %s --password=%s', MYSQLDUMP, $db_name, DB_USERNAME, DB_PASSWORD);

	if (!empty($tables)) {
		$mysqldump .= ' --tables ' . implode(' ', $tables);
	}

	if (GZIP_DUMP_FILES) {
		$mysqldump .= ' | gzip';
	}

	$mysqldump .= ' > "' . DUMPS_PATH . '/' . $file_name . '"';

	exec($mysqldump);
}

function printLog($msg) {
	printf("[%s] %s\n", date('Y-m-d H:i:s'), $msg);
}
