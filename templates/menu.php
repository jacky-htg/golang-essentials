<?php
  $menu = 'home';
  if (str_contains($_SERVER['REQUEST_URI'], 'adm/users')) {
    $menu = 'users';
  } elseif (str_contains($_SERVER['REQUEST_URI'], 'adm/events')) {
    $menu = 'events';
  } 
?>
  <nav>
    <ul>
      <li><a class="<?php echo $menu == 'home' ? 'active': '';?>" href="<?php echo $menu == 'home' ? '#': '/adm';?>">Home</a></li>
      <li><a class="<?php echo $menu == 'users' ? 'active': '';?>" href="<?php echo $menu == 'users' ? '#': '/adm/users';?>">Users</a></li>
      <li><a class="<?php echo $menu == 'events' ? 'active': '';?>" href="<?php echo $menu == 'events' ? '#': '/adm/events';?>">Events</a></li>
    </ul>
  </nav>