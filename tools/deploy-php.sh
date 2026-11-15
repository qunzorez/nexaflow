#!/bin/sh
# cPanel's default CLI PHP can differ from the version selected for the site.
for php in /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php /opt/alt/php81/usr/bin/php /opt/alt/php80/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php /opt/cpanel/ea-php80/root/usr/bin/php /usr/local/bin/php; do
    if [ -x "$php" ] && "$php" -r 'foreach (array("pdo_mysql", "curl", "mbstring") as $ext) { if (!extension_loaded($ext)) exit(1); } exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'; then
        echo "Deployment CLI PHP: $php"
        exec "$php" "$@"
    fi
done
echo 'No PHP 8.0 or newer CLI with pdo_mysql, curl and mbstring found. Enable these extensions for a compatible PHP CLI on the hosting server.' >&2
exit 1
