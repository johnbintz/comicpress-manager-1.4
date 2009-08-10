<?php

function cpm_action_do_first_run() {
  global $cpm_config, $blog_id, $wpmu_version;

  $dir_list = array(
    CPM_DOCUMENT_ROOT,
    CPM_DOCUMENT_ROOT . '/comics',
    CPM_DOCUMENT_ROOT . '/comics-rss',
    CPM_DOCUMENT_ROOT . '/comics-archive'
  );
	$is_wpmu = $wpmu_version;
	if ($is_wpmu) { $dir_list = cpm_wpmu_first_run_dir_list(); }

  $any_made = false;
  $all_made = true;

  foreach ($dir_list as $dir_to_make) {
    if (!file_exists($dir_to_make)) {
      $any_made = true;
      if (@mkdir($dir_to_make)) {
        if (!$is_wpmu) {
          $cpm_config->messages[] = sprintf(__("<strong>Directory created:</strong> %s", 'comicpress-manager'), $dir_to_make);
        }
      } else {
        $all_made = false;
        if (!$is_wpmu) {
          $cpm_config->warnings[] = sprintf(__("<strong>Unable to create directory:</strong> %s", 'comicpress-manager'), $dir_to_make);
        }
      }
    }
  }

  if (!$any_made) {
    $cpm_config->messages[] = __("<strong>All the directories were already found, nothing to do!</strong>", "comicpress-manager");
  }
  if ($is_wpmu) {
    if ($all_made) {
      $cpm_config->messages[] = sprintf(__("<strong>All directories created!</strong>", 'comicpress-manager'), $dir_to_make);
      cpm_wpmu_complete_first_run();
    } else {
      $cpm_config->warnings[] = sprintf(__("<strong>Unable to create directories!</strong> Contact your administrator.", 'comicpress-manager'), $dir_to_make);
    }
    update_option("comicpress-manager-cpm-did-first-run", 1);
  }

  $cpm_config->did_first_run = true;

  cpm_read_information_and_check_config();
}

?>
