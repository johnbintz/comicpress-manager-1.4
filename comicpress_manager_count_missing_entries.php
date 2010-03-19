<?php
  global $blog_id;

  if (!function_exists('add_action')) {
    require_once("../../../wp-config.php");
  }

  if (WP_ADMIN) {
    require_once('comicpress_manager_config.php');
    require_once('comicpress_manager_library.php');

    cpm_get_cpm_document_root();

    $cpm_config = new ComicPressConfig();

    cpm_read_information_and_check_config();

    if (isset($_REQUEST['blog_id']) && function_exists('switch_to_blog')) {
      switch_to_blog((int)$_REQUEST['blog_id']);
    }

    // TODO: handle different comic categories differently, this is still too geared
    // toward one blog/one comic...
    $all_post_dates = array();

		$format = CPM_DATE_FORMAT;
		if (isset($_POST['format'])) { $format = $_POST['format']; }

    foreach (cpm_query_posts() as $comic_post) {
      $all_post_dates[] = date($format, strtotime($comic_post->post_date));
    }
    $all_post_dates = array_unique($all_post_dates);

    ob_start();
    $missing_comic_count = 0;
    foreach (cpm_read_comics_folder() as $comic_file) {
      $comic_file = pathinfo($comic_file, PATHINFO_BASENAME);
      if (($result = cpm_breakdown_comic_filename($comic_file, $format)) !== false) {
        if (!in_array($result['date'], $all_post_dates)) {
          if (($post_hash = generate_post_hash($result['date'], $result['converted_title'])) !== false) {
            $missing_comic_count++;
          }
        }
      }
    }

    header("X-JSON: {missing_posts: ${missing_comic_count}}");
    ob_end_flush();
  }
?>
