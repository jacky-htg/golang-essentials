<?php

try {
  $db = new mysqli($DB_host,$DB_user,$DB_pass,$DB_dbname);
} catch(Exception $e) {
  echo $e->getMessage();
  exit(); 
}