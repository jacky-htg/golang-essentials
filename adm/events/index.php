<?php
session_start();

if ($_SESSION['role'] != 'A') {
  header('Location: /'); 
  exit();
}

require_once('../../../helpers/config.php');

$datetime = new DateTime();
$_SESSION['deleteevent'] = $datetime->getTimestamp();

require_once('../../../helpers/connection.php');
require_once('../../../helpers/utils.php');

try {
  $page = isset($_GET['page']) && $_GET['page'] > 0 ? $_GET['page'] : 1;
  $limit = 10;
  $offset = ($page-1) * $limit;

  $where = [];
  if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where['search'] = $_GET['search'];
  }

  if (isset($_GET['is_done']) && in_array($_GET['is_done'], [1, 0]) && $_GET['is_done'] != 'all') {
    $where['is_done'] = $_GET['is_done'];
  }

  if (isset($_GET['has_send_certificate']) && in_array($_GET['has_send_certificate'], [1, 0])  && $_GET['has_send_certificate'] != 'all') {
    $where['has_send_certificate'] = $_GET['has_send_certificate'];
  }

  $condition = '';
  $conditions = [];
  if (isset($where['search'])) {
    $conditions[] = '(title like ? OR description LIKE ? OR speaker like ?)';
  } 

  if (isset($where['is_done'])) {
    $conditions[] = 'is_done = ?';
  }

  if (isset($where['has_send_certificate'])) {
    $conditions[] = 'has_send_certificate = ?';
  }
  
  if ($conditions) {
    $condition = ' WHERE '. implode(' AND ', $conditions);
  }
  $dataCount = getCount($db, $condition, $where);
  $lastPage = ceil($dataCount['jumlah']/$limit);

  $pagination = [
    'start' => $page - 5 > 1 ? $page - 5 : 1, 
    'end'=> $page + 5 <= $lastPage ? $page + 5 : $lastPage
  ];

  if ($pagination['start'] + 9 > $pagination['end']) {
    $pagination['end'] = $pagination['start'] + 9 <= $lastPage ? $pagination['start'] + 9 : $lastPage;
  }

  if ($pagination['end'] - 9 < $pagination['start']) {
    $pagination['start'] = $pagination['end'] - 9 >= 1 ? $pagination['end'] - 9 : 1;
  }

  $sort = [
    'field' => isset($_GET['sort_field']) && in_array($_GET['sort_field'], ['id', 'title', 'description', 'date', 'speaker', 'is_done', 'has_send_certificate']) ? $_GET['sort_field'] : 'id',
    'order' => isset($_GET['sort_order']) && in_array(strtolower($_GET['sort_order']), ['asc', 'desc']) ? $_GET['sort_order'] : 'asc'
  ];
  
  $datas = listData($db, $condition, $where, $sort, $offset, $limit);
  $db -> close();

} catch(Exception $e) {
  echo 'Gagal mendapatkan data: '. $e->getMessage();
  exit(); 
}

function getCount($db, $condition, $where) {
  $stmt = $db->prepare("SELECT COUNT(*) jumlah FROM events $condition");
  if ($condition) {
    if (isset($where['search'])) {
      $search = "%{$where['search']}%";
    }
    $types = [];
    $params = [];
    if (isset($where['search'])) {
      $types[] = 'sss';
      $params[] = $search;
      $params[] = $search;
      $params[] = $search;
    }

    if (isset($where['is_done'])) {
      $types[] = 'd';
      $params[] = $where['is_done'];
    }
    if (isset($where['has_send_certificate'])) {
      $types[] = 'd';
      $params[] = $where['has_send_certificate'];
    }
    $stmt->bind_param(implode('',$types), ...$params);
  }
  $stmt->execute();
    
  $count = $stmt->get_result();
  $stmt->close();
  $dataCount = $count->fetch_assoc();
  $count->free_result();
  return $dataCount;
}
function listData($db, $condition, $where, $sort, $offset, $limit) {
  $query = "SELECT BIN_TO_UUID(id) as id, title, description, date, speaker, number_of_participant, is_done, has_send_certificate FROM events $condition";
  $query .= " ORDER BY {$sort['field']} {$sort['order']}";
  $query .= " LIMIT $offset, $limit";
  $stmt = $db->prepare($query); 
  if ($condition) {
    if (isset($where['search'])) {
      $search = "%{$where['search']}%";
    }
    $types = [];
    $params = [];
    if (isset($where['search'])) {
      $types[] = 'sss';
      $params[] = $search;
      $params[] = $search;
      $params[] = $search;
    }

    if (isset($where['is_done'])) {
      $types[] = 'd';
      $params[] = $where['is_done'];
    }
    if (isset($where['has_send_certificate'])) {
      $types[] = 'd';
      $params[] = $where['has_send_certificate'];
    }
    $stmt->bind_param(implode('',$types), ...$params);
  }
  $stmt->execute();
    
  $result = $stmt->get_result();
  $stmt->close();
  $datas = [];

  while ($row = $result -> fetch_assoc()) {
    $datas[] = $row;
  }
  $result -> free_result();
  return $datas;
}

include('index_view.php');