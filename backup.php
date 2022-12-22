<?php
/*
    all-inkl.com backup script
    
    this script generates backups of whole sites (webspace and database), grouped together.
    backups older than a specific date are deleted.    
    
    copyright: eiko wagenknecht (phenx.de)
    version: 1.3 (2022-03-08)
    
    this needs at least php version 7.0. created and tested in php 7.4.
*/

// show errors. if you also want to see parse errors add "php_flag display_errors 1" to .htaccess file.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ########## START EDITING HERE ##########

// script settings
// -----------------------------------------------------------------------------
// use appropriate amount of memory and execution time. allowed values depend on
// your hoster (in this case probably all-inkl.com) and package.
$config["php"]["max_execution_time"] = 600; // seconds
$config["php"]["memory_limit"] = "256M"; // megabytes

// general backup settings
// -----------------------------------------------------------------------------
// the target directory is relative to the root of the webspace and has to be
// entered without a trailing slash (e.g. "backup" results in
// /www/htdocs/w0123456/backup). make sure to store nothing else in here because
// old files will be deleted as soon as the quota is met!
$config["backup"]["enabled"] = true; // only disable for testing purposes
$config["backup"]["cleanup"] = true; // disable to never remove any backups
$config["backup"]["target_directory"] = "backup";
$config["backup"]["quota"] = 100 * 1024 ** 3; // in bytes
$config["backup"]["compression_algorithm"] = "gz"; // "gz" = fast, "bz2" = small

// mail settings
// -----------------------------------------------------------------------------
$config["mail"]["enabled"] = true;
$config["mail"]["errors_only"] = false;
$config["mail"]["from"] = "mail@your-domain.com";
$config["mail"]["to"] = "mail@your-domain.com";
$config["mail"]["subject"] = "Backup complete";

// locale settings
// -----------------------------------------------------------------------------
$config["locale"]["timestamp_format"] = "Y-m-d_H-i-s";
$config["locale"]["date_format"] = "Y-m-d";
$config["locale"]["filename_timestamp_format"] = "Y-m-d";

// sites settings
// -----------------------------------------------------------------------------
// a site is a set of folders and databases that should be archived together.
// to declare multiple sites just copy the settings block like this:
// $sites[] = [...data of site 1...];
// $sites[] = [...data of site 2...];
// - description: a short summary of the site (e.g. "dummy.com main page")
//   used for logging purposes.
// - backup_prefix: backup files will use this as a prefix. be sure to include
//   only characters a-z, A-Z, 0-9 and underscores here to be on the safe side.
// - folders: an array of all folders that belong to the site (subfolders are
//   included automatically)
// - files: an array of all files that should be included in addition to the
//   folders above. folders are relative to the ftp root directory
// - databases: an array of all databases that belong to the site.
//   - db: database name
//   - user: username, for all-inkl.com this equals the database name
//   - pass: password for the database
$sites[] = [
    "description" => "your-domain.com wordpress",
    "backup_prefix" => "your_domain_de",
    "folders" => [
        "your-domain.de/www"
    ],
    "files" => [
        "your-domain.de/important-file.ext"
    ],
    "databases" => [
        ["db" => "d0123456", "user" => "d0123456", "pass" => "password"]
    ]
];

// ########## STOP EDITING HERE (unless you know what you're doing) ##########

ini_set("max_execution_time", $config["php"]["max_execution_time"]);
ini_set("memory_limit", $config["php"]["memory_limit"]);

header('Content-Type: text/html; charset=utf-8');
include "Archive/Tar.php";

echo_page_header();
echo_log_header();

$log_output = ""; // global variable used to send the whole output as mail later

// automatically add some often used config values
$config["webspace_root"] = preg_replace('/(\/www\/htdocs\/\w+\/).*/', '$1', realpath(__FILE__)); // e.g. /www/htdocs/w0123456/
logtext("Webspace root directory (absolute): {$config["webspace_root"]}", "debug");
$config["backup"]["target_directory_absolute"] = $config["webspace_root"] . $config["backup"]["target_directory"];
logtext("Backup directory (absolute): {$config["backup"]["target_directory_absolute"]}", "debug");

// make sure backup root folder exists
logtext("Make sure backup directory exists: {$config["backup"]["target_directory_absolute"]}", "debug");
if (!is_dir($config["backup"]["target_directory_absolute"])) {
    logtext("Directory does not exist - creating...", "debug");
    mkdir($config["backup"]["target_directory_absolute"], 0755, true);
}

