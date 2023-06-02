<?php
session_start();
require_once('../../../helpers/config.php');
require_once('../../../helpers/connection.php');

function randomPassword() {
  $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
  $pass = array();
  $alphaLength = strlen($alphabet) - 1;
  for ($i = 0; $i < 8; $i++) {
      $n = rand(0, $alphaLength);
      $pass[] = $alphabet[$n];
  }
  return implode($pass);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($_POST['token'] != $_SESSION['adduser']) {
      unset($_SESSION['adduser']);
      header('Location: ./index.php?error=Invalid Token'); 
      exit();
    } 

    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
      $_GET['error'] = "Invalid email format";
    } else if (!preg_match("/^[a-zA-Z-' ]*$/",$_POST['name'])) {
      $_GET['error'] = "Only letters and white space allowed";
    } else {
      $password = randomPassword();
      $hashPassword = password_hash($password, PASSWORD_BCRYPT);
      $query = "INSERT INTO users (email, username, name, password, role) VALUES(?, ?, ?, ?, 'A')";
      $stmt = $db->prepare($query);
      $stmt->bind_param('ssss', $_POST['email'], $_POST['email'], $_POST['name'], $hashPassword);
      $stmt->execute();
      $stmt->close();
      $db -> close();

      // send email to inform the password
      $postData = [
        'from' => ['email' => $SENDGRID['from']],
        'subject' => 'Selamat Datang di Sistem Event HIMATIKA UNSIA',
        'personalizations' => [[
          'to' => [['email' => $_POST['email']]],
          'dynamic_template_data' =>[
            'name' => $_POST['name'],
            'app_name' => $APPNAME,
            'username' => $_POST['email'],
            'password' => $password,
            'cs_email' => $SENDGRID['cs_email'],
            'cs_phone' => $SENDGRID['cs_phone']
          ],
        ]],
        'template_id' => $SENDGRID['registration_template']
      ];

      $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
      curl_setopt_array($ch, array(
        CURLOPT_POST => TRUE,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer '.$SENDGRID['api_key'],
            'Content-Type: application/json'
        ),
        CURLOPT_POSTFIELDS => json_encode($postData)
      ));
      $response = curl_exec($ch);
      unset($_SESSION['adduser']);
      header('Location: ./index.php?message=user admin berhasil ditambahkan');
      exit();
    }

  } catch(Exception $e) {
    unset($_SESSION['adduser']);
    header('Location: ./index.php?error=user admin gagal ditambahkan: ' . $e->getMessage()); 
    exit();
  }
} 
  
$datetime = new DateTime();
$_SESSION['adduser'] = $datetime->getTimestamp();

include('add_view.php');