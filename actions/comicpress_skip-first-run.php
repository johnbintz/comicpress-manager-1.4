<?php

function cpm_action_skip_first_run() {
  global $cpm_config;

  $cpm_config->messages[] = __("<strong>No directories were created.</strong> You'll need to create directories on your own.", 'comicpress-manager');

  $cpm_config->did_first_run = true;

  cpm_read_information_and_check_config();
}

?>