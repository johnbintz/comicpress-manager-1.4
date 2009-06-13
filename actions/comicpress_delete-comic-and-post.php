<?php

function cpm_action_delete_comic_and_post() {
  global $cpm_config;

  $comic_file = pathinfo($_POST['comic'], PATHINFO_BASENAME);

  if (file_exists($cpm_config->path . '/' . $comic_file)) {
    if (($result = cpm_breakdown_comic_filename($comic_file)) !== false) {
      extract($result, EXTR_PREFIX_ALL, 'filename');

      $all_possible_posts = array();
      foreach (cpm_query_posts() as $comic_post) {
        if (date(CPM_DATE_FORMAT, strtotime($comic_post->post_date)) == $filename_date) {
          $all_possible_posts[] = $comic_post->ID;
        }
      }

      if (count($all_possible_posts) > 1) {
        $cpm_config->messages[] = sprintf(
          __('There are multiple posts (%1$s) with the date %2$s in the comic categories. Please manually delete the posts.', 'comicpress-manager'),
          implode(", ", $all_possible_posts),
          $filename_date
        );

      } else {
        $delete_targets = array($cpm_config->path . '/' . $comic_file);
        foreach ($cpm_config->thumbs_folder_writable as $type => $value) {
          $delete_targets[] = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties[$type . "_comic_folder"] . '/' . $comic_file;
        }
        foreach ($delete_targets as $target) { @unlink($target); }

        if (count($all_possible_posts) == 0) {
          $cpm_config->messages[] = sprintf(__("<strong>%s deleted.</strong>  No matching posts found.  Any associated thumbnails were also deleted.", 'comicpress-manager'), $comic_file);
        } else {
          wp_delete_post($all_possible_posts[0]);
          $cpm_config->messages[] = sprintf(__('<strong>%1$s and post %2$s deleted.</strong>  Any associated thumbnails were also deleted.', 'comicpress-manager'), $comic_file, $all_possible_posts[0]);
        }
        $cpm_config->comic_files = cpm_read_comics_folder();
      }
    }
  }
}

?>