// this will hold the backup results table
// - site: site description
// - type: file, folder or database
// - source: folder name or database name
// - target: compressed archive
// - source_size: uncompressed size (in bytes)
// - target_size: compressed size (in bytes)
// - message: errors or warning message.
$results["backups"] = [];
$results["start_time"] = time();
$results["errors"] = false;

// do the actual backup
if ($config["backup"]["enabled"]) {
    foreach ($sites as $site) {
        backup_site($site, $config, $results);
    }
}
if ($config["backup"]["cleanup"]) {
    cleanup_old_backups($config, $results);
}

$total_webspace_size = get_directory_size($config["webspace_root"]) - get_directory_size($config["backup"]["target_directory_absolute"]);
$total_webspace_size_display = human_filesize($total_webspace_size);
logtext("Size of all web directories (excluding backup): {$total_webspace_size_display}", "info");

$results_table = generate_results_table();
$results_summary = generate_results_summary($config, $results);

if ($config["mail"]["enabled"] && (!$config["mail"]["errors_only"] || ($config["mail"]["errors_only"] && $results["errors"]))) {
    $subject = $results["errors"] ? "[ERROR]" : "" . $config["mail"]["subject"];
    $mail_text = generate_mail_text($log_output, $results_table . $results_summary);
    send_mail($config["mail"]["from"], $config["mail"]["to"], $subject, $mail_text);
}

$duration = time() - $results["start_time"];
logtext("Total time was {$duration} seconds (maximum allowed: {$config["php"]["max_execution_time"]} seconds).");
logtext("-----Script ended-----", "info");

echo_log_footer();
echo_results($results_table, $results_summary);
echo_page_footer();

function backup_site($site, $config, &$results)
{
    logtext("---Creating backup of site {$site["description"]}---");

    if ($config["backup"]["compression_algorithm"] != "gz" && $config["backup"]["compression_algorithm"] != "bz2") {
        logtext("Unsupported compression algorithm {$config["backup"]["compression_algorithm"]}, aborting.", "error");
        return;
    }

    $date = date($config["locale"]["filename_timestamp_format"]);

    foreach ($site["folders"] as $folder) {
        $start_time = time();
        $folder_absolute = $config["webspace_root"] . $folder;
        $archive_name_prefix = "{$site["backup_prefix"]}_folder";
        $archive_absolute = "{$config["backup"]["target_directory_absolute"]}/{$archive_name_prefix}_{$date}.tar.{$config["backup"]["compression_algorithm"]}";
        $folder_size = get_directory_size($folder_absolute);
        $folder_size_display = human_filesize($folder_size);
        logtext("Creating backup of folder \"{$folder}\" ({$folder_size_display}) to archive \"{$archive_absolute}\".");
        $message = "";
        $archive_size = 0;

        if (is_dir($folder_absolute)) {
            $archive = new Archive_Tar($archive_absolute, $config["backup"]["compression_algorithm"]);
            $archive->addModify($folder_absolute, "", $config["webspace_root"]);

            $archive_size = filesize($archive_absolute);
            $archive_size_display = human_filesize($archive_size);
            logtext("Backed up, file size: {$archive_size_display}.");
        } else {
            $message = "Source folder {$folder_absolute} doesn't exist.";
            logtext($message, "error");
        }

        $duration = time() - $start_time;

        $results["backups"][] = [
            "site" => $site["description"],
            "type" => "Folder",
            "source" => str_replace($config["webspace_root"], "", $folder_absolute),
            "target" => str_replace($config["webspace_root"], "", $archive_absolute),
            "source_size" => $folder_size,
            "target_size" => $archive_size,
            "duration" => $duration,
            "message" => $message
        ];
    }
    foreach ($site["files"] as $file) {
        $start_time = time();
        $file_absolute = $config["webspace_root"] . $file;
        $archive_name_prefix = "{$site["backup_prefix"]}_file";
        $archive_absolute = "{$config["backup"]["target_directory_absolute"]}/{$archive_name_prefix}_{$date}.tar.{$config["backup"]["compression_algorithm"]}";
        $file_size = filesize($file_absolute);
        $file_size_display = human_filesize($file_size);
        logtext("Creating backup of file \"{$file}\" ({$file_size_display}) to archive \"{$archive_absolute}\".");
        $message = "";
        $archive_size = 0;

        if (is_file($file_absolute)) {
            $archive = new Archive_Tar($archive_absolute, $config["backup"]["compression_algorithm"]);
            $archive->addModify($file_absolute, "", $config["webspace_root"]);

            $archive_size = filesize($archive_absolute);
            $archive_size_display = human_filesize($archive_size);
            logtext("Backed up, file size: {$archive_size_display}.");
        } else {
            $message = "Source file {$file_absolute} doesn't exist.";
            logtext($message, "error");
        }

        $duration = time() - $start_time;

        $results["backups"][] = [
            "site" => $site["description"],
            "type" => "File",
            "source" => str_replace($config["webspace_root"], "", $file_absolute),
            "target" => str_replace($config["webspace_root"], "", $archive_absolute),
            "source_size" => $file_size,
            "target_size" => $archive_size,
            "duration" => $duration,
            "message" => $message
        ];
    }

    foreach ($site["databases"] as $database) {
        $start_time = time();
        $archive_name_prefix = "{$site["backup_prefix"]}_{$database["db"]}";
        $sql_absolute = "{$config["backup"]["target_directory_absolute"]}/{$archive_name_prefix}_{$date}.sql";
        logtext("Creating backup of database \"{$database["db"]}\" to file \"{$sql_absolute}.{$config["backup"]["compression_algorithm"]}\".");
        $source_size = 0;
        $archive_size = 0;
        $message = "";

        exec("mysqldump -u {$database["user"]} -p'{$database["pass"]}' --allow-keywords --add-drop-table --complete-insert --quote-names {$database["db"]} > {$sql_absolute}", $output, $return);
        if ($return != 0) {
            $errors = true;
            $message = "Couldn't dump database {$database["db"]}, Error {$return}.";
            logtext($message, "error");
            unlink($sql_absolute); // delete the file since it's broken
        } else {
            $source_size = filesize($sql_absolute);
            if ($config["backup"]["compression_algorithm"] == "gz") {
                exec("gzip -f $sql_absolute", $output, $return);
            } else {
                exec("bzip2 -f $sql_absolute", $output, $return);
            }

            if ($return != 0) {
                $errors = true;
                $message = "Couldn't compress dump for {$database["db"]}, Error {$return}.";
                logtext($message, "error");
            }
            $archive_size = filesize("{$sql_absolute}.{$config["backup"]["compression_algorithm"]}");
            $archive_size_display = human_filesize($archive_size);
            logtext("Backed up, file size: {$archive_size_display}.");
        }

        $duration = time() - $start_time;

        $results["backups"][] = [
            "site" => $site["description"],
            "type" => "Database",
            "source" => $database["db"],
            "target" => str_replace($config["webspace_root"], "", $sql_absolute) . ".gz",
            "source_size" => $source_size,
            "target_size" => $archive_size,
            "duration" => $duration,
            "message" => $message
        ];
    }

    logtext("---Backup of site {$site["description"]} finished---");
}

