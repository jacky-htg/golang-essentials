<html>
<head>
    <link rel="stylesheet" href="../../style.css"/>
  </head>
  
  <body>
    <?php require_once('../../../templates/header.php');?>
    <?php require_once('../../../templates/menu.php');?>
    
    <section>
      <?php require_once('../../../templates/toast.php');?>

      <a href="./">Kembali ke halaman list events</a>
      <h2>Tambah Event Baru</h2>
      <form action="./add.php" method="POST" class="form">
      <div>
        <label>Title</label>
        <input name="title" pattern="[-:a-zA-Z ]+" required value="<?php echo isset($_POST['title'])?$_POST['title']:'';?>"/>
      </div>  
      <div>
        <label>Date</label>
        <input name="date" type="date" required value="<?php echo isset($_POST['date'])?$_POST['date']:'';?>"/>
        <input name="time" type="time" required value="<?php echo isset($_POST['time'])?$_POST['time']:'';?>"/>
        <input type="hidden" name="token" value="<?php echo $_SESSION['addevent'];?>"/>
      </div>
      <div>
        <label>Speaker</label>
        <input name="speaker" pattern="[a-zA-Z ]+" required value="<?php echo isset($_POST['speaker'])?$_POST['speaker']:'';?>"/>
      </div>  
      <div>
        <label>Description</label>
        <textarea name="description"><?php echo isset($_POST['description'])?$_POST['description']:'';?></textarea>
      </div>  
      <div>
        <label>Number of Participant</label>
        <input name="number_of_participant" type="number" required value="<?php echo isset($_POST['number_of_participant'])?$_POST['number_of_participant']:'';?>"/>
      </div>  
        
      <div><button type="submit">Submit</button></div>
      </form>
    </section>
    <?php require_once('../../../templates/footer.php');?>
  </body>
</html>