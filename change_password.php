<?php
session_start();

if (!isset($_SESSION['userid'])) {
  header('Location: ./login.php');
  exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($_POST['token'] != $_SESSION['change_password']) {
      unset($_SESSION['change_password']);
      header("Location: {$_SERVER['PHP_SELF']}?error=Invalid Token"); 
      exit();
    } else if (strlen($_POST['new_password']) < 10){
      $_GET['error'] = 'Password minimal 10 karakter';
    } else if ($_POST['new_password'] != $_POST['re_password']){
      $_GET['error'] = 'Password baru yang diinput tidak sama';
    } else if (!preg_match('/[a-z]/', $_POST['new_password'])) {
      $_GET['error'] = 'Password baru minimal harus berisi 1 huruf kecil';
    } else if (!preg_match('/[A-Z]/', $_POST['new_password'])) {
      $_GET['error'] = 'Password baru minimal harus berisi 1 huruf besar';
    } else if (!preg_match('/\d/', $_POST['new_password'])) {
      $_GET['error'] = 'Password baru minimal harus berisi 1 angka';
    } else if (!preg_match('/[^a-zA-Z\d]/', $_POST['new_password'])) {
      $_GET['error'] = 'Password baru minimal harus berisi 1 karakter khusus';
    }

    require_once('../helpers/config.php');
    require_once('../helpers/connection.php');

    $query = 'SELECT * FROM users WHERE id = ?';
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $_SESSION['userid']);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $stmt->close();
    $data = $result -> fetch_assoc();
    if (!$data || !password_verify($_POST['password'], $data['password'])) {
      $_GET['error'] = 'Invalid current password';
    } else {
      $result -> free_result();

      // simpan password baru 
      $query = 'UPDATE users SET password=? WHERE id=?';
      $stmt = $db->prepare($query);
      $hashPassword = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
      $stmt->bind_param("ss", $hashPassword, $_SESSION['userid']);
      $stmt->execute();
      $stmt->close();
      $db -> close();

      session_destroy();
      header('Location: ./login.php?message=Password berhasil diubah. Silahkan login kembali.');
      exit();
    }
    unset($_SESSION['change_password']);
  } catch(Exception $e) {
    unset($_SESSION['change_password']);
    header("Location: {$_SERVER['PHP_SELF']}?error=Gagal ganti password"); 
    exit();
  }
} 
  
$datetime = new DateTime();
$_SESSION['change_password'] = $datetime->getTimestamp();

require_once('../change_password_view.php');