function cleanup_old_backups($config, &$results)
{
    $results["deleted_files"] = 0;

    $total_backups_size = get_directory_size($config["backup"]["target_directory_absolute"]);
    $total_backups_size_display = human_filesize($total_backups_size);
    $backup_quota_display = human_filesize($config["backup"]["quota"]);
    $backups_percentage = percent($total_backups_size / $config["backup"]["quota"]);

    if ($total_backups_size < $config["backup"]["quota"]) {
        logtext("Backups use {$total_backups_size_display} of {$backup_quota_display} ({$backups_percentage}%). No cleanup needed.");
        return 0;
    }

    logtext("Cleaning up old backups because current directory size of {$total_backups_size_display} is bigger than the allowed quota of {$backup_quota_display}.");

    $backupFiles = [];
    foreach (array_filter(glob($config["backup"]["target_directory_absolute"] . '/*.gz'), "is_file") as $file) {
        $backupFiles[$file] = filectime($file);
    }
    foreach (array_filter(glob($config["backup"]["target_directory_absolute"] . '/*.bz2'), "is_file") as $file) {
        $backupFiles[$file] = filectime($file);
    }
    asort($backupFiles); // sort by date, oldest first

    foreach ($backupFiles as $file => $timestamp) {
        $file_size = filesize($file);
        $date_display = date($config["locale"]["date_format"], $timestamp);

        logtext("Deleting file {$file} from {$date_display}.", "info");
        if (unlink($file)) {
            $results["deleted_files"]++;
            $total_backups_size -= $file_size;
            if ($total_backups_size <= $config["backup"]["quota"]) {
                break;
            }
        } else {
            logtext("Error deleting file {$file}. Aborting cleanup.", "error");
        }
    }

    $backups_percentage = percent($total_backups_size / $config["backup"]["quota"]);
    $total_backups_size_display = human_filesize($total_backups_size);
    logtext("Backups use {$total_backups_size_display} of {$backup_quota_display} after cleanup ({$backups_percentage}%).");
}

