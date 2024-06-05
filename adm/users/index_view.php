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

      <h2>Users</h2>
      
      <div style="display:flex">
        <a href="./add.php"><button>Tambah User Admin Baru</button></a> &nbsp; &nbsp;
        <div class="search">
          <input type="text" id="search" placeholder="Search.." name="search" value="<?php echo isset($where['search']) ? $where['search']: '' ;?>">
          <button type="submit" onclick="window.location.href='./index.php?search='+document.getElementById('search').value+'&role='+document.getElementById('role').value+'&is_actived='+document.getElementById('is_actived').value+'&is_verified_email='+document.getElementById('is_verified_email').value"><i class="fa fa-search"></i></button>
        </div>
        <div>
          <label>&nbsp; Role</label>
          <select id="role" name="role" onchange="window.location.href='./index.php?search='+document.getElementById('search').value+'&role='+document.getElementById('role').value+'&is_actived='+document.getElementById('is_actived').value+'&is_verified_email='+document.getElementById('is_verified_email').value">
            <option <?php echo !isset($where['role']) ? 'selected' : ($where['role'] == 'all' || empty($where['role'])  ? 'selected' : '');?> value="all">All</option>
            <option <?php echo (isset($where['role']) && $where['role'] == 'A') ? 'selected' :  '';?> value="A">Admin</option>
            <option <?php echo (isset($where['role']) && $where['role'] == 'P') ? 'selected' :  '';?> value="P">Participant</option>
          </select>
        </div>
        <div>
          <label>&nbsp; Is Actived</label>
          <select id="is_actived" name="is_actived" onchange="window.location.href='./index.php?search='+document.getElementById('search').value+'&role='+document.getElementById('role').value+'&is_actived='+document.getElementById('is_actived').value+'&is_verified_email='+document.getElementById('is_verified_email').value">
            <option <?php echo !isset($where['is_actived']) ? 'selected' : ($where['is_actived'] == 'all' || empty($where['is_actived'])  ? 'selected' : '');?> value="all">All</option>
            <option <?php echo (isset($where['is_actived']) && $where['is_actived'] == 1) ? 'selected' :  '';?> value="1">Yes</option>
            <option <?php echo (isset($where['is_actived']) && $where['is_actived'] == 0) ? 'selected' :  '';?> value="0">No</option>
          </select>
        </div>
        <div>
          <label>&nbsp; Is Verified Email</label>
          <select id="is_verified_email" name="is_verified_email" onchange="window.location.href='./index.php?search='+document.getElementById('search').value+'&role='+document.getElementById('role').value+'&is_actived='+document.getElementById('is_actived').value+'&is_verified_email='+document.getElementById('is_verified_email').value">
            <option <?php echo !isset($where['is_verified_email']) ? 'selected' : ($where['is_verified_email'] == 'all' || empty($where['is_verified_email'])  ? 'selected' : '');?> value="all">All</option>
            <option <?php echo (isset($where['is_verified_email']) && $where['is_verified_email'] == 1) ? 'selected' :  '';?> value="1">Yes</option>
            <option <?php echo (isset($where['is_verified_email']) && $where['is_verified_email'] == 0) ? 'selected' :  '';?> value="0">No</option>
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
            <a class="header" href="<?php echo getLinkSorting('email', $sort, $where);?>">
              EMAIL 	<?php echo $sort['field'] == 'email' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('name', $sort, $where);?>">
              NAME 	<?php echo $sort['field'] == 'name' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('role', $sort, $where);?>">
              ROLE 	<?php echo $sort['field'] == 'role' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('is_actived', $sort, $where);?>">
            IS ACTIVED 	<?php echo $sort['field'] == 'is_actived' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>
            <a class="header" href="<?php echo getLinkSorting('is_verified_email', $sort, $where);?>">
            IS VERIFIED 	<?php echo $sort['field'] == 'is_verified_email' ? ($sort['order'] == 'asc' ? '&uarr;': '&darr;') : '';?>
            </a>
          </th>
          <th>Action</th>
        </tr>
        <?php foreach($datas as $data) : ?>
          <tr>
            <td><?php echo $data['id'];?></td>
            <td><?php echo $data['email'];?></td>
            <td><?php echo $data['name'];?></td>
            <td><?php echo $data['role'] == 'A' ? 'Admin' : 'Participant';?></td>
            <td><?php echo $data['is_actived']? 'YES' : 'NO';?></td>
            <td><?php echo $data['is_verified_email']? 'YES' : 'NO';?></td>
            <td style="display:flex;">
              <a href="./edit.php?id=<?php echo $data['id'];?>"><button>Update</button></a>
              &nbsp; &nbsp; 
              <form action="./delete.php" method="POST">
                <input type="hidden" name="id" value="<?php echo $data['id'];?>"/>
                <input type="hidden" name="token" value="<?php echo $_SESSION['deleteuser'];?>"/>
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