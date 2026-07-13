<html>
  <head>
    <link rel="stylesheet" href="../../style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  </head>
  <body>
    <?php require_once('../../../templates/header.php');?>
    <?php require_once('../../../templates/menu.php');?>
    <section>
      <?php require_once('../../../templates/toast.php');?>

      <h2>Events</h2>
      
      <div style="display:flex">
        <a href="./add.php"><button>Tambah Event Baru</button></a> &nbsp; &nbsp;
        <div class="search">
          <input type="text" id="search" placeholder="Search.." name="search" value="<?php echo isset($where['search']) ? $where['search']: '' ;?>">
          <button type="submit" onclick="window.location.href='./index.php?search='+document.getElementById('search').value+'&is_done='+document.getElementById('is_done').value+'&has_send_certificate='+document.getElementById('has_send_certificate').value"><i class="fa fa-search"></i></button>
        </div>
        <div>
          <label>&nbsp; Is Done</label>
          <select id="is_done" name="is_done" onchange="window.location.href='./index.php?search='+document.getElementById('search').value+'&is_done='+document.getElementById('is_done').value+'&has_send_certificate='+document.getElementById('has_send_certificate').value">
            <option <?php echo !isset($where['is_done']) ? 'selected' : ($where['is_done'] == 'all' || empty($where['is_done'])  ? 'selected' : '');?> value="all">All</option>
            <option <?php echo (isset($where['is_done']) && $where['is_done'] == 1) ? 'selected' :  '';?> value="1">Yes</option>
            <option <?php echo (isset($where['is_done']) && $where['is_done'] == 0) ? 'selected' :  '';?> value="0">No</option>
          </select>
        </div>
        <div>
          <label>&nbsp; Has Send Certificate</label>
          <select id="has_send_certificate" name="has_send_certificate" onchange="window.location.href='./index.php?search='+document.getElementById('search').value+'&is_done='+document.getElementById('is_done').value+'&has_send_certificate='+document.getElementById('has_send_certificate').value">
            <option <?php echo !isset($where['has_send_certificate']) ? 'selected' : ($where['has_send_certificate'] == 'all' || empty($where['has_send_certificate'])  ? 'selected' : '');?> value="all">All</option>
            <option <?php echo (isset($where['has_send_certificate']) && $where['has_send_certificate'] == 1) ? 'selected' :  '';?> value="1">Yes</option>
            <option <?php echo (isset($where['has_send_certificate']) && $where['has_send_certificate'] == 0) ? 'selected' :  '';?> value="0">No</option>
          </select>
        </div>
      </div>
      
      <table>
        <tr>
          <th>
            <a class="header" href="<?php echo getLinkSorting('id', $sort, $where);?>">
              ID 	<?php echo $sort['field'] == 'id' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('title', $sort, $where);?>">
              TITLE 	<?php echo $sort['field'] == 'title' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('description', $sort, $where);?>">
              DESCRIPTION 	<?php echo $sort['field'] == 'description' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('date', $sort, $where);?>">
            DATE 	<?php echo $sort['field'] == 'date' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('speaker', $sort, $where);?>">
            SPEAKER 	<?php echo $sort['field'] == 'speaker' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('number_of_participant', $sort, $where);?>">
            NUMBER OF PARTICIPANT 	<?php echo $sort['field'] == 'number_of_participant' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('is_done', $sort, $where);?>">
            IS DONE 	<?php echo $sort['field'] == 'is_done' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('has_send_certificate', $sort, $where);?>">
            HAS SEND CERTIFICATE <?php echo $sort['field'] == 'has_send_certificate' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>Action</th>
        </tr>
        <?php foreach($datas as $data) : ?>
          <tr>
            <td><?php echo $data['id'];?></td>
            <td><?php echo $data['title'];?></td>
            <td><?php echo $data['description'];?></td>
            <td><?php echo $data['date'];?></td>
            <td><?php echo $data['speaker'];?></td>
            <td><?php echo $data['number_of_participant'];?></td>
            <td><?php echo $data['is_done']? 'YES' : 'NO';?></td>
            <td><?php echo $data['has_send_certificate']? 'YES' : 'NO';?></td>
            <td style="display:flex;">
              <a href="./edit.php?id=<?php echo $data['id'];?>"><button>Update</button></a>
              &nbsp; &nbsp; 
              <form action="./delete.php" method="POST">
                <input type="hidden" name="id" value="<?php echo $data['id'];?>"/>
                <input type="hidden" name="token" value="<?php echo $_SESSION['deleteevent'];?>"/>
                <button type="button" onclick="if(confirm('Yakin ingin menghapus data <?php echo $data['id'];?>?')) this.parentElement.submit()">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php require_once('../../../templates/pagination.php');?>
    </section>
    <?php require_once('../../../templates/footer.php');?>
  </body>
</html>