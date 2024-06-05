<html>
<head>
    <link rel="stylesheet" href="./style.css"/>
  </head>
  
  <body>
    <?php include_once('../templates/header.php');?>
    
    <section>
      <h2>Login</h2>
      <?php include_once('../templates/toast.php');?>
      <form class="form" action="./login.php" method="POST">
      <div>
        <label>Username</label>
        <input name="username" required value="<?php echo isset($_POST['username'])? $_POST['username'] : '';?>"/>
        <input type="hidden" name="token" value="<?php echo $_SESSION['login'];?>"/>
      </div>  
      <div>
        <label>Password</label>
        <input name="password" type="password" required value="<?php echo isset($_POST['password'])? $_POST['password'] : '';?>"/>
      </div>  
      <div><button type="submit">Login</button></div>
      </form>
    </section>
    <?php include_once('../templates/footer.php');?>
  </body>
</html>