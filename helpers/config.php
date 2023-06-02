<?php

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
  
