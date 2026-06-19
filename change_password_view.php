<html>
<head>
    <link rel="stylesheet" href="./style.css"/>
  </head>
  
  <body>
    <?php include_once('../templates/header.php');?>
    
    <section>
      <h2>Change Password</h2>
      <?php include_once('../templates/toast.php');?>
      <form class="form" action="<?php echo $_SERVER['PHP_SELF'];?>" method="POST">
      <div>
        <label>Current Password</label>
        <input name="password" type="password" required value="<?php echo isset($_POST['password'])? $_POST['password'] : '';?>"/>
        <input type="hidden" name="token" value="<?php echo $_SESSION['change_password'];?>"/>
      </div>  
      <div>
        <label>New Password</label>
        <input name="new_password" type="password" required value="<?php echo isset($_POST['new_password'])? $_POST['new_password'] : '';?>"/>
      </div>  
      <div>
        <label>Re New Password</label>
        <input name="re_password" type="password" required value="<?php echo isset($_POST['re_password'])? $_POST['re_password'] : '';?>"/>
      </div>  
      <div><button type="submit">Submit</button></div>
      </form>
    </section>
    <?php include_once('../templates/footer.php');?>
  </body>
</html>