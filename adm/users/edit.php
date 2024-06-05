<?php
session_start();

if ($_SESSION['role'] != 'A') {
  header('Location: /'); 
  exit();
}

require_once('../../../helpers/config.php');
require_once('../../../helpers/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($_POST['token'] != $_SESSION['edituser']) {
      unset($_SESSION['edituser']);
      header('Location: ./index.php?error=Invalid Token'); 
      exit();
    } 

    $query = 'UPDATE users SET name=?, is_actived = ? WHERE id=?';
    $stmt = $db->prepare($query);
    $stmt->bind_param("sds", $_POST['name'], $_POST['is_actived'], $_POST['id']);
    $stmt->execute();
    $stmt->close();
    $db -> close();
    unset($_SESSION['edituser']);
    header('Location: ./index.php?message=User berhasil diupdate');
    exit();

  } catch(Exception $e) {
    unset($_SESSION['edituser']);
    header('Location: ./index.php?error=User gagal diupdate:' . $e->getMessage());
    exit();
  }
} else {
  try {
    $query = 'SELECT * FROM users WHERE id = ?';
    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $_GET['id']);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $stmt->close();
    $data = $result -> fetch_assoc();
    if (!$data) {
      unset($_SESSION['edituser']);
      header('Location: ./index.php?error=Invalid ID user');
      exit();
    }
    $result -> free_result();
    $db -> close();

  } catch(Exception $e) {
    unset($_SESSION['edituser']);
    header('Location: ./index.php?error=Invalid ID User'); 
    exit();
  }
}

$datetime = new DateTime();
$_SESSION['edituser'] = $datetime->getTimestamp();

include('edit_view.php');