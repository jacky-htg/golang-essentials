<?php
session_start();
require_once('../../../helpers/config.php');

if ($_SESSION['role'] != 'A') {
  header('Location: /'); 
  exit();
}

$datetime = new DateTime();
$_SESSION['deleteuser'] = $datetime->getTimestamp();

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

  if (isset($_GET['role']) && !empty($_GET['role']) && $_GET['role'] != 'all') {
    $where['role'] = $_GET['role'];
  }

  if (isset($_GET['is_actived']) && in_array($_GET['is_actived'], [1, 0]) && $_GET['is_actived'] != 'all') {
    $where['is_actived'] = $_GET['is_actived'];
  }

  if (isset($_GET['is_verified_email']) && in_array($_GET['is_verified_email'], [1, 0])  && $_GET['is_verified_email'] != 'all') {
    $where['is_verified_email'] = $_GET['is_verified_email'];
  }

  $condition = '';
  $conditions = [];
  if (isset($where['search'])) {
    $conditions[] = '(email like ? OR name LIKE ?)';
  } 

  if (isset($where['role'])) {
    $conditions[] = 'role = ?';
  }

  if (isset($where['is_actived'])) {
    $conditions[] = 'is_actived = ?';
  }

  if (isset($where['is_verified_email'])) {
    $conditions[] = 'is_verified_email = ?';
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
    'field' => isset($_GET['sort_field']) && in_array($_GET['sort_field'], ['id', 'email', 'username', 'name', 'is_actived', 'is_verified_email', 'role']) ? $_GET['sort_field'] : 'id',
    'order' => isset($_GET['sort_order']) && in_array(strtolower($_GET['sort_order']), ['asc', 'desc']) ? $_GET['sort_order'] : 'asc'
  ];
  
  $datas = listData($db, $condition, $where, $sort, $offset, $limit);
  $db -> close();

} catch(Exception $e) {
  echo 'Gagal mendapatkan data: '. $e->getMessage();
  exit(); 
}

function getCount($db, $condition, $where) {
  $stmt = $db->prepare("SELECT COUNT(*) jumlah FROM users $condition");
  if ($condition) {
    if (isset($where['search'])) {
      $search = "%{$where['search']}%";
    }
    $types = [];
    $params = [];
    if (isset($where['search'])) {
      $types[] = 'ss';
      $params[] = $search;
      $params[] = $search;
    }

    if (isset($where['role'])) {
      $types[] = 's';
      $params[] = $where['role'];
    }
    if (isset($where['is_actived'])) {
      $types[] = 'd';
      $params[] = $where['is_actived'];
    }
    if (isset($where['is_verified_email'])) {
      $types[] = 'd';
      $params[] = $where['is_verified_email'];
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
  $query = "SELECT * FROM users $condition";
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
      $types[] = 'ss';
      $params[] = $search;
      $params[] = $search;
    }

    if (isset($where['role'])) {
      $types[] = 's';
      $params[] = $where['role'];
    }
    if (isset($where['is_actived'])) {
      $types[] = 'd';
      $params[] = $where['is_actived'];
    }
    if (isset($where['is_verified_email'])) {
      $types[] = 'd';
      $params[] = $where['is_verified_email'];
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