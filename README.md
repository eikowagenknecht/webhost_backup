# Webhost Backup Script

This script performs the following tasks:
- Generates backups of whole sites, including webspace and databases, grouped together.
- Compresses the backups using either "gz" (fast) or "bz2" (small) compression algorithms.
- Deletes backups older than a specific date to meet a defined quota.
- Optionally uploads the backups to a remote FTP server, with support for SSL and unsecure fallback.
- Sends a notification email upon completion, with detailed logs and results.
- Customizable settings for memory usage, execution time, backup directories, FTP and mail configurations, etc.

Copyright:
- Author: Eiko Wagenknecht (eiko-wagenknecht.de)

Requirements:
- PHP version 7.0 or higher (created and tested in PHP 7.4).
- Appropriate permissions for reading, writing, and executing the script.
- FTP and mail configurations if using the corresponding features.

Note:
- This script is tested only with all-inkl.com, but should work for other hosters as well.
- Shows errors by default. Add "php_flag display_errors 1" to the .htaccess file if you want to see parse errors as well.
- Ensure that the target directory is relative to the root of the webspace and does not contain any other files, as old files will be deleted to meet the quota.
- Customize the settings under the "START EDITING HERE" section according to your needs.
    
For a detailled walkthrough on how to use this see https://eikowagenknecht.de/posts/all-in-one-backup-script-for-all-inkl/

# License
No proper licensing chosen yet. So far: Mainly you can do with this whatever you want. This explicitly includes
- Using the script to backup your private or commercial site.
- Modifying the script and sharing the modified version. In this case the original author (me) must be named and a link to either this github repository or the blog entry included in the source (e.g. in the backup.php file).

Commercial redistribution is not allowed. If you want to make money using this, contact me first and we can talk about the details.

# Disclaimer
I take no responsibility in any way for damage done by using this script. Please act responsibly and make sure that your backups work the way you expect them to.