function generate_mail_text($log_text, $results_table): string
{
    $mail_text = "<html><head>";
    $mail_text .= get_css_style();
    $mail_text .= "</head><body>";
    $mail_text .= "<h1>Results</h1><p>";
    $mail_text .= $results_table;
    $mail_text .= "</p><h2>Detailled Log</h2><p>";
    $mail_text .= $log_text;
    $mail_text .= "</p>";
    $mail_text .= "</body></html>";
    return $mail_text;
}

function get_css_style(): string
{
    return "<style>
.log {font-size: 14px; font-family: \"Courier New\";}
.error {color: red; font-weight: bold;}
table {font-size: 14px;}
table td, table th {border: 1px solid #AAA; padding: 3px 5px;}
table tbody td:nth-child(even) {background: #EEE;}
table thead, table tfoot {text-align: left; background: #222; font-weight: bold; color: #FFF;}
</style>";
}

function echo_page_header()
{
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
<title>all-inkl.com Backup</title>";
    echo get_css_style();
    echo "</head><body>";
}

function echo_log_header()
{
    echo "<h1>Log (live)</h1><p class=\"log\">";
}

function echo_log_footer()
{
    echo "</p>";
}

function echo_page_footer()
{
    echo "</body></html>";
}

function echo_results(string $results_table, string $results_summary)
{
    echo "<h1>Results</h1>";
    echo $results_table;
    echo $results_summary;
}

function generate_results_table(): string
{
    global $results;
    global $config;

    $total_source_size = 0;
    $total_target_size = 0;
    $total_duration = 0;

    $results_table = "<p><table><thead><tr><th>Site</th><th>Type</th><th>Source</th><th>Target</th><th>Source Size</th><th>Target Size</th><th>Duration</th><th>Message</th></tr></thead><tbody>";
    foreach ($results["backups"] as $result) {
        $total_source_size += $result["source_size"];
        $total_target_size += $result["target_size"];
        $total_duration += $result["duration"];

        $source_size_display = human_filesize($result["source_size"]);
        $target_size_display = human_filesize($result["target_size"]);

        $results_table .= "<tr><td>{$result["site"]}</td><td>{$result["type"]}</td><td>{$result["source"]}</td><td>{$result["target"]}</td><td>{$source_size_display}</td><td>{$target_size_display}</td><td>{$result["duration"]}s</td><td class=\"error\">{$result["message"]}</td></tr>";
    }
    $total_source_size_display = human_filesize($total_source_size);
    $total_target_size_display = human_filesize($total_target_size);
    $results_table .= "</tbody><tfoot><tr><td>Total</td><td></td><td></td><td></td><td>{$total_source_size_display}</td><td>{$total_target_size_display}</td><td>{$total_duration}s</td><td></td></tr></tfoot></table></p>";
    return $results_table;
}

function generate_results_summary($config, $results): string
{
    $total_duration = time() - $results["start_time"];
    $backup_quota_display = human_filesize($config["backup"]["quota"]);
    $total_backups_size = get_directory_size($config["backup"]["target_directory_absolute"]);
    $total_backups_size_display = human_filesize($total_backups_size);
    $backups_percentage = percent($total_backups_size / $config["backup"]["quota"]);

    $result = "<p>Backup took a total of {$total_duration}s. Space used is now {$total_backups_size_display} of {$backup_quota_display} ({$backups_percentage}%).</p>";
    if ($results["deleted_files"] > 0) {
        $result .=     "<p>Some old backups have been deleted because the backup storage quota was exceeded. See log for details.</p>";
    }

    return $result;
}

function send_mail($from, $to, $subject, $text)
{
    logtext("Sending mail to {$to}.");
    mail(
        $to,
        $subject,
        $text,
        "From: {$from}\nReply-To: {$from}\nContent-Type: text/html\n"
    );
}

function logtext(string $text, string $level = "info"): void
{
    global $config;
    global $log_output;

    $class = strtolower($level);
    $date = date($config["locale"]["timestamp_format"]);
    $output = "<span class=\"{$class}\">{$date} [{$class}] {$text}</span><br>";

    $log_output .= $output;
    echo $output;
    flush();
}

function human_filesize($bytes, $decimals = 2): string
{
    $sz = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function percent($input, $decimals = 2): string
{
    return sprintf("%.{$decimals}f", $input * 100);
}

function get_directory_size($path)
{
    $bytestotal = 0;
    $path = realpath($path);
    if ($path !== false && $path != '' && file_exists($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object) {
            $bytestotal += $object->getSize();
        }
    }
    return $bytestotal;
}
