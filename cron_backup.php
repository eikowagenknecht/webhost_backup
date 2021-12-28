<?php
exec("/usr/bin/php /www/htdocs/w01b7391/eiko-wagenknecht.de/tools/backup/backup.php 2>&1", $out, $result);
echo "Returncode (0 is good): {$result}.";
?>