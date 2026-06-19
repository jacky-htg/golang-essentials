<html>
<head>
    <link rel="stylesheet" href="../../style.css"/>
  </head>
  
  <body>
    <?php require_once('../../../templates/header.php');?>
    <?php require_once('../../../templates/menu.php');?>
    
    <section>
      <a href="./">Kembali ke halaman list users</a>
      <h2>Tambah User Admin Baru</h2>
      <form action="./add.php" method="POST" class="form">
      <div>
        <label>Email</label>
        <input name="email" type="email" required value="<?php echo isset($_POST['email'])?$_POST['email']:'';?>"/>
      </div>  
      <div>
        <label>Name</label>
        <input name="name" pattern="[a-zA-Z ]+" required value="<?php echo isset($_POST['name'])?$_POST['name']:'';?>"/>
        <input type="hidden" name="token" value="<?php echo $_SESSION['adduser'];?>"/>
      </div>  
      <div><button type="submit">Submit</button></div>
      </form>
    </section>
    <?php require_once('../../../templates/footer.php');?>
  </body>
</html>