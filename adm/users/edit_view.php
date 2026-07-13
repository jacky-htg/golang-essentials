<html>
  <head>
    <link rel="stylesheet" href="../../style.css"/>
  </head>
  <body>
    <?php require_once('../../../templates/header.php');?>
    <?php require_once('../../../templates/menu.php');?>
    <section>
      <a href="./">Kembali ke halaman list mahasiswa</a>
      <h2>Edit Mahasiswa</h2>
      <form action="./edit.php" method="POST" class="form">
      <div>
        <label>Email</label>
        <input name="email" required value="<?php echo $data['email'];?>" disabled/>
      </div>  
      <div>
        <label>Name</label>
        <input name="name" required value="<?php echo $data['name'];?>"/>
        <input name="id" type="hidden" value="<?php echo $data['id'];?>"/>
        <input type="hidden" name="token" value="<?php echo $_SESSION['edituser'];?>"/>
      </div>  
      <div>
        <label>Is Actived</label>
        <select name="is_actived">
          <option value="0" <?php echo $data['is_actived']?'':'selected';?>>NO</option>
          <option value="1" <?php echo $data['is_actived']?'selected':'';?>>YES</option>
        </select>
      </div>  
      <div><button type="submit">Submit</button></div>
      </form>
    </section>
    <?php require_once('../../../templates/footer.php');?>
  </body>
</html>