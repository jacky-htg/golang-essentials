<?php

function getLinkPagination($page, $sort, $where) {
  $params = ['page' => $page];
  $params['sort_field'] = $sort['field'];
  $params['sort_order'] = $sort['order'];
  foreach ($where as $i => $v) {
    if (!empty($v)) $params[$i] = $v;
  }
  
  $url = [];
  foreach ($params as $i=>$v) {
    $url[] = $i . '=' . $v;
  }
  
  return './index.php?'.implode('&', $url);
}

function getLinkSorting($key, $sort, $where) {
  $params = ['sort_field' => $key];
  $params['sort_order'] = ($sort['field'] == $key && $sort['order'] == 'asc') ? 'desc' : 'asc';
  foreach ($where as $i => $v) {
    if (!empty($v)) $params[$i] = $v;
  }
  
  $url = [];
  foreach ($params as $i=>$v) {
    $url[] = $i . '=' . $v;
  }
  
  return './index.php?'.implode('&', $url);
}

function validateDate($date, $format = 'Y-m-d H:i:s') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) == $date;
}