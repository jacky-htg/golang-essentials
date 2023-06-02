<?php
session_start();

if ($_SESSION['role'] != 'A') {
  header('Location: /'); 
  exit();
}

require_once('../../../helpers/config.php');
require_once('../../../helpers/connection.php');
require_once('../../../helpers/utils.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($_POST['token'] != $_SESSION['editevent']) {
      unset($_SESSION['editevent']);
      header('Location: ./index.php?error=Invalid Token'); 
      exit();
    } 

    $datetime = $_POST['date'].' '.$_POST['time'];
    if (strlen($_POST['time']) == 5) {
      $datetime = $_POST['date'].' '.$_POST['time'].':00';
    }
    
    if (empty($_POST['title'])) {
      $_GET['error'] = "Title can not be empty";
    } else if (empty($_POST['date'])) {
      $_GET['error'] = "Date can not be empty";
    } else if (empty($_POST['speaker'])) {
      $_GET['error'] = "Speaker can not be empty";
    } else if (empty($_POST['number_of_participant'])) {
      $_GET['error'] = "Number of Participant can not be empty";
    } else if (!preg_match("/^[-:a-zA-Z-' ]*$/",$_POST['title'])) {
      $_GET['error'] = "Title only letters and white space allowed";
    } else if (!validateDate($datetime, 'Y-m-d H:i:s')) {
      $_GET['error'] = "Please supply valid date";
    } else if (!preg_match("/^[a-zA-Z-' ]*$/",$_POST['speaker'])) {
      $_GET['error'] = "Speaker only letters and white space allowed";
    } else if (!is_numeric($_POST['number_of_participant'])) {
      $_GET['error'] = "Number of participant must be numeric";
    } else {
      $query = 'UPDATE events SET title=?, description=?, date=?, speaker=?, number_of_participant=?, updated_by=?, updated_at=NOW() WHERE id=UUID_TO_BIN(?)';
      $stmt = $db->prepare($query);
      $stmt->bind_param("ssssdds", $_POST['title'], $_POST['description'], $datetime, $_POST['speaker'], $_POST['number_of_participant'], $_SESSION['userid'], $_POST['id']);
      $stmt->execute();
      $stmt->close();
      $db -> close();
      unset($_SESSION['editevent']);
      header('Location: ./index.php?message=Event berhasil diupdate');
      exit();
    }

  } catch(Exception $e) {
    unset($_SESSION['editevent']);
    header('Location: ./index.php?error=Event gagal diupdate:' . $e->getMessage());
    exit();
  }
} else {
  try {
    $query = 'SELECT BIN_TO_UUID(id) as id, title, date, description, speaker, number_of_participant FROM events WHERE id = UUID_TO_BIN(?)';
    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $_GET['id']);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $stmt->close();
    $data = $result -> fetch_assoc();
    if (!$data) {
      unset($_SESSION['editevent']);
      header('Location: ./index.php?error=Invalid ID event');
      exit();
    }
    $result -> free_result();
    $db -> close();

    if (!empty($data['date'])) {
      $dates = explode(' ', $data['date']);
      $data['date'] = $dates[0];
      $data['time'] = $dates[1];
    }

  } catch(Exception $e) {
    unset($_SESSION['editevent']);
    header('Location: ./index.php?error=Invalid ID Event'); 
    exit();
  }
}

$datetime = new DateTime();
$_SESSION['editevent'] = $datetime->getTimestamp();

include('edit_view.php');