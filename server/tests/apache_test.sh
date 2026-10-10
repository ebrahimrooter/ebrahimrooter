#!/bin/sh
# The real Apache with the project's .htaccess (as deploy-vps.sh sets it up):
# secrets, copies of config.php, databases and PHP internals must not be served,
# the entry points must, and the security headers must be there.
#   sh server/tests/apache_test.sh        (needs apache2 + libapache2-mod-php)
cd "$(dirname "$0")/.." || exit 1
command -v apache2 >/dev/null || { echo "apache2 not installed - skipped"; exit 0; }
T=$(mktemp -d); W="$T/www/bank"; mkdir -p "$W"
cp -r . "$W"; rm -f "$W/config.php" "$W"/data/*.sqlite*
printf "<?php return ['app_token' => 'apppass123', 'device_token' => 'dev123', 'timezone' => 'Asia/Tehran'];\n" > "$W/config.php"
cp "$W/config.php" "$W/config.php.bak.1760000000"      # what older voice/install.sh left behind
cp "$W/config.php" "$W/config.php~"; cp "$W/install.php" "$W/install.php.bak"
echo x > "$W/data/acc_company_9.sqlite"; echo x > "$W/.env"; echo x > "$W/data/backups.bkp"
P=$((20000 + $$ % 10000)); M=/usr/lib/apache2/modules
cat > "$T/httpd.conf" <<CONF
ServerRoot "$T"
PidFile "$T/httpd.pid"
Listen 127.0.0.1:$P
ServerName localhost
LoadModule mpm_prefork_module $M/mod_mpm_prefork.so
LoadModule authz_core_module $M/mod_authz_core.so
LoadModule authn_core_module $M/mod_authn_core.so
LoadModule mime_module $M/mod_mime.so
LoadModule dir_module $M/mod_dir.so
LoadModule headers_module $M/mod_headers.so
LoadModule setenvif_module $M/mod_setenvif.so
LoadModule php_module $M/libphp8.3.so
TypesConfig /etc/mime.types
ErrorLog "$T/error.log"
DocumentRoot "$T/www"
<FilesMatch ".+\.ph(ar|p|tml)\$">
    SetHandler application/x-httpd-php
</FilesMatch>
<Directory "$T/www">
    AllowOverride All
    Require all granted
</Directory>
CONF
apache2 -f "$T/httpd.conf" -k start || { echo "apache did not start"; cat "$T/error.log"; exit 1; }
sleep 1
U="http://127.0.0.1:$P/bank"
fails=0
code() { curl -s -o /dev/null -w '%{http_code}' "$U/$1"; }
check() { if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1 (got: $2)"; fails=$((fails+1)); fi; }
echo "closed"
for f in config.php.bak.1760000000 'config.php~' config.php config.sample.php lib.php inventory.php backup.php acc_core.php cron.php install.php.bak \
         data/acc_company_9.sqlite data/backups.bkp .env .htaccess tests/run.php; do
  check "$f" "$(code "$f")" "403"
done
check "nothing of config.php in the backup copy's answer" "$(curl -s "$U/config.php.bak.1760000000" | grep -c apppass123)" "0"
echo "open"
check "api.php" "$(curl -s "$U/api.php?r=ping" | grep -c '"ok":true')" "1"
check "acc/api.php" "$(code 'acc/api.php?p=/health')" "200"
check "app/" "$(code app/)" "200"
check "acc/" "$(code acc/)" "200"
check "start page script" "$(code start.js)" "200"
echo "headers"
H=$(curl -sI "$U/app/")
check "CSP" "$(printf '%s' "$H" | grep -ci "content-security-policy: default-src 'self'")" "1"
check "X-Frame-Options" "$(printf '%s' "$H" | grep -ci 'x-frame-options: SAMEORIGIN')" "1"
check "nosniff" "$(printf '%s' "$H" | grep -ci 'x-content-type-options: nosniff')" "1"
check "Referrer-Policy" "$(printf '%s' "$H" | grep -ci 'referrer-policy: no-referrer')" "1"
check "no directory listing" "$(curl -s "$U/data/" | grep -c 'Index of')" "0"
[ -n "$KEEP" ] && exit 0
apache2 -f "$T/httpd.conf" -k stop; sleep 1; rm -rf "$T"
[ $fails -eq 0 ] && echo "all passed" || { echo "$fails FAILED"; exit 1; }
