<?php
// Liveness check only -- this app has no local database to round-trip
// against (see this plan's Architecture note), so unlike every other
// VPS site's healthz.php (which does a real `SELECT 1`), this one just
// confirms php-fpm is serving requests at all. The nginx healthcheck
// (sites/mensioxml/nginx/conf.d/site.conf) hits this over the real
// fastcgi path, so it still proves the nginx<->phpfpm hop works.
header('Content-Type: text/plain');
echo 'OK';
