<?php

function cpm_action_restore_backup() {
  global $cpm_config;

  $config_dirname = dirname($cpm_config->config_filepath);
  if (is_numeric($_POST['backup-file-time'])) {
    if (file_exists($config_dirname . '/comicpress-config.php.' . $_POST['backup-file-time'])) {
      if ($cpm_config->can_write_config) {
        if (@copy($config_dirname . '/comicpress-config.php.' . $_POST['backup-file-time'],
                  $config_dirname . '/comicpress-config.php') !== false) {

          cpm_read_information_and_check_config();

          $cpm_config->messages[] = sprintf(__("<strong>Restored %s</strong>.  Check to make sure your site is functioning correctly.", 'comicpress-manager'), 'comicpress-config.php.' . $_POST['backup-file-time']);
        } else {
          $cpm_config->warnings[] = sprintf(__("<strong>Could not restore %s</strong>.  Check the permissions of your theme folder and try again.", 'comicpress-manager'), 'comicpress-config.php.' . $_POST['backup-file-time']);
        }
      }
    }
  }
}

?>