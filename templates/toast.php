      <?php if (isset($_GET['message']) || isset($_GET['error'])) : ?>
        <div id="alert" class="alert <?php echo isset($_GET['error']) ? 'alert-red' : 'alert-green';?>">
          <span class="closebtn" onclick="this.parentElement.style.display='none';">&times;</span>
          <?php echo isset($_GET['error']) ? $_GET['error'] : $_GET['message'];?>
          <script>
            setTimeout(function(){ 
              document.getElementById("alert").style.display="none";
            }, 3000);
          </script>
        </div>
      <?php endif; ?>