<?php
session_start();

if ($_SESSION['role'] != 'A') {
  header('Location: /'); 
  exit();
}

require_once('../../../helpers/config.php');
require_once('../../../helpers/connection.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  die('method is not allowed');
}

if (!isset($_POST['id']) || empty($_POST['id'])) {
  die('please suplly valid id');
}

if ($_POST['token'] != $_SESSION['deleteevent']) {
  unset($_SESSION['deleteevent']);
  header('Location: ./index.php?error=Invalid Token'); 
  exit();
} 

try {
  $query = 'DELETE FROM events WHERE id=UUID_TO_BIN(?)';
  $stmt = $db->prepare($query);
  $stmt->bind_param('s', $_POST['id']);
  $stmt->execute();
  $stmt->close();
  $db -> close();
  unset($_SESSION['deleteevent']);
  header('Location: ./index.php?message=Event berhasil dihapus');
  exit();

} catch(Exception $e) {
  unset($_SESSION['deleteevent']);
  header('Location: index.php?error=Event gagal dihapus: ' . $e->getMessage());
  exit();
}