<?php
define('WORK_DIR', substr($_SERVER['DOCUMENT_ROOT'], 0, -4));

if (!getenv('APP_NAME')) {
  $handle = fopen(WORK_DIR.'.env', "r");
  if($handle) {
    while (($line = fgets($handle)) !== false) {
      if (strlen($line) > 0 && sizeof(explode('=', $line, 2)) > 1) {
        putenv(trim($line));
      }
    }

    fclose($handle);
  }
}

$DB_host = getenv('DB_HOST');
$DB_user = getenv('DB_USER');
$DB_pass = getenv('DB_PASS');
$DB_dbname = getenv('DB_NAME');

$APPNAME = getenv('APP_NAME');
$SENDGRID = [
  'from' => getenv('SENDGRID_FROM'),
  'registration_template' => getenv('SENDGRID_REGISTRATION_TEMPLATE'),
  'cs_email' => getenv('SENDGRID_CS_EMAIL'),
  'cs_phone' => getenv('SENDGRID_CS_PHONE'),
  'api_key' => getenv('SENDGRID_APIKEY')
];
  
