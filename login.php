<?php
session_start();

if (isset($_SESSION['userid']) && $_SESSION['userid']) {
  header('Location: ../index.php?message=You already login');
  exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($_POST['token'] != $_SESSION['login']) {
      unset($_SESSION['login']);
      header("Location: {$_SERVER['PHP_SELF']}?error=Invalid Token"); 
      exit();
    } 

    require_once('../helpers/config.php');
    require_once('../helpers/connection.php');

    $query = 'SELECT * FROM users WHERE username = ?';
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $_POST['username']);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $stmt->close();
    $data = $result -> fetch_assoc();
    if (!$data || !password_verify($_POST['password'], $data['password'])) {
      $_GET['error'] = 'Invalid username or password';
    } else if (!$data['is_verified_email']) {
      $_GET['error'] = 'Please verify your email';
    } else if (!$data['is_actived']) {
      $_GET['error'] = 'Your account inactive';
    } else {
      $result -> free_result();
      $db -> close();

      $_SESSION['userid'] = $data['id'];
      $_SESSION['role'] = $data['role'];

      unset($_SESSION['login']);
      if ($data['role'] == 'A') {
        header('Location: ../adm/index.php');
      } else {
        header('Location: ../index.php');
      }
      exit();
    }
    unset($_SESSION['login']);
  } catch(Exception $e) {
    unset($_SESSION['login']);
    header("Location: {$_SERVER['PHP_SELF']}?error=Gagal login"); 
    exit();
  }
} 
  
$datetime = new DateTime();
$_SESSION['login'] = $datetime->getTimestamp();

require_once('../login_view.php');