<?php

function cpm_action_update_config() {
  global $cpm_config;

  $cpm_config->is_cpm_managing_posts = true;

  $do_write = false;
  $use_default_file = false;

  if ($cpm_config->config_method == "comicpress-config.php") {
    $do_write = !isset($_POST['just-show-config']);
  } else {
    $use_default_file = true;
  }

  include(realpath(dirname(__FILE__)) . '/../cp_configuration_options.php');

  $original_properties = $cpm_config->properties;

  foreach ($comicpress_configuration_options as $field_info) {
    extract($field_info);

    $config_id = (isset($field_info['variable_name'])) ? $field_info['variable_name'] : $field_info['id'];

    switch ($type) {
      case "folder":
        $cpm_config->properties[$config_id] = $_POST[$_POST["folder-{$config_id}"] . "-" . $config_id];
        break;
      default:
        if (isset($_POST[$config_id])) {
          $cpm_config->properties[$config_id] = $_POST[$config_id];
        }
        break;
    }
  }

  //if (function_exists('get_site_option')) {
  //  cpm_wpmu_save_options();
  //  $cpm_config->is_wp_options = true;
  //}

  if (!$cpm_config->is_wp_options) {
    if (!$do_write) {
      $file_output = write_comicpress_config_functions_php($cpm_config->config_filepath, true, $use_default_file);
      $cpm_config->properties = $original_properties;
      if ($use_default_file) {
        $cpm_config->messages[] = __("<strong>No comicpress-config.php file was found in your theme folder.</strong> Using default configuration file.", 'comicpress-manager');
      }
      $cpm_config->messages[] = __("<strong>Your configuration:</strong>", 'comicpress-manager') . "<pre class=\"code-block\">" . htmlentities($file_output) . "</pre>";
    } else {
      if (!is_null($cpm_config->config_filepath)) {
        if (is_array($file_output = write_comicpress_config_functions_php($cpm_config->config_filepath))) {
          $cpm_config->config_method = read_current_theme_comicpress_config();
          $cpm_config->path = get_comic_folder_path();
          $cpm_config->plugin_path = PLUGINDIR . '/' . plugin_basename(__FILE__);

          cpm_read_information_and_check_config();

          $backup_file = pathinfo($file_output[0], PATHINFO_BASENAME);

          $cpm_config->messages[] = sprintf(__("<strong>Configuration updated and original config backed up to %s.</strong> Rename this file to comicpress-config.php if you are having problems.", 'comicpress-manager'), $backup_file);

        } else {
          $relative_path = substr(realpath($cpm_config->config_filepath), CPM_STRLEN_REALPATH_DOCUMENT_ROOT);
          $cpm_config->warnings[] = sprintf(__("<strong>Configuration not updated</strong>, check the permissions of %s and the theme folder.  They should be writable by the Webserver process. Alternatively, copy and paste the following code into your comicpress-config.php file:", 'comicpress-manager'), $relative_path) . "<pre class=\"code-block\">" . htmlentities($file_output) . "</pre>";

          $cpm_config->properties = $original_properties;
        }
      }
    }
  } else {
    cpm_read_information_and_check_config();

    $cpm_config->messages[] = sprintf(__("<strong>Configuration updated in database.</strong>", 'comicpress-manager'), $backup_file);
  }
}

?>
