<?php

function cpm_action_manage_subcomic() {
  global $cpm_config;

  $target_category_id = (int)$_POST['comic'];
  extract(cpm_normalize_storyline_structure());
  $final_category_id = false;
  $first = true;

  foreach ($category_tree as $node) {
    $category = get_category(end(explode("/", $node)));
    if ($first) { $final_category_id = $category->term_id; }

    if ($target_category_id == $category->term_id) {
      if (is_dir(CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties['comic_folder'] . '/' . $category->slug)) {
        $final_category_id = $category->term_id; break;
      }
    }
    $first = false;
  }

  update_option('comicpress-manager-manage-subcomic', $final_category_id);
  cpm_read_information_and_check_config();

  $cpm_config->messages[] = sprintf(__("Now managing <strong>%s</strong>.", 'comicpress-manager'), get_cat_name($final_category_id));
}

?>