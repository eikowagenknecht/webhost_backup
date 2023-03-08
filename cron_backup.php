<?php
// replace "/www/htdocs/w1234567/your-domain.com/tools/backup/backup.php"
// with your path to the backup.php file
exec("/usr/bin/php /www/htdocs/w1234567/your-domain.com/tools/backup/backup.php 2>&1", $out, $result);
echo "Returncode (0 is good): {$result}.";
