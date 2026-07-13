<?php
// protect direct access
if (str_contains($_SERVER['REQUEST_URI'], '/templates/pagination.php')) {
  header('Location: /');
  exit();
}
?>
      <div class="pagination">
        <a href="<?php echo getLinkPagination(1, $sort, $where);?>">&laquo;</a>
        <?php if ($pagination['start']-10 > 1) : ?>
          <a href="<?php echo getLinkPagination($pagination['start']-10, $sort, $where);?>"><?php echo $pagination['start']-10;?></a>
          <div>...</div>
        <?php endif; ?>

        <?php for($i = $pagination['start']; $i <= $pagination['end']; $i++) : ?>
        <a href="<?php echo getLinkPagination($i, $sort, $where);?>" class="<?php echo $i == $page ? 'active':'';?>"><?php echo $i;?></a>
        <?php endfor; ?>

        <?php if ($pagination['end']+10 < $lastPage) : ?>
          <div>...</div>
          <a href="<?php echo getLinkPagination($pagination['end']+10, $sort, $where);?>"><?php echo $pagination['end']+10;?></a>
        <?php endif; ?>

        <a href="<?php echo getLinkPagination($lastPage, $sort, $where);?>">&raquo;</a>
      </div>