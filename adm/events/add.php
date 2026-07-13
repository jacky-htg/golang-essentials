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
    if ($_POST['token'] != $_SESSION['addevent']) {
      unset($_SESSION['addevent']);
      header('Location: ./index.php?error=Invalid Token'); 
      exit();
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
    } else if (!validateDate($_POST['date'].' '.$_POST['time'].':00', 'Y-m-d H:i:s')) {
      $_GET['error'] = "Please supply valid date";
    } else if (!preg_match("/^[a-zA-Z-' ]*$/",$_POST['speaker'])) {
      $_GET['error'] = "Speaker only letters and white space allowed";
    } else if (!is_numeric($_POST['number_of_participant'])) {
      $_GET['error'] = "Number of participant must be numeric";
    } else {
      $certificateTemplateId = 1;
      $query = "INSERT INTO events (title, description, date, speaker, number_of_participant, certificate_template_id, created_by, updated_by) VALUES(?, ?, ?, ?, ?, ?, ?, ?)";
      $stmt = $db->prepare($query);
      $datetime = $_POST['date'].' '.$_POST['time'].':00';
      $stmt->bind_param('ssssdddd', $_POST['title'], $_POST['description'], $datetime, $_POST['speaker'], $_POST['number_of_participant'], $certificateTemplateId, $_SESSION['userid'], $_SESSION['userid']);
      $stmt->execute();
      $stmt->close();
      $db -> close();

      unset($_SESSION['addevent']);
      header('Location: ./index.php?message=Event berhasil ditambahkan');
      exit();
    }

  } catch(Exception $e) {
    unset($_SESSION['addevent']);
    header('Location: ./index.php?error=event gagal ditambahkan: ' . $e->getMessage()); 
    exit();
  }
} 
  
$datetime = new DateTime();
$_SESSION['addevent'] = $datetime->getTimestamp();

include('add_view.php');