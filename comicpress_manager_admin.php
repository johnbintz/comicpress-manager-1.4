<?php

//harmonious @maxdb @hash @zip:rename

require_once('comicpress_manager_library.php');

add_action("admin_menu", "cpm_add_pages");
add_action("add_category_form_pre", "cpm_comicpress_categories_warning");
add_action("pre_post_update", "cpm_handle_pre_post_update");
add_action("save_post", "cpm_handle_edit_post");
add_action("delete_post", "cpm_handle_delete_post");
add_filter("manage_posts_columns", "cpm_manage_posts_columns");
add_action("manage_posts_custom_column", "cpm_manage_posts_custom_column");
add_action("create_category", "cpm_rebuild_storyline_structure");
add_action("delete_category", "cpm_rebuild_storyline_structure");
add_action("edit_category", "cpm_rebuild_storyline_structure");

$cpm_config = new ComicPressConfig();

include('cp_configuration_options.php');

$default_comicpress_config_file_lines = array('<?' . 'php');
foreach ($comicpress_configuration_options as $option_info) {
  $default_comicpress_config_file_lines[] = "//{$option_info['name']} - {$option_info['description']} (default \"{$option_info['default']}\")";
  $default_comicpress_config_file_lines[] = "\${$option_info['id']} = \"{$option_info['default']}\";";
  $default_comicpress_config_file_lines[] = "";
}
$default_comicpress_config_file_lines[] = "?>";

$default_comicpress_config_file = implode("\n", $default_comicpress_config_file_lines);

cpm_get_cpm_document_root();
cpm_initialize_options();

/**
 * Show a warning at the top of Manage -> Categories if not enough categories
 * are defined.
 */
function cpm_comicpress_categories_warning() {
  if (count(get_all_category_ids()) < 2) {
    echo '<div style="margin: 10px; padding: 5px; background-color: #440008; color: white; border: solid #a00 1px">';
    echo __("Remember, you need at least two categories defined in order to use ComicPress.", 'comicpress-manager');
    echo '</div>';
  }
}

/**
 * Get the path to the plugin folder.
 */
function cpm_get_plugin_path() {
  return PLUGINDIR . '/' . preg_replace('#^.*/([^\/]*)#', '\\1', dirname(plugin_basename(__FILE__)));
}

/**
 * Add pages to the admin interface and load necessary JavaScript libraries.
 * Also read in the configuration and handle any POST actions.
 */
function cpm_add_pages() {
  global $plugin_page, $access_level, $pagenow, $cpm_config, $wp_version, $wpmu_version;

  load_plugin_textdomain('comicpress-manager', cpm_get_plugin_path());

  $widget_options = get_option('dashboard_widget_options');
  if ( !$widget_options || !is_array($widget_options) )
    $widget_options = array();

  cpm_read_information_and_check_config();

  $do_enqueue_prototype = false;

  if (($pagenow == "post.php") && ($_REQUEST['action'] == "edit")) {
    $do_enqueue_prototype = true;
  }

  if (strpos($pagenow, "post") === 0) {
    add_meta_box(
      'comic-for-this-post',
      __('Comic For This Post', 'comicpress-manager'),
      'cpm_show_comic_caller',
      'post',
      'normal',
      'low'
    );

    require_once("pages/edit_post_show_comic.php");
  }

  $filename = plugin_basename(__FILE__);

  if (strpos($plugin_page, $filename) !== false) {
    $editor_load_pages = array($filename, $filename . '-import');

    if (in_array($plugin_page, $editor_load_pages)) {
      wp_enqueue_script('editor');
      if (!function_exists('wp_tiny_mce')) {
        wp_enqueue_script('wp_tiny_mce');
      }
    }

    $do_enqueue_prototype = true;

    cpm_handle_actions();
  }

  if (in_array($pagenow, array("edit.php", "post-new.php"))) {
    $do_enqueue_prototype = true;
  }

  if ($do_enqueue_prototype) {
    wp_enqueue_script('prototype');
    wp_enqueue_script('scriptaculous-effects');
    wp_enqueue_script('scriptaculous-builder');
  }

  if (!isset($access_level)) { $access_level = 10; }

  $plugin_title = __("ComicPress Manager", 'comicpress-manager');

  add_menu_page($plugin_title, __("ComicPress", 'comicpress-manager'), $access_level, $filename, "cpm_manager_index_caller", get_option('siteurl') . '/' . cpm_get_plugin_path() . '/comicpress-icon.png');
  add_submenu_page($filename, $plugin_title, __("Upload", 'comicpress-manager'), $access_level, $filename, 'cpm_manager_index_caller');

  //if (!$wpmu_version) {
    add_submenu_page($filename, $plugin_title, __("Import", 'comicpress-manager'), $access_level, $filename . '-import', 'cpm_manager_import_caller');
  //}

  add_submenu_page($filename, $plugin_title, __("Bulk Edit", 'comicpress-manager'), $access_level, $filename . '-status', 'cpm_manager_status_caller');

  add_submenu_page($filename, $plugin_title, __("Storyline Structure", 'comicpress-manager'), $access_level, $filename . '-storyline', 'cpm_manager_storyline_caller');

  add_submenu_page($filename, $plugin_title, __("Change Dates", 'comicpress-manager'), $access_level, $filename . '-dates', 'cpm_manager_dates_caller');
  add_submenu_page($filename, $plugin_title, __("ComicPress Config", 'comicpress-manager'), $access_level, $filename . '-config', 'cpm_manager_config_caller');
  add_submenu_page($filename, $plugin_title, __("Manager Config", 'comicpress-manager'), $access_level, $filename . '-cpm-config', 'cpm_manager_cpm_config_caller');

  if ($pagenow == "index.php") {
    if (cpm_option('cpm-enable-dashboard-rss-feed') == 1) {
      wp_register_sidebar_widget( 'dashboard_cpm', __("ComicPress News", "comicpress-manager"), 'cpm_dashboard_widget',
        array( 'all_link' => "http://mindfaucet.com/comicpress/", 'feed_link' => "http://feeds.feedburner.com/comicpress?format=xml", 'width' => 'half', 'class' => 'widget_rss' )
      );

      add_filter('wp_dashboard_widgets', 'cpm_add_dashboard_widget');
    }

    if (($option = generate_comic_categories_options('category')) !== false) {
      if (cpm_option('cpm-enable-quomicpress') == 1) {
        if (count($cpm_config->errors) == 0) {
          if (current_user_can('edit_post')) {
            wp_register_sidebar_widget( 'dashboard_quomicpress', __("QuomicPress (Quick ComicPress)", "comicpress-manager"), 'cpm_quomicpress_widget',
              array( 'width' => 'half' )
            );

            add_filter('wp_dashboard_widgets', 'cpm_add_quomicpress_widget');
          }
        }
      }
    }
  }
}

/**
 * Handle updating a post.  If Edit Post Integration is enabled, and the post
 * date is changing, rename the file as necessary.
 */
function cpm_handle_pre_post_update($post_id) {
  global $cpm_config;

  if (!$cpm_config->is_cpm_managing_posts) {
    if (cpm_option("cpm-edit-post-integrate") == 1) {
      if ($post_id > 0) {
        $post = get_post($post_id);
        if (!in_array($post->post_type, array("attachment", "revision", "page"))) {
          $ok = false;
          extract(cpm_get_all_comic_categories());
          $post_categories = wp_get_post_categories($post_id);
          foreach ($category_tree as $node) {
            $parts = explode("/", $node);
            if (in_array(end($parts), $post_categories)) {
              $ok = true; break;
            }
          }

          if ($ok) {
            $original_timestamp = false;
            foreach (array("post_date", "post_date_gmt") as $param) {
              $result = strtotime(date("Y-m-d", strtotime($post->{$param})));
              if ($result !== false) {
                $original_timestamp = $result; break;
              }
            }

            $new_timestamp = strtotime(implode("-", array($_POST['aa'], $_POST['mm'], $_POST['jj'])));

            $todays_date = date("Y-m-d", $original_timestamp);
            $any_posts_today = false;
            foreach (cpm_query_posts() as $comic_post) {
              if ($comic_post->ID != $post_id) {
                if (strpos($comic_post->post_date, $todays_date) === 0) {
                  $any_posts_today = true; break;
                }
              }
            }

            if (!$any_posts_today) {
              if (!empty($original_timestamp) && !empty($new_timestamp)) {
                $original_date = date(CPM_DATE_FORMAT, $original_timestamp);
                $new_date = date(CPM_DATE_FORMAT, $new_timestamp);

                if ($original_date !== $new_date) {
                  if (empty($cpm_config->comic_files)) {
                    cpm_read_information_and_check_config();
                  }

                  foreach ($cpm_config->comic_files as $file) {
                    $filename = pathinfo($file, PATHINFO_BASENAME);
                    if (($result = cpm_breakdown_comic_filename($filename)) !== false) {
                      if ($result['date'] == $original_date) {
                        foreach (cpm_find_thumbnails_by_filename($file) as $thumb_file) {
                          @rename($thumb_file, str_replace("/${original_date}", "/${new_date}", $thumb_file));
                        }

                        @rename($file, str_replace("/${original_date}", "/${new_date}", $file));
                      }
                    }
                  }

                  $cpm_config->comic_files = null;
                }
              }
            }
          }
        }
      }
    }
  }
}

/**
 * Handle editing a post.
 */
function cpm_handle_edit_post($post_id) {
  global $cpm_config;

  if (!$cpm_config->is_cpm_managing_posts) {
    $ok = false;
    extract(cpm_get_all_comic_categories());
    $post_categories = wp_get_post_categories($post_id);
    foreach ($category_tree as $node) {
      $parts = explode("/", $node);
      if (in_array(end($parts), $post_categories)) {
        $ok = true; break;
      }
    }

    if (is_uploaded_file($_FILES['comicpress-replace-image']['tmp_name']) && !$ok) {
      $post_categories = wp_get_post_categories($post_id);
      $post_categories[] = end(explode("/", reset($category_tree)));
      wp_set_post_categories($post_id, $post_categories);
      $ok = true;
    }

    if ($ok) {
      $new_date = date(CPM_DATE_FORMAT, strtotime(implode("-", array($_POST['aa'], $_POST['mm'], $_POST['jj']))));

      foreach (array('hovertext' => 'comicpress-img-title',
                     'transcript' => 'comicpress-transcript') as $meta_name => $post_name) {
        if (isset($_POST[$post_name])) {
          update_post_meta($post_id, $meta_name, $_POST[$post_name]);
        }
      }

      if (is_uploaded_file($_FILES['comicpress-replace-image']['tmp_name'])) {
        $_POST['override-date'] = $new_date;
        cpm_handle_file_uploads(array('comicpress-replace-image'));
      }
    }
  }
}

/**
 * Handle deleting a post. If Edit Post Integration is enabled, delete any associated
 * files from the comics folders.
 */
function cpm_handle_delete_post($post_id) {
  global $cpm_config;

  if (!$cpm_config->is_cpm_managing_posts) {
    if (cpm_option("cpm-edit-post-integrate") == 1) {
      $post = get_post($post_id);
      if (!in_array($post->post_type, array("attachment", "revision", "page"))) {

        $ok = false;
        extract(cpm_get_all_comic_categories());
        $post_categories = wp_get_post_categories($post_id);
        foreach ($category_tree as $node) {
          $parts = explode("/", $node);
          if (in_array(end($parts), $post_categories)) {
            $ok = true; break;
          }
        }

        if ($ok) {
          $original_date = date(CPM_DATE_FORMAT, strtotime($post->post_date));

          if (empty($cpm_config->comic_files)) {
            cpm_read_information_and_check_config();
          }

          foreach ($cpm_config->comic_files as $file) {
            $filename = pathinfo($file, PATHINFO_BASENAME);
            if (($result = cpm_breakdown_comic_filename($filename)) !== false) {
              if ($result['date'] == $original_date) {
                foreach (cpm_find_thumbnails_by_filename($file) as $thumb_file) {
                  $thumb_file = CPM_DOCUMENT_ROOT . $thumb_file;
                  @unlink($thumb_file);
                }

                @unlink($file);
              }
            }
          }
        }
      }
    }
  }
}

/**
 * Add the ComicPress News Dashboard widget.
 */
function cpm_add_dashboard_widget($widgets) {
	global $wp_registered_widgets;
	if (!isset($wp_registered_widgets['dashboard_cpm'])) {
		return $widgets;
	}
	array_splice($widgets, sizeof($widgets)-1, 0, 'dashboard_cpm');
	return $widgets;
}

/**
 * Write out the ComicPress News Dashboard widget.
 */
function cpm_dashboard_widget($sidebar_args) {
  if (is_array($sidebar_args)) {
    extract($sidebar_args, EXTR_SKIP);
  }
  echo $before_widget . $before_title . $widget_name . $after_title;
  wp_widget_rss_output('http://feeds.feedburner.com/comicpress?format=xml', array('items' => 2, 'show_summary' => true));
	echo $after_widget;
}

/**
 * Add the QuomicPress Dashboard widget.
 */
function cpm_add_quomicpress_widget($widgets) {
  global $wp_registered_widgets;
  if (!isset($wp_registered_widgets['dashboard_quomicpress'])) {
    return $widgets;
  }
  array_splice($widgets, sizeof($widgets)-1, 0, 'dashboard_quomicpress');
  return $widgets;
}

/**
 * Write out the QuomicPress Dashboard widget.
 */
function cpm_quomicpress_widget($sidebar_args) {
  if (is_array($sidebar_args)) {
    extract($sidebar_args, EXTR_SKIP);
  }
  echo $before_widget . $before_title . $widget_name . $after_title;
  include("pages/write_comic_post.php");
  cpm_manager_write_comic(plugin_basename(__FILE__), false);
  echo $after_widget;
}

/**
 * Add the Comic column to Edit Posts.
 */
function cpm_manage_posts_columns($posts_columns) {
  wp_enqueue_script('prototype');

  $posts_columns['comic'] = "Comic";
  return $posts_columns;
}

$broken_down_comic_files = null;

/**
 * Populate the Comic coulmn in Edit Posts.
 */
function cpm_manage_posts_custom_column($column_name) {
  global $cpm_config, $broken_down_comic_files, $post;

  if ($column_name == "comic") {
    $post_date = date(CPM_DATE_FORMAT, strtotime($post->post_date));

    if ($is_first = empty($broken_down_comic_files)) {
      $broken_down_comic_files = array();
      if (empty($cpm_config->comic_files)) {
        cpm_read_information_and_check_config();
      }

      foreach ($cpm_config->comic_files as $file) {
        $filename = pathinfo($file, PATHINFO_BASENAME);
        if (($result = cpm_breakdown_comic_filename($filename)) !== false) {
          if (!isset($broken_down_comic_files[$result['date']])) {
            $broken_down_comic_files[$result['date']] = array();
          }
          $broken_down_comic_files[$result['date']][] = $file;
        }
      }
      ?>
        <script type="text/javascript">
          function set_up_opener(id) {
            Event.observe('comic-icon-' + id, 'mouseover', function() {
              Element.clonePosition('comic-hover-' + id, 'comic-icon-' + id, { setWidth: false, setHeight: false, offsetLeft: -215, offsetTop: -((Element.getDimensions('comic-hover-' + id)['height'] - Element.getDimensions('comic-icon-' + id)['height']) / 2) });
              $('comic-hover-' + id).show();
            });

            Event.observe('comic-icon-' + id, 'mouseout', function() {
              $('comic-hover-' + id).hide();
            });
          }
        </script>
      <?php
    }

    $ok = false;

    if (cpm_get_subcomic_directory() !== false) {
      if (in_category(get_option('comicpress-manager-manage-subcomic'))) {
        $ok = true;
      }
    } else {
      extract(cpm_get_all_comic_categories());
      foreach ($category_tree as $node) {
        $parts = explode("/", $node);
        if (in_category(end($parts))) {
          $ok = true; break;
        }
      }
    }

    if ($ok) {
      if (isset($broken_down_comic_files[$post_date])) {
        $index = 0;
        foreach ($broken_down_comic_files[$post_date] as $file) {
          $image_index = $post->ID . '-' . $index;

          $thumbnails_found = cpm_find_thumbnails_by_filename($file);

          $icon_file_to_use = $file;
          foreach (array('rss', 'archive', 'mini') as $type) {
            if (isset($thumbnails_found[$type])) { $icon_file_to_use = $thumbnails_found[$type]; }
          }

          $hovertext = get_post_meta($post->ID, "hovertext", true);

          ?>
            <img style="border: solid black 1px; margin-bottom: 2px" id="comic-icon-<?php echo $image_index ?>" src="<?php echo cpm_build_comic_uri($icon_file_to_use) ?>" width="100" /><br />
            <div id="comic-hover-<?php echo $image_index ?>" style="display: none; position: absolute; width: 200px; padding: 5px; border: solid black 1px; background-color: #DDDDDD; z-index: 1">
              <img width="200"  id="preview-comic-<?php echo $image_index ?>" src="<?php echo cpm_build_comic_uri($file) ?>" />

              <p><strong>File:</strong> <?php echo pathinfo($file, PATHINFO_BASENAME) ?></p>

              <?php if (count($thumbnails_found) > 0) { ?>
                <p><strong>Thumbs:</strong> <?php echo implode(", ", array_keys($thumbnails_found)) ?></p>
              <?php } ?>

              <?php if (!empty($hovertext)) { ?>
                <p><strong>Hover:</strong> <?php echo $hovertext ?></p>
              <?php } ?>
            </div>
            <script type="text/javascript">set_up_opener('<?php echo $image_index ?>')</script>
          <?php
          $index++;
        }
      } else {
        echo __("No comic files found!", 'comicpress-manager');
      }
    }
  }
}

/**
 * If a category is added, deleted, or edited, and it's not done through
 * the Storyline Structure page, normalize the Storyline Structure
 * so that it includes/removes the affected categories.
 */
function cpm_rebuild_storyline_structure($term_id) {
  global $cpm_config;

  if (empty($cpm_config->is_cpm_modifying_categories)) {
    cpm_read_information_and_check_config();
    cpm_normalize_storyline_structure();
  }
}

/**
 * Create a list of checkboxes that can be used to select additional categories.
 */
function cpm_generate_additional_categories_checkboxes($override_name = null) {
  global $cpm_config;

  $additional_categories = array();

  $invalid_ids = array($cpm_config->properties['blogcat']);

  extract(cpm_get_all_comic_categories());
  foreach ($category_tree as $node) {
    $invalid_ids[] = end(explode('/', $node));
  }

  foreach (get_all_category_ids() as $cat_id) {
    if (!in_array($cat_id, $invalid_ids)) {
      $category = get_category($cat_id);
      $additional_categories[strtolower($category->cat_name)] = $category;
    }
  }

  ksort($additional_categories);

  $name = (!empty($override_name)) ? $override_name : "additional-categories";
  $selected_additional_categories = explode(",", cpm_option("cpm-default-additional-categories"));

  $category_checkboxes = array();
  if (count($additional_categories) > 0) {
    foreach ($additional_categories as $category) {
      $checked = (in_array($category->cat_ID, $selected_additional_categories) ? "checked" : "");

      $category_checkboxes[] = "<input id=\"additional-" . $category->cat_ID . "\" type=\"checkbox\" name=\"${name}[]\" value=\"" . $category->cat_ID . "\" ${checked} /> <label for=\"additional-" . $category->cat_ID . "\">" . $category->cat_name . "</label><br />";
    }
  }
  return $category_checkboxes;
}

/**
 * Initialize ComicPress Manager options.
 */
function cpm_initialize_options() {
  global $cpm_config;

  include('cpm_configuration_options.php');

  foreach ($configuration_options as $option_info) {
    if (is_array($option_info)) {
      $result = cpm_option($option_info['id']);

      if (isset($option_info['not_blank']) && empty($result)) { $result = false; }

      if ($result === false) {
        $default = (isset($option_info['default']) ? $option_info['default'] : "");
        update_option("comicpress-manager-" . $option_info['id'], $default);
      }

    }
  }
}

/**
 * Show the Post Editor.
 * @param integer $width The width in pixels of the text editor widget.
 */
function cpm_post_editor($width = 435, $is_import = false) {
  global $cpm_config;

  $form_titles_and_fields = array();

  if (is_null(get_category($cpm_config->properties['comiccat']))) { ?>
    <p><strong>You don't have a comics category defined!</strong> Go to the
    <a href="?page=<?php echo plugin_basename(__FILE__) ?>-config">ComicPress Config</a> screen and choose a category.
    <?php return;
  }

  extract(cpm_normalize_storyline_structure());

  if (cpm_get_subcomic_directory() !== false) {
    $post_categories = array(get_option('comicpress-manager-manage-subcomic'));
  } else {
    if (cpm_option('cpm-default-comic-category-is-last-storyline') == 1) {
      $post_categories = array(end(explode("/", end($category_tree))));
    } else {
      $post_categories = array($cpm_config->properties['comiccat']);
    }
  }

  ob_start();
  cpm_display_storyline_checkboxes($category_tree, $post_categories);
  $storyline_editor = ob_get_clean();

  $form_titles_and_fields[] = array(
    __("Storyline:", 'comicpress-manager'),
    $storyline_editor
  );

  // see if there are additional categories that can be set besides the comic and blog categories

  if (count($category_checkboxes = cpm_generate_additional_categories_checkboxes()) > 0) {
    $form_titles_and_fields[] = array(
      __("Additional Categories:", 'comicpress-manager'),
      implode("\n", $category_checkboxes)
    );
  }

  $form_titles_and_fields[] = array(
    __("Time to post:", 'comicpress-manager'),
    "<input type=\"text\" name=\"time\" value=\"" . cpm_option('cpm-default-post-time') . "\" size=\"10\" />" .
    __(" <em>(must be in the format <strong>HH:MM am/pm</strong> or <strong>now</strong>)</em>", 'comicpress-manager')
  );

  $form_titles_and_fields[] = array(
    '<label for="publish">' . __("Publish post:", 'comicpress-manager') . '</label>',
    '<input id="publish" type="checkbox" name="publish" value="yes" checked />' .
    __(" <label for=\"publish\"><em>(set the status of this post to <strong>published</strong> instead of <strong>draft</strong>)</em></label>", "comicpress-manager")
  );

  if (!$is_import) {
    $form_titles_and_fields[] = array(
      '<label for="duplicate-check">' . __("Check for duplicate posts:", 'comicpress-manager') . '</label>',
      '<input id="duplicate-check" type="checkbox" name="duplicate_check" value="yes" checked />' .
      __(" <label for=\"duplicate-check\"><em>(if you've set up ComicPress to use multiple posts on the same day, you'll need to disable this option to allow ComicPress Manager to make multiple posts)</em></label>", "comicpress-manager")
    );
  } else {
    $form_titles_and_fields[] = '<input type="hidden" name="duplicate_check" value="yes" />';
  }

  if ($cpm_config->get_scale_method() != CPM_SCALE_NONE) {
    $thumbnail_writes = cpm_get_thumbnails_to_generate();

    $thumb_write_holder = ' (<em>';
    if (count($thumbnail_writes) > 0) {
      $thumb_write_holder .= sprintf("If enabled, you'll be writing thumbnails to: %s", implode(", ", $thumbnail_writes));
    } else {
      $thumb_write_holder .= __("You won't be generating any thumbnails.", 'comicpress-manager');
    }
    $thumb_write_holder .= "</em>)";

    if (count($thumbnail_writes) > 0) {
      $form_titles_and_fields[] = array(
        '<label for="thumbnails">' . __("Generate thumbnails:", 'comicpress-manager') . '</label>',
        '<input onclick="hide_show_div_on_checkbox(\'thumbnail-write-holder\', this, true)" type="checkbox" name="thumbnails" id="thumbnails" value="yes" checked />' . '<label for="thumbnails">' . $thumb_write_holder . "</label>"
      );
    }
  }

  $form_titles_and_fields[] = array(
    __("Title For All Posts:", 'comicpress-manager'),
    '<input type="text" name="override-title-to-use" value="' . cpm_option('cpm-default-override-title') . '" />' .
    __(" <em>(the title to use for all posts)</em>", 'comicpress-manager')
  );

  $form_titles_and_fields[] = array(
    __("&lt;img title&gt;/Hovertext For All Posts:", 'comicpress-manager'),
    '<input type="text" name="hovertext-to-use" value="' . cpm_option('cpm-default-hovertext') . '" />' .
    __(" <em>(the hovertext to use for all posts)</em>", 'comicpress-manager')
  );

  $form_titles_and_fields[] = array(
    __("Transcript For All Posts:", 'comicpress-manager'),
    '<textarea name="transcript-to-use" rows="5" cols="30"></textarea>' .
    __(" <em>(the transcript to use for all posts)</em>", 'comicpress-manager')
  );

  if (!is_plugin_active('what-did-they-say/what-did-they-say.php')) {
    $form_titles_and_fields[] = '<em>' . __('Want even better control over your transcripts? Try <a href="http://wordpress.org/extend/plugins/what-did-they-say/" target="wdts">What Did They Say?!?</a>', 'comicpress-manager') . '</em>';
  }

  $form_titles_and_fields[] = array(
    __("Upload Date Format:", 'comicpress-manager'),
    '<input type="text" id="upload-date-format" name="upload-date-format" />' .
    __(" <em>(if the files you are uploading have a different date format, specify it here. ex: <strong>Ymd</strong> for a file named <strong>20080101-my-new-years-day.jpg</strong>)</em>", 'comicpress-manager')
  );

  $form_titles_and_fields[] = array(
    __("Tags:", 'comicpress-manager'),
    '<input type="text" id="tags" name="tags" value="' . cpm_option('cpm-default-post-tags') .'" />' .
    __(" <em>(any tags to add to the post, separated by commas. any tags you've used before will be listed below.)</em>", 'comicpress-manager')
  );

  $all_tags_links = array();
  foreach (get_tags() as $tag) {
    $all_tags_links[] = "<a href=\"#\" class=\"tag\">{$tag->name}</a>";
  }

  sort($all_tags_links);

  if (count($all_tags_links) > 0) {
    $form_titles_and_fields[] = array(
      __("Quick Tags (click to add):", 'comicpress-manager'),
      implode("\n", $all_tags_links)
    );
  }

  ?><table class="form-table"><?php

  foreach ($form_titles_and_fields as $form_title_and_field) {
    if (is_array($form_title_and_field)) {
      list($title, $field, $id) = $form_title_and_field; ?>
      <tr<?php echo (!empty($id) ? " id=\"$id\"" : "") ?>>
        <th scope="row" valign="top"><?php echo $title ?></th>
        <td valign="top"><?php echo $field ?></td>
      </tr>
    <?php } else { ?>
      <tr><td colspan="2"><?php echo $form_title_and_field ?></td></tr>
    <?php } ?>
  <?php } ?>
  </table>

  <?php cpm_show_post_body_template($width) ?>
<?php }

/**
 * Wrap the help text and activity content in the CPM page style.
 * @param string $help_content The content to show in the Help box.
 * @param string $activity_content The content to show in the Activity box.
 */
function cpm_wrap_content($help_content, $activity_content, $show_sidebar = true) {
  global $wp_scripts;
  cpm_write_global_styles_scripts();

  ?>

<div class="wrap">
  <div id="cpm-container" <?php echo (!$show_sidebar || (cpm_option('cpm-sidebar-type') == "none")) ? "class=\"no-sidebar\"" : "" ?>>
    <?php if (cpm_handle_warnings()) { ?>
      <!-- Header -->
      <?php cpm_show_manager_header() ?>

      <div style="overflow: hidden">
        <div id="cpm-activity-column" <?php echo (!$show_sidebar || (cpm_option('cpm-sidebar-type') == "none")) ? "class=\"no-sidebar\"" : "" ?>>
          <div class="activity-box"><?php echo $activity_content ?></div>
          <![if !IE]>
            <script type="text/javascript">prepare_comicpress_manager()</script>
          <![endif]>
        </div>

        <?php if ($show_sidebar && (cpm_option('cpm-sidebar-type') !== "none")) { ?>
          <div id="cpm-sidebar-column">
            <?php
              switch(cpm_option('cpm-sidebar-type')) {
                case "latest":
                  cpm_show_latest_posts();
                  break;
                case "standard":
                default:
                  cpm_show_comicpress_details();
                  break;
              }
            ?>
            <?php if (!is_null($help_content) && (cpm_option('cpm-sidebar-type') == "standard")) { ?>
              <div id="comicpress-help">
                <h2 style="padding-right:0;"><?php _e("Help!", 'comicpress-manager') ?></h2>
                <?php echo $help_content ?>
              </div>
            <?php } ?>
          </div>
        <?php } ?>
      </div>
    <?php cpm_show_footer() ?>
  <?php } ?>
  </div>
</div>
<?php }

function cpm_manager_page_caller($page) {
  global $cpm_config, $wpmu_version;

  $do_first_run = false;
  if (!cpm_option('cpm-did-first-run')) {
    $all_comic_folders_found = true;
    foreach (array(''. 'rss_', 'archive_') as $folder_name) {
      if (!file_exists(CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties["${folder_name}comic_folder"])) { $all_comic_folders_found = false; break; }
    }

    $do_first_run = !$all_comic_folders_found;
    if (!$do_first_run) {
      update_option("comicpress-manager-cpm-did-first-run", 1);
    }
  }

  if ($do_first_run) {
    include("pages/comicpress_first_run.php");
    cpm_manager_first_run(plugin_basename(__FILE__));
    update_option("comicpress-manager-cpm-did-first-run", 1);
  } else {
    if ($cpm_config->did_first_run) { $page = "config"; }
    include("pages/comicpress_${page}.php");
    call_user_func("cpm_manager_${page}");
  }
}

/**
 * Wrappers around page calls to reduce the amount of code in _admin.php.
 */
function cpm_manager_index_caller() { cpm_manager_page_caller("index"); }
function cpm_manager_status_caller() { cpm_manager_page_caller("status"); }
function cpm_manager_dates_caller() { cpm_manager_page_caller("dates"); }
function cpm_manager_import_caller() { cpm_manager_page_caller("import"); }
function cpm_manager_config_caller() { cpm_manager_page_caller("config"); }
function cpm_manager_cpm_config_caller() { cpm_manager_page_caller("cpm_config"); }
function cpm_manager_storyline_caller() { cpm_manager_page_caller("storyline"); }

function cpm_show_comic_caller() {
  cpm_show_comic();
}

function cpm_manager_write_comic_caller() {
  include("pages/write_comic_post.php");
  cpm_manager_write_comic(plugin_basename(__FILE__));
}

/**
 * Show the header.
 */
function cpm_show_manager_header() {
  global $cpm_config; ?>
  <div id="icon-admin" class="icon32"><br /></div>
  <h2>
  <?php if (!is_null($cpm_config->comic_category_info)) { ?>
    <?php printf(__("Managing &#8216;%s&#8217;", 'comicpress-manager'), $cpm_config->comic_category_info['name']) ?>
  <?php } else { ?>
    <?php _e("Managing ComicPress", 'comicpress-manager') ?>
  <?php } ?>
 </h2>
<?php }

/**
 * Find all the thumbnails for a particular image root.
 */
function cpm_find_thumbnails($date_root) {
  global $cpm_config;

  $thumbnails_found = array();
  foreach (array('rss', 'archive', 'mini') as $type) {
    if ($cpm_config->separate_thumbs_folder_defined[$type]) {
      $path = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties[$type . "_comic_folder"];
      if (($subdir = cpm_get_subcomic_directory()) !== false) {
        $path .= '/' . $subdir;
      }
      $files = glob($path . '/' . $date_root . "*");
      if ($files === false) { $files = array(); }

      if (count($files) > 0) {
        $thumbnails_found[$type] = substr(realpath(array_shift($files)), CPM_STRLEN_REALPATH_DOCUMENT_ROOT);
      }
    }
  }

  return $thumbnails_found;
}

/**
 * Find all the thumbnails for a particular image root.
 */
function cpm_find_thumbnails_by_filename($filename) {
  global $cpm_config;

  $thumbnails_found = array();
  foreach (array('rss', 'archive', 'mini') as $type) {
    if ($cpm_config->separate_thumbs_folder_defined[$type]) {
      $thumb_filename = str_replace('/' . $cpm_config->properties["comic_folder"] . '/', '/' . $cpm_config->properties[$type . "_comic_folder"] . '/', $filename);

      if (file_exists($thumb_filename)) {
        $thumbnails_found[$type] = substr(realpath($thumb_filename), CPM_STRLEN_REALPATH_DOCUMENT_ROOT);
      }
    }
  }

  return $thumbnails_found;
}

/**
 * Find a comic file by date.
 */
function find_comic_by_date($timestamp) {
  global $cpm_config;

  $files = glob(get_comic_folder_path() . '/' . date(CPM_DATE_FORMAT, $timestamp) . '*');
  if ($files === false) { $files = array(); }
  foreach ($files as $file) {
    if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $cpm_config->allowed_extensions)) {
      return $file;
    }
  }
  return false;
}

function cpm_display_storyline_checkboxes($category_tree, $post_categories, $prefix = null, $root_name = "in-comic-category") {
  foreach ($category_tree as $node) {
    $parts = explode("/", $node);
    $category_id = end($parts);
    $name = (empty($prefix) ? "" : "${prefix}-") . $root_name;

    ?>
    <div style="margin-left: <?php echo (count($parts) - 2) * 20 ?>px; white-space: nowrap">
      <label>
        <input type="radio"
               name="<?php echo $name ?>[]"
               value="<?php echo $category_id ?>" id="<?php echo $name ?>-<?php echo $category_id ?>"
               <?php echo in_array($category_id, $post_categories) ? "checked" : "" ?> />
        <?php echo get_cat_name($category_id) ?>
      </label>
    </div>
  <?php }
}

/**
 * Generate &lt;option&gt; elements for all comic categories.
 */
function generate_comic_categories_options($form_name, $label = true) {
  global $cpm_config;

  $number_of_categories = 0;
  $first_category = null;

  extract(cpm_normalize_storyline_structure());
  if (cpm_get_subcomic_directory() !== false) {
    $default_category = get_option('comicpress-manager-manage-subcomic');
  } else {
    if (cpm_option('cpm-default-comic-category-is-last-storyline') == 1) {
      $default_category = end(explode("/", end($category_tree)));
    } else {
      $default_category = $cpm_config->properties['comiccat'];
    }
  }

  ob_start();
  foreach (get_all_category_ids() as $cat_id) {
    $ok = ($cat_id == $default_category);

    if ($ok) {
      $number_of_categories++;
      $category = get_category($cat_id);
      if (is_null($first_category)) { $first_category = $category; }
      ?>
      <option
        value="<?php echo $category->cat_ID ?>"
        <?php if (!is_null($cpm_config->comic_category_info)) {
          echo ($default_category == $cat_id) ? " selected" : "";
        } ?>
        ><?php echo $category->cat_name; ?></option>
    <?php }
  }
  $output = ob_get_clean();

  if ($number_of_categories == 0) {
    return false;
  } else {
    if ($number_of_categories == 1) {
      $output = "<input type=\"hidden\" name=\"${form_name}\" value=\"{$first_category->cat_ID}\" />";
      if ($label) { $output .= $first_category->cat_name; }
      return $output;
    } else {
      return "<select name=\"${form_name}\">" . $output . "</select>";
    }
  }
}

/**
 * Use file_put_contents or f-functions() as necessary.
 */
function file_write_contents($file, $data) {
  //harmonious file_put_contents
  if (function_exists('file_put_contents')) {
    return file_put_contents($file, $data);
  } else {
    if (($fh = fopen($file, "w")) !== false) {
      fwrite($fh, $data);
      fclose($fh);
    }
  }
  //harmonious_end
}

/**
 * Write the current ComicPress Config to disk.
 */
function write_comicpress_config_functions_php($filepath, $just_show_config = false, $use_default_file = false) {
  global $cpm_config, $default_comicpress_config_file;

  $file_lines = array();

  if ($use_default_file) {
    $file_lines = $default_comicpress_config_file;
  } else {
    foreach (file($filepath) as $line) {
      $file_lines[] = rtrim($line, "\r\n");
    }
  }

  include('cp_configuration_options.php');

  $properties_written = array();

  $closing_line = null;

  for ($i = 0; $i < count($file_lines); $i++) {
    foreach (array_keys($cpm_config->properties) as $variable) {
      if (!in_array($variable, $properties_written)) {
        if (preg_match("#\\$${variable}\ *\=\ *([^\;]*)\;#", $file_lines[$i], $matches) > 0) {
          $value = $cpm_config->properties[$variable];
          $file_lines[$i] = '$' . $variable . ' = "' . $value . '";';
          $properties_written[] = $variable;
        }
      }
    }
    if (strpos($file_lines[$i], "?>") !== false) { $closing_line = $i; }
  }

  foreach ($comicpress_configuration_options as $option_info) {
    $varname = isset($option_info['variable_name']) ? $option_info['variable_name'] : $option_info['id'];
    $value = isset($cpm_config->properties[$varname]) ? $cpm_config->properties[$varname] : $option_info['default'];

    if (!in_array($varname, $properties_written)) {
      $comicpress_lines = array();
      $comicpress_lines[] = "//{$option_info['name']} - {$option_info['description']} (default \"{$option_info['default']}\")";
      $comicpress_lines[] = "\${$varname} = \"{$value}\";";
      $comicpress_lines[] = "";
      array_splice($file_lines, $closing_line, 0, $comicpress_lines);
    }
  }

  $file_output = implode("\n", $file_lines);

  if (!$just_show_config) {
    if (can_write_comicpress_config($filepath)) {
      $target_filepath = $filepath . '.' . time();
      $temp_filepath = $target_filepath . '-tmp';
      if (@file_write_contents($temp_filepath, $file_output) !== false) {
        if (file_exists($temp_filepath)) {
          @chmod($temp_filepath, CPM_FILE_UPLOAD_CHMOD);
          if (@rename($filepath, $target_filepath)) {
            if (@rename($temp_filepath, $filepath)) {
              return array($target_filepath);
            } else {
              @unlink($temp_filepath);
              @rename($target_filepath, $filepath);
            }
          } else {
            @unlink($temp_filepath);
          }
        }
      }
    }
  }

  return $file_output;
}

/**
 * Generate links to view or edit a particular post.
 * @param array $post_info The post information to use.
 * @return string The view & edit post links for the post.
 */
function generate_view_edit_post_links($post_info) {
  $view_post_link = sprintf('<a href="%s">%s</a>', $post_info['guid'], __("View post", 'comicpress-manager'));
  $edit_post_link = sprintf('<a href="post.php?action=edit&amp;post=%s">%s</a>', $post_info['ID'], __("Edit post", 'comicpress-manager'));

  return $view_post_link . ' | ' . $edit_post_link;
}

/**
 * Write a thumbnail image to the thumbnail folders.
 * @param string $input The input image filename.
 * @param string $target_filename The filename for the thumbnails.
 * @param boolean $do_rebuild If true, force rebuilding thumbnails.
 * @return mixed True if successful, false if not, null if unable to write.
 */
function cpm_write_thumbnail($input, $target_filename, $do_rebuild = false) {
  global $cpm_config;

  $target_format = pathinfo($target_filename, PATHINFO_EXTENSION);
  $files_created_in_operation = array();

  $write_targets = array();
  foreach ($cpm_config->separate_thumbs_folder_defined as $type => $value) {
    if ($value) {
      if ($cpm_config->thumbs_folder_writable[$type]) {
        if (cpm_option("cpm-${type}-generate-thumbnails") == 1) {
          $converted_target_filename = preg_replace('#\.[^\.]+$#', '', $target_filename) . '.' . $target_format;

          $target = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties[$type . "_comic_folder"];
          if (($subdir = cpm_get_subcomic_directory()) !== false) {
            $target .= '/' . $subdir;
          }
          $target .= '/' . $converted_target_filename;

          if (!in_array($target, $write_targets)) {
            $write_targets[$type] = $target;
          }
        }
      }
    }
  }

  if (count($write_targets) > 0) {
    if (!$do_rebuild) {
      if (file_exists($input)) {
        if (file_exists($target)) {
          if (filemtime($input) > filemtime($target)) {
            $do_rebuild = true;
          }
        } else {
          $do_rebuild = true;
        }
      }
    }

    if ($do_rebuild) {
      switch ($cpm_config->get_scale_method()) {
        case CPM_SCALE_NONE:
          return null;
        case CPM_SCALE_IMAGEMAGICK:
        	$original_size = getimagesize($input);

          $unique_colors = exec("identify -format '%k' '${input}'");
          if (empty($unique_colors)) { $unique_colors = 256; }

          $ok = true;
          foreach ($write_targets as $type => $target) {
            $width_to_use =   (isset($cpm_config->properties["${type}_comic_width"]))
                            ? $cpm_config->properties["${type}_comic_width"]
                            : $cpm_config->properties['archive_comic_width'];

            if ($width_to_use < $original_size[0]) {
							$command = array("convert",
															 "\"${input}\"",
															 "-filter Lanczos",
															 "-resize " . $width_to_use . "x");

							$im_target = $target;

							switch(strtolower($target_format)) {
								case "jpg":
								case "jpeg":
									$command[] = "-quality " . cpm_option("cpm-thumbnail-quality");
									break;
								case "gif":
									$command[] = "-colors ${unique_colors}";
									break;
								case "png":
									if ($unique_colors <= 256) {
										$im_target = "png8:${im_target}";
										$command[] = "-colors ${unique_colors}";
									}
									$command[] = "-quality 100";
									break;
								default:
							}

							$command[] = "\"${im_target}\"";

							$convert_to_thumb = escapeshellcmd(implode(" ", $command));

							exec($convert_to_thumb);

							if (!file_exists($target)) {
								$ok = false;
							} else {
								@chmod($target, CPM_FILE_UPLOAD_CHMOD);
								$files_created_in_operation[] = $target;
							}
						} else {
							copy($input, $target);
						}
          }

          return ($ok) ? $files_created_in_operation :false ;
        case CPM_SCALE_GD:
          list ($width, $height) = getimagesize($input);

          if ($width > 0) {
            $pixel_size_buffer = 1.25;

            $max_bytes = cpm_short_size_string_to_bytes(ini_get('memory_limit'));
            if ($max_bytes > 0) {
              $max_thumb_size = 0;

              foreach ($write_targets as $type => $target) {
                $width_to_use =   (isset($cpm_config->properties["${type}_comic_width"]))
                                ? $cpm_config->properties["${type}_comic_width"]
                                : $cpm_config->properties['archive_comic_width'];

                $archive_comic_height = (int)(($width_to_use * $height) / $width);

                $max_thumb_size = max($width_to_use * $archive_comic_height * 4, $max_thumb_size);
              }

              $input_image_size = $width * $height * 4;
              if (strtolower(pathinfo($input, PATHINFO_EXTENSION)) == "gif") { $input_image_size *= 2; }

              $recommended_size = ($input_image_size + $max_thumb_size) * $pixel_size_buffer;
              if (function_exists('memory_get_usage')) { $recommended_size += memory_get_usage(); }

              if ($recommended_size > $max_bytes) {
                $cpm_config->warnings[] = sprintf(__("<strong>You don't have enough memory available to PHP and GD to process this file.</strong> You should <strong>set your PHP <tt>memory_size</tt></strong> to at least <strong><tt>%sM</tt></strong> and try again. For more information, read the ComicPress Manager FAQ.", 'comicpress-manager'), (int)($recommended_size / (1024 * 1024)));

                return false;
              }
            }

            foreach ($write_targets as $type => $target) {
              $width_to_use =   (isset($cpm_config->properties["${type}_comic_width"]))
                              ? $cpm_config->properties["${type}_comic_width"]
                              : $cpm_config->properties['archive_comic_width'];

							if ($width_to_use < $width) {
								$archive_comic_height = (int)(($width_to_use * $height) / $width);

								$pathinfo = pathinfo($input);

								$thumb_image = imagecreatetruecolor($width_to_use, $archive_comic_height);
								imagealphablending($thumb_image, true);
								switch(strtolower($pathinfo['extension'])) {
									case "jpg":
									case "jpeg":
										$comic_image = imagecreatefromjpeg($input);
										break;
									case "gif":
										$is_gif = true;

										$temp_comic_image = imagecreatefromgif($input);

										list($width, $height) = getimagesize($input);
										$comic_image = imagecreatetruecolor($width, $height);

										imagecopy($comic_image, $temp_comic_image, 0, 0, 0, 0, $width, $height);
										imagedestroy($temp_comic_image);
										break;
									case "png":
										$comic_image = imagecreatefrompng($input);
										break;
									default:
										return false;
								}
								imagealphablending($comic_image, true);

								if ($is_palette = !imageistruecolor($comic_image)) {
									$number_of_colors = imagecolorstotal($comic_image);
								}

								imagecopyresampled($thumb_image, $comic_image, 0, 0, 0, 0, $width_to_use, $archive_comic_height, $width, $height);

								$ok = true;

								@touch($target);
								if (file_exists($target)) {
									@unlink($target);
									switch(strtolower($target_format)) {
										case "jpg":
										case "jpeg":
											if (imagetypes() & IMG_JPG) {
												@imagejpeg($thumb_image, $target, cpm_option("cpm-thumbnail-quality"));
											} else {
												return false;
											}
											break;
										case "gif":
											if (imagetypes() & IMG_GIF) {
												if (function_exists('imagecolormatch')) {
													$temp_comic_image = imagecreate($width_to_use, $archive_comic_height);
													imagecopymerge($temp_comic_image, $thumb_image, 0, 0, 0, 0, $width_to_use, $archive_comic_height, 100);
													imagecolormatch($thumb_image, $temp_comic_image);

													@imagegif($temp_comic_image, $target);
													imagedestroy($temp_comic_image);
												} else {
													@imagegif($thumb_image, $target);
												}
											} else {
												return false;
											}
											break;
										case "png":
											if (imagetypes() & IMG_PNG) {
												if ($is_palette) {
													imagetruecolortopalette($thumb_image, true, $number_of_colors);
												}
												@imagepng($thumb_image, $target, 9);
											} else {
												return false;
											}
											break;
										default:
											return false;
									}
								}
              } else {
              	copy($input, $target);
              }

              if (!file_exists($target)) {
                $ok = false;
              } else {
                @chmod($target, CPM_FILE_UPLOAD_CHMOD);
                $files_created_in_operation[] = $target;
              }

              imagedestroy($comic_image);
              imagedestroy($thumb_image);
            }
          } else {
            $ok = false;
          }

          return ($ok) ? $files_created_in_operation :false ;
      }
    }
  }

  return null;
}

function cpm_obfuscate_filename($filename) {
  if (($result = cpm_breakdown_comic_filename($filename)) !== false) {
    $md5_key = substr(md5(rand() + strlen($filename)), 0, 8);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    switch (cpm_option('cpm-obfuscate-filenames-on-upload')) {
      case "append":
        return $result['date'] . $result['title'] . '-' . $md5_key . '.' . $extension;
      case "replace":
        return $result['date'] . '-' . $md5_key . '.' . $extension;
    }
  }
  return $filename;
}

function cpm_do_gd_file_check_on_upload($check_file_path, $target_filename) {
  global $cpm_config, $wpmu_version;

  $file_ok = true;
  $did_filecheck = false;
  $is_cmyk = false;

  $result = cpm_breakdown_comic_filename($target_filename, true);

  if (extension_loaded("gd") && (cpm_option('cpm-perform-gd-check') == 1)) {
    $file_ok = (($image_info = getimagesize($check_file_path)) !== false);
    $did_filecheck = true;

    if (($image_info[2] == IMAGETYPE_JPEG) && ($image_info['channels'] == 4)) {
      $is_cmyk = true;
      $file_ok = false;
      $temp_check_file_path = $check_file_path . md5(rand());
      switch ($cpm_config->get_scale_method()) {
        case CPM_SCALE_NONE:
          break;
        case CPM_SCALE_IMAGEMAGICK:
          exec(implode(" ", array("convert",
                            "\"{$check_file_path}\"",
                            "-colorspace rgb",
                            $temp_check_file_path)));
          break;
        case CPM_SCALE_GD:
          $cmyk_data = imagecreatefromjpeg($check_file_path);
          imagejpeg($cmyk_data, $temp_check_file_path, cpm_option("cpm-thumbnail-quality"));
          break;
      }
      if (file_exists($temp_check_file_path)) {
        rename($temp_check_file_path, $check_file_path);
        $file_ok = true;
      }
    }

    if ($file_ok) {
      $current_extension = strtolower(pathinfo($target_filename, PATHINFO_EXTENSION));
      if ($current_extension != "") {
        $remove_extension = false;
        switch($image_info[2]) {
          case IMAGETYPE_GIF:
            $remove_extension = !in_array($current_extension, array("gif"));
            break;
          case IMAGETYPE_JPEG:
            $remove_extension = !in_array($current_extension, array("jpg", "jpeg"));
            break;
          case IMAGETYPE_PNG:
            $remove_extension = !in_array($current_extension, array("png"));
            break;
        }
        if ($remove_extension) {
          $target_filename = preg_replace('#\.[^\.]+$#', '', $target_filename);
          $result = cpm_breakdown_comic_filename($target_filename, true);
        }
      }

      $gd_did_rename = false;
      if ($result === false && $file_ok) {

        if (pathinfo($target_filename, PATHINFO_EXTENSION) == "") {
          $new_extension = "";
          switch($image_info[2]) {
            case IMAGETYPE_GIF:  $new_extension = "gif"; break;
            case IMAGETYPE_JPEG: $new_extension = "jpg"; break;
            case IMAGETYPE_PNG:  $new_extension = "png"; break;
          }

          if ($new_extension != "") { $target_filename .= '.' . $new_extension; }
          $result = cpm_breakdown_comic_filename($target_filename, true);
          $gd_did_rename = true;
        }
      }
    }
  }

  return compact('file_ok', 'did_filecheck', 'gd_did_rename', 'result', 'target_filename', 'is_cmyk');
}

/**
 * Handle uploading a set of files.
 * @param array $files A list of valid $_FILES keys to process.
 */
function cpm_handle_file_uploads($files) {
  global $cpm_config;

  $posts_created = array();
  $duplicate_posts = array();
  $files_uploaded = array();
  $thumbnails_written = array();
  $invalid_filenames = array();
  $thumbnails_not_written = array();
  $files_not_uploaded = array();
  $invalid_image_types = array();
  $gd_rename_file = array();
  $did_convert_cmyk_jpeg = array();

  $target_root = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties[$_POST['upload-destination'] . "_folder"];
  if (($subdir = cpm_get_subcomic_directory()) !== false) {
    $target_root .= '/' . $subdir;
  }

  $write_thumbnails = isset($_POST['thumbnails']) && ($_POST['upload-destination'] == "comic");
  $new_post = isset($_POST['new_post']) && ($_POST['upload-destination'] == "comic");

  $ok_to_keep_uploading = true;
  $files_created_in_operation = array();

  $filename_original_titles = array();

  foreach ($files as $key) {
    if (is_uploaded_file($_FILES[$key]['tmp_name'])) {
      if ($_FILES[$key]['error'] != 0) {
        switch ($_FILES[$key]['error']) {
          case UPLOAD_ERR_INI_SIZE:
          case UPLOAD_ERR_FORM_SIZE:
            $cpm_config->warnings[] = sprintf(__("<strong>The file you uploaded was too large.</strong>  The max allowed filesize for uploads to your server is %s.", 'comicpress-manager'), ini_get('upload_max_filesize'));
            break;
          case UPLOAD_ERR_NO_FILE:
            break;
          default:
            $cpm_config->warnings[] = sprintf(__("<strong>There was an error in uploading.</strong>  The <a href='http://php.net/manual/en/features.file-upload.errors.php'>PHP upload error code</a> was %s.", 'comicpress-manager'), $_FILES[$key]['error']);
            break;
        }
      } else {
        if (strpos($_FILES[$key]['name'], ".zip") !== false) {
          $invalid_files = array();

          //harmonious zip_open zip_entry_name zip_read zip_entry_read zip_entry_open zip_entry_filesize zip_entry_close zip_close
          if (extension_loaded("zip")) {
            if (is_resource($zip = zip_open($_FILES[$key]['tmp_name']))) {
              while ($zip_entry = zip_read($zip)) {
                if (zip_entry_open($zip, $zip_entry, "r")) {
                  $temp_path = $target_root . '/' . md5(rand());
                  file_write_contents($temp_path,
                                    zip_entry_read($zip_entry,
                                                   zip_entry_filesize($zip_entry)));

                  $comic_file = zip_entry_name($zip_entry);
                  $target_filename = pathinfo(zip_entry_name($zip_entry), PATHINFO_BASENAME);

                  $result = cpm_breakdown_comic_filename($target_filename, true);

                  if (file_exists($temp_path)) {
                    extract(cpm_do_gd_file_check_on_upload($temp_path, $target_filename));

                    if ($result !== false) {
                      extract($result, EXTR_PREFIX_ALL, "filename");

                      if ($file_ok) {
                        if (($obfuscated_filename = cpm_obfuscate_filename($target_filename)) !== $target_filename) {
                          $cpm_config->messages[] = sprintf(__('Uploaded file %1$s renamed to %2$s.', 'comicpress-manager'), $target_filename, $obfuscated_filename);
                          $filename_original_titles[$obfuscated_filename] = $result['converted_title'];

                          $target_filename = $obfuscated_filename;
                        }

                        @rename($temp_path, $target_root . '/' . $target_filename);
                        $files_created_in_operation[] = $target_root . '/' . $target_filename;
                        $files_uploaded[] = $target_filename;
                        if ($gd_did_rename) { $gd_rename_file[] = $comic_file; }

                        if ($did_filecheck) {
                          if ($is_cmyk) {
                            $did_convert_cmyk_jpeg[] = $comic_file;
                          }
                        }
                      } else {
                        if ($did_filecheck) {
                          $invalid_image_types[] = $comic_file;
                        } else {
                          $invalid_filenames[] = $comic_file;
                        }
                      }
                    } else {
                      $files_not_uploaded[] = $comic_file;
                    }
                  } else {
                    $invalid_filenames[] = $comic_file;
                  }

                  @unlink($temp_path);
                  zip_entry_close($zip_entry);
                }

                if (($result = cpm_breakdown_comic_filename($target_filename, true)) !== false) {
                  extract($result, EXTR_PREFIX_ALL, 'filename');
                  $target_path = $target_root . '/' . $target_filename;

                  if (isset($_POST['upload-date-format']) && !empty($_POST['upload-date-format'])) {
                    $target_filename = date(CPM_DATE_FORMAT, strtotime($result['date'])) .
                                        $result['title'] . '.' . pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION);
                  }
                } else {
                  $invalid_filenames[] = $comic_file;
                }
              }
              zip_close($zip);
            }
          } else {
            $cpm_config->warnings[] = sprintf(__("The Zip extension is not installed. %s was not processed.", 'comicpress-manager'), $_FILES[$key]['name']);
          }
          //harmonious_end
        } else {
          $target_filename = $_FILES[$key]['name'];
          if (get_magic_quotes_gpc()) {
            $target_filename = stripslashes($target_filename);
          }

          $tried_replace = false;
          if (!empty($_POST['overwrite-existing-file-choice'])) {
            $tried_replace = true;
            $original_filename = $target_filename;
            $target_filename = $_POST['overwrite-existing-file-choice'];
            if (get_magic_quotes_gpc()) {
              $target_filename = stripslashes($target_filename);
            }

            $new_post = false;

            if (pathinfo($original_filename, PATHINFO_EXTENSION) != pathinfo($target_filename, PATHINFO_EXTENSION)) {
              if (@unlink($target_root . '/' . $target_filename)) {
                foreach (cpm_get_thumbnails_to_generate() as $type) {
                  $path = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties[$type . "_comic_folder"];
                  if (($subdir = cpm_get_thumbnails_to_generate()) !== false) {
                    $path .= '/' . $subdir;
                  }
                  @unlink($path . '/' . $target_filename);
                }
              }

              $target_filename = preg_replace('#\.[^\.]+$#', '', $target_filename) . '.' . pathinfo($original_filename, PATHINFO_EXTENSION);
            }

            $result = cpm_breakdown_comic_filename($target_filename);
            $cpm_config->messages[] = sprintf(__('Uploaded file <strong>%1$s</strong> renamed to <strong>%2$s</strong>.', 'comicpress-manager'), $original_filename, $target_filename);
          } else {
            if (count($files) == 1) {
              if (!empty($_POST['override-date'])) {
                $date = strtotime($_POST['override-date']);
                if (($date !== false) && ($date !== -1)) {
                  $new_date = date(CPM_DATE_FORMAT, $date);

                  $old_filename = $target_filename;
                  if (($target_result = cpm_breakdown_comic_filename($target_filename, true)) !== false) {
                    $target_filename = $new_date . $target_result['title'] . '.' . pathinfo($target_filename, PATHINFO_EXTENSION);
                  } else {
                    $target_filename = $new_date . '-' . $target_filename;
                  }

                  if ($old_filename !== $target_filename) {
                    $cpm_config->messages[] = sprintf(__('Uploaded file %1$s renamed to %2$s.', 'comicpress-manager'), $_FILES[$key]['name'], $target_filename);
                  }

                  $result = cpm_breakdown_comic_filename($target_filename);
                } else {
                  if (preg_match('/\S/', $_POST['override-date']) > 0) {
                    $cpm_config->warnings[] = sprintf(__("Provided override date %s is not parseable by strtotime().", 'comicpress-manager'), $_POST['override-date']);
                  }
                }
              }
            }
            $result = cpm_breakdown_comic_filename($target_filename, true);
            if ($result !== false) { // bad file, can we get a date attached?
              if (isset($_POST['upload-date-format']) && !empty($_POST['upload-date-format'])) {
                $target_filename = date(CPM_DATE_FORMAT, strtotime($result['date'])) .
                                   $result['title'] . '.' . pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION);
              }
            }
          }

          $comic_file = $_FILES[$key]['name'];

          extract(cpm_do_gd_file_check_on_upload($_FILES[$key]['tmp_name'], $target_filename));

          $output_file = null;

          if ($result !== false) {
            extract($result, EXTR_PREFIX_ALL, "filename");

            if ($file_ok) {
              if (($obfuscated_filename = cpm_obfuscate_filename($target_filename)) !== $target_filename) {
                $cpm_config->messages[] = sprintf(__('Uploaded file %1$s renamed to %2$s.', 'comicpress-manager'), $target_filename, $obfuscated_filename);
                $filename_original_titles[$obfuscated_filename] = $result['converted_title'];
                $target_filename = $obfuscated_filename;
              }

              $output_file = $target_root . '/' . $target_filename;

              @move_uploaded_file($_FILES[$key]['tmp_name'], $output_file);
              $files_created_in_operation[] = $output_file;

              if (file_exists($output_file)) {
                $files_uploaded[] = $target_filename;
                if ($gd_did_rename) { $gd_rename_file[] = $comic_file; }
              } else {
                $files_not_uploaded[] = $target_filename;
              }
              if ($did_filecheck) {
                if ($is_cmyk) { $did_convert_cmyk_jpeg[] = $comic_file; }
              }
            } else {
              if ($did_filecheck) {
                $invalid_image_types[] = $comic_file;
              } else {
                $invalid_filenames[] = $comic_file;
              }
            }
          } else {
            if (!$tried_replace) {
              $invalid_filenames[] = $comic_file;
            }
          }

          if (   ($cpm_config->scale_method_cache == CPM_SCALE_IMAGEMAGICK)
              && (cpm_option('cpm-strip-icc-profiles') == "1")
              && !empty($output_file)) {
            $temp_output_file = $output_file . '.' . md5(rand());

            $command = array("convert",
                             "\"${output_file}\"",
                             "-strip",
                             "\"${temp_output_file}\"");

            $strip_profiles = escapeshellcmd(implode(" ", $command));

            exec($strip_profiles);

            if (file_exists($temp_output_file)) {
              @unlink($output_file);
              @rename($temp_output_file, $output_file);
            }
          }
        }
      }
    }
    if ($wpmu_version) {
      if (cpm_wpmu_is_over_storage_limit()) { $ok_to_keep_uploading = false; break; }
    }
  }

  if ($ok_to_keep_uploading) {
    foreach ($files_uploaded as $target_filename) {
      $target_path = $target_root . '/' . $target_filename;
      @chmod($target_path, CPM_FILE_UPLOAD_CHMOD);
      if ($write_thumbnails) {
        $wrote_thumbnail = cpm_write_thumbnail($target_path, $target_filename);
      }

      if (!is_null($wrote_thumbnail)) {
        if (is_array($wrote_thumbnail)) {
          $thumbnails_written[] = $target_filename;
          $files_created_in_operation = array_merge($files_created_in_operation, $wrote_thumbnail);
        } else {
          $thumbnails_not_written[] = $target_filename;
        }
      }
    }
    if ($wpmu_version) {
      if (cpm_wpmu_is_over_storage_limit()) { $ok_to_keep_uploading = false; }
    }
  }

  if ($ok_to_keep_uploading) {
    foreach ($files_uploaded as $target_filename) {
      if ($new_post) {
        extract(cpm_breakdown_comic_filename($target_filename), EXTR_PREFIX_ALL, "filename");
        if (isset($filename_original_titles[$target_filename])) {
          $filename_converted_title = $filename_original_titles[$target_filename];
        }
        if (($post_hash = generate_post_hash($filename_date, $filename_converted_title)) !== false) {
          extract($post_hash);
          $ok_to_create_post = true;
          if (isset($_POST['duplicate_check'])) {
            $ok_to_create_post = (($post_id = post_exists($post_title, $post_content, $post_date)) == 0);
          }

          if ($ok_to_create_post) {
            if (!is_null($post_id = wp_insert_post($post_hash))) {
              $posts_created[] = get_post($post_id, ARRAY_A);

              foreach (array('hovertext', 'transcript') as $field) {
                if (!empty($_POST["${field}-to-use"])) { update_post_meta($post_id, $field, $_POST["${field}-to-use"]); }
              }
            }
          } else {
            $duplicate_posts[] = array(get_post($post_id, ARRAY_A), $target_filename);
          }
        } else {
          $invalid_filenames[] = $target_filename;
        }
      }
    }
    cpm_display_operation_messages(compact('invalid_filenames', 'files_uploaded', 'files_not_uploaded',
                                           'thumbnails_written', 'thumbnails_not_written', 'posts_created',
                                           'duplicate_posts', 'invalid_image_types', 'gd_rename_file',
                                           'did_convert_cmyk_jpeg'));
  } else {
    $cpm_config->messages = array();
    $cpm_config->warnings = array($cpm_config->wpmu_disk_space_message);

    foreach ($files_created_in_operation as $file) { @unlink($file); }
  }

  return array($posts_created, $duplicate_posts);
}

/**
 * Display messages when CPM operations are completed.
 */
function cpm_display_operation_messages($info) {
  global $cpm_config;
  extract($info);

  if (count($invalid_filenames) > 0) {
    $cpm_config->messages[] = __("<strong>The following filenames were invalid:</strong> ", 'comicpress-manager') . implode(", ", $invalid_filenames);
  }

  if (count($invalid_image_types) > 0) {
    $cpm_config->warnings[] = __("<strong>According to GD, the following files were invalid image files:</strong> ", 'comicpress-manager') . implode(", ", $invalid_image_types);
  }

  if (count($files_uploaded) > 0) {
    $cpm_config->messages[] = __("<strong>The following files were uploaded:</strong> ", 'comicpress-manager') . implode(", ", $files_uploaded);
  }

  if (count($files_not_uploaded) > 0) {
    $cpm_config->messages[] = __("<strong>The following files were not uploaded, or the permissions on the uploaded file do not allow reading the file.</strong> Check the permissions of both the target directory and the upload directory and try again: ", 'comicpress-manager') . implode(", ", $files_not_uploaded);
  }

  if (count($thumbnails_written) > 0) {
    $cpm_config->messages[] = __("<strong>Thumbnails were written for the following files:</strong> ", 'comicpress-manager') . implode(", ", $thumbnails_written);
  }

  if (count($thumbnails_not_written) > 0) {
    $cpm_config->messages[] = __("<strong>Thumbnails were not written for the following files.</strong>  Check the permissions on the rss &amp; archive folders, and make sure the files you're processing are valid image files: ", 'comicpress-manager') . implode(", ", $thumbnails_not_written);
  }

  if (count($new_thumbnails_not_needed) > 0) {
    $cpm_config->messages[] = __("<strong>New thumbnails were not needed for the following files:</strong> ", 'comicpress-manager') . implode(", ", $new_thumbnails_not_needed);
  }

  if (count($gd_rename_file) > 0) {
    $cpm_config->messages[] = __("<strong>GD was able to recognize the filetypes of these files and change their extensions to match:</strong> ", 'comicpress-manager') . implode(", ", $gd_rename_file);
  }

  if (count($did_convert_cmyk_jpeg) > 0) {
    $cpm_config->messages[] = __("<strong>The following JPEG files have been converted from CMYK to RGB:</strong> ", 'comicpress-manager') . implode(", ", $did_convert_cmyk_jpeg);
  }

  if (count($posts_created) > 0) {
    $post_links = array();
    foreach ($posts_created as $comic_post) {
      $post_links[] = "<li><strong>" . $comic_post['post_title'] . "</strong> (" . $comic_post['post_date'] . ") " . generate_view_edit_post_links($comic_post) . "</li>";
    }

    $cpm_config->messages[] = __("<strong>New posts created.</strong>  View them from the links below:", 'comicpress-manager') . " <ul>" . implode("", $post_links) . "</ul>";
  } else {
    if (count($files_uploaded) > 0) {
      if (count($duplicate_posts) == 0) {
        $cpm_config->messages[] = __("<strong>No new posts created.</strong>", 'comicpress-manager');
      }
    }
  }

  if (count($duplicate_posts) > 0) {
    $post_links = array();
    foreach ($duplicate_posts as $info) {
      list($comic_post, $comic_file) = $info;
      $post_links[] = "<li><strong>" . $comic_file . " &mdash; " . $comic_post['post_title'] . "</strong> (" . $comic_post['post_date'] . ") " . generate_view_edit_post_links($comic_post) . "</li>";
    }

    $cpm_config->messages[] = __("<strong>The following files would have created duplicate posts.</strong> View them from the links below: ", 'comicpress-manager') . "<ul>" . implode("", $post_links) . "</ul>";
  }
}

/**
 * Show the Post Body Template.
 * @param integer $width The width of the editor in pixels.
 */
function cpm_show_post_body_template($width = 435) {
  global $cpm_config; ?>

  <?php if (function_exists('wp_tiny_mce')) { wp_tiny_mce(); } ?>

  <table class="form-table">
    <tr>
      <td>
        <strong>Post body template:</strong>
        <div id="title"></div>
        <div id="<?php echo user_can_richedit() ? 'postdivrich' : 'postdiv' ?>" class="postarea">
          <?php the_editor(cpm_option('cpm-default-post-content')) ?>
        </div>

        <br />
        (<em><?php _e("Available wildcards:", 'comicpress-manager') ?></em>)
        <ul>
          <li><strong>{category}</strong>: <?php _e("The name of the category", 'comicpress-manager') ?></li>
          <li><strong>{date}</strong>: <?php printf(__("The date of the comic (ex: <em>%s</em>)", 'comicpress-manager'), date("F j, Y", time())) ?></li>
          <li><strong>{title}</strong>: <?php _e("The title of the comic", 'comicpress-manager') ?></li>
        </ul>
      </td>
    </tr>
  </table>
  <?php
}

/**
 * Include a JavaScript file, preferring the minified version of the file if available.
 */
function cpm_include_javascript($name) {

  $js_path = realpath(ABSPATH . cpm_get_plugin_path() . '/js');
  $plugin_url_root = get_option('siteurl') . '/' . cpm_get_plugin_path();

  $regular_file = $name;
  $minified_file = 'minified-' . $name;

  $file_to_use = $regular_file;
  if (file_exists($js_path . '/' . $minified_file)) {
    if (filemtime($js_path . '/' . $minified_file) >= filemtime($js_path . '/' . $regular_file)) {
      if (filesize($js_path . '/' . $minified_file) > 0) {
        $file_to_use = $minified_file;
      }
    }
  }

  ?><script type="text/javascript" src="<?php echo $plugin_url_root ?>/js/<?php echo $file_to_use ?>"></script><?php
}

/**
 * Write all of the styles and scripts.
 */
function cpm_write_global_styles_scripts() {
  global $cpm_config, $blog_id;

  $plugin_url_root = get_option('siteurl') . '/' . cpm_get_plugin_path();

  $ajax_request_url = isset($_SERVER['URL']) ? $_SERVER['URL'] : $_SERVER['SCRIPT_URL'];
  ?>

  <script type="text/javascript">
var messages = {
  'add_file_upload_file': "<?php _e("File:", 'comicpress-manager') ?>",
  'add_file_upload_remove': "<?php _e("remove", 'comicpress-manager') ?>",
  'count_missing_posts_none_missing': "<?php _e("You're not missing any posts!", 'comicpress-manager') ?>",
  'failure_in_counting_posts': "<?php _e("There was a failure in counting. You may have too many comics/posts to analyze before your server times out.", 'comicpress-manager') ?>",
  'count_missing_posts_counting': "<?php _e("counting", 'comicpress-manager') ?>"
};

var ajax_request_uri = "<?php echo $plugin_url_root ?>/comicpress_manager_count_missing_entries.php?blog_id=<?php echo $blog_id ?>";
  </script>
  <?php cpm_include_javascript("comicpress_script.js") ?>
  <link rel="stylesheet" href="<?php echo $plugin_url_root . '/comicpress_styles.css' ?>" type="text/css" />
  <?php if ($cpm_config->need_calendars) { ?>
    <link rel="stylesheet" href="<?php echo $plugin_url_root ?>/jscalendar-1.0/calendar-blue.css" type="text/css" />
    <script type="text/javascript" src="<?php echo $plugin_url_root ?>/jscalendar-1.0/calendar.js"></script>
    <script type="text/javascript" src="<?php echo $plugin_url_root ?>/jscalendar-1.0/lang/calendar-en.js"></script>
    <script type="text/javascript" src="<?php echo $plugin_url_root ?>/jscalendar-1.0/calendar-setup.js"></script>
  <?php } ?>
  <!--[if IE]>
    <script type="text/javascript">Event.observe(window, 'load', function() { prepare_comicpress_manager() })</script>
  <![endif]-->

<!--[if lte IE 6]>
<style type="text/css">div#cpm-container div#cpm-left-column { margin-top: 0 }</style>
<![endif]-->
<?php }

/**
 * Handle any warnings that have been invoked.
 */
function cpm_handle_warnings() {
  global $cpm_config, $wpmu_version;

    // display informative messages to the use
    // TODO: remove separate arrays and tag messages based on an enum value
    foreach (array(
      array(
        $cpm_config->messages,
        __("The operation you just performed returned the following:", 'comicpress-manager'),
        'messages'),
      array(
        $cpm_config->warnings,
        __("The following warnings were generated:", 'comicpress-manager'),
        'warnings'),
      array(
        $cpm_config->errors,
        __("The following problems were found in your configuration:", 'comicpress-manager'),
        'errors')
    ) as $info) {
      list($messages, $header, $style) = $info;
      if (count($messages) > 0) {
        if (count($messages) == 1) {
          $output = $messages[0];
        } else {
          ob_start(); ?>

          <ul>
            <?php foreach ($messages as $message) { ?>
              <li><?php echo $message ?></li>
            <?php } ?>
          </ul>

          <?php $output = ob_get_clean();
        }

        if ((strpos(PHP_OS, "WIN") !== false) && ($style == "warnings")) {
          $output .= __("<p><strong>If your error is permissions-related, you may have to set some Windows-specific permissions on your filesystem.</strong> Consult your Webhost for more information.</p>", 'comicpress-manager');
        }

        ?>
        <div id="cpm-<?php echo $style ?>"><?php echo $output ?></div>
      <?php }
    }

    // errors are fatal.
    if (count($cpm_config->errors) > 0) {
      $current_theme_info = get_theme(get_current_theme());
      ?>
      <p><?php _e("You must fix the problems above before you can proceed with managing your ComicPress installation.", 'comicpress-manager') ?></p>

      <?php if ($cpm_config->show_config_editor) { ?>
        <p><strong><?php _e("Details:", 'comicpress-manager') ?></strong></p>
        <ul>
          <li><strong><?php _e("Current ComicPress theme folder:", 'comicpress-manager') ?></strong> <?php echo $current_theme_info['Template Dir'] ?></li>
          <li><strong><?php _e("Available categories:", 'comicpress-manager') ?></strong>
            <table id="categories-table">
              <tr>
                <th><?php _e("Category Name", 'comicpress-manager') ?></th>
                <th><?php _e("ID #", 'comicpress-manager') ?></th>
              </tr>
              <?php foreach (get_all_category_ids() as $category_id) {
                $category = get_category($category_id);
                ?>
                <tr>
                  <td><?php echo $category->category_nicename ?></td>
                  <td align="center"><?php echo $category->cat_ID ?></td>
                </tr>
              <?php } ?>
            </table>
          </li>
        </ul>
      <?php }

      $update_automatically = true;

      $available_backup_files = array();
      $found_backup_files = glob(dirname($cpm_config->config_filepath) . '/comicpress-config.php.*');
      if ($found_backup_files === false) { $found_backup_files = array(); }
      foreach ($found_backup_files as $file) {
        if (preg_match('#\.([0-9]+)$#', $file, $matches) > 0) {
          list($all, $time) = $matches;
          $available_backup_files[] = $time;
        }
      }

      arsort($available_backup_files);

//      if ($wpmu_version) {
//        $cpm_config->show_config_editor = true;
//      } else {
        if ($cpm_config->config_method == "comicpress-config.php") {
          if (!$cpm_config->can_write_config) {
            $update_automatically = false;
          }
        } else {
          if (count($available_backup_files) > 0) {
            if (!$cpm_config->can_write_config) {
              $update_automatically = false;
            }
          } else {
            $update_automatically = false;
          }
        }

        if (!$update_automatically) { ?>
          <p>
            <?php printf(__("<strong>You won't be able to update your comicpress-config.php or functions.php file directly through the ComicPress Manager interface.</strong> Check to make sure the permissions on %s and comicpress-config.php are set so that the Webserver can write to them.  Once you submit, you'll be given a block of code to paste into the comicpress-config.php file.", 'comicpress-manager'), $current_theme_info['Template Dir']) ?>
          </p>
        <?php } else {
          if (count($available_backup_files) > 0) { ?>
            <p>
              <?php _e("<strong>Some backup comicpress-config.php files were found in your theme directory.</strong>  You can choose to restore one of these backup files, or you can go ahead and create a new configuration below.", 'comicpress-manager') ?>
            </p>

            <form action="" method="post">
              <input type="hidden" name="action" value="restore-backup" />
              <strong><?php _e("Restore from backup dated:", 'comicpress-manager') ?></strong>
                <select name="backup-file-time">
                  <?php foreach($available_backup_files as $time) { ?>
                    <option value="<?php echo $time ?>">
                      <?php echo date("r", $time) ?>
                    </option>
                  <?php } ?>
                </select>
              <input type="submit" class="button" value="<?php _e("Restore", 'comicpress-manager') ?>" />
            </form>
            <hr />
          <?php }
        }
 //     }

      if ($cpm_config->show_config_editor) {
        echo cpm_manager_edit_config();
      } ?>

      <?php if ($wpmu_version) { ?>
        <hr />

        <strong><?php _e('Debug info', 'comicpress-manager') ?></strong> (<em><?php _e("this data is sanitized to protect your server's configuration", 'comicpress-manager') ?></em>)

        <?php echo cpm_show_debug_info(false);
      }

      return false;
    }
  return true;
}

/**
 * Sort backup files by timestamp.
 */
function cpm_available_backup_files_sort($a, $b) {
  if ($a[1] == $b[1]) return 0;
  return ($a[1] > $b[1]) ? -1 : 1;
}

/**
 * Handle all ComicPress actions.
 */
function cpm_handle_actions() {
  global $cpm_config;

  $valid_actions = array('multiple-upload-file', 'create-missing-posts',
                         'update-config', 'restore-backup', 'change-dates',
                         'write-comic-post', 'update-cpm-config', 'do-first-run', 'skip-first-run',
                         'build-storyline-schema', 'batch-processing', 'manage-subcomic');

  //
  // take actions based upon $_POST['action']
  //
  if (isset($_POST['action'])) {
    if (in_array($_POST['action'], $valid_actions)) {
      require_once('actions/comicpress_' . $_POST['action'] . '.php');
      call_user_func("cpm_action_" . str_replace("-", "_", $_POST['action']));
    }
  }
}

/**
 * Show the details of the current setup in the Sidebar.
 */
function cpm_show_comicpress_details() {
  global $cpm_config, $wpmu_version;

  $all_comic_dates_ok = true;
  $all_comic_dates = array();
  foreach ($cpm_config->comic_files as $comic_file) {
    if (($result = cpm_breakdown_comic_filename(pathinfo($comic_file, PATHINFO_BASENAME))) !== false) {
      if (isset($all_comic_dates[$result['date']])) { $all_comic_dates_ok = false; break; }
      $all_comic_dates[$result['date']] = true;
    }
  }

  $subdir_path = '';
  if (($subdir = cpm_get_subcomic_directory()) !== false) {
    $subdir_path .= '/' . $subdir;
  }

  ?>
    <!-- ComicPress details -->
    <div id="comicpress-details">
      <h2 style="padding-right: 0"><?php _e('ComicPress Details', 'comicpress-manager') ?></h2>
      <ul style="padding-left: 30px; margin: 0">
        <li><strong><?php _e("Configuration method:", 'comicpress-manager') ?></strong>
          <?php if ($cpm_config->config_method == "comicpress-config.php") { ?>
            <a href="?page=<?php echo plugin_basename(__FILE__) ?>-config"><?php echo $cpm_config->config_method ?></a>
            <?php if ($cpm_config->can_write_config) { ?>
              <?php _e('(click to edit)', 'comicpress-manager') ?>
            <?php } else { ?>
              <?php _e('(click to edit, cannot update automatically)', 'comicpress-manager') ?>
            <?php } ?>
          <?php } else { ?>
            <?php echo $cpm_config->config_method ?>
          <?php } ?>
        </li>
        <li><strong><?php _e('Comics folder:', 'comicpress-manager') ?></strong>
                    <?php
                      echo $cpm_config->properties['comic_folder'] . $subdir_path;
                    ?><br />
            <?php
              $too_many_comics_message = "";
              if (!$all_comic_dates_ok) {
                ob_start(); ?>
                  , <a href="?page=<?php echo plugin_basename(__FILE__) ?>-status"><em><?php _e("multiple files on the same date!", 'comicpress-manager') ?></em></a>
                <?php $too_many_comics_message = trim(ob_get_clean());
              } ?>

            <?php printf(_n('(%d comic in folder%s)', '(%d comics in folder%s)', count($cpm_config->comic_files), 'comicpress-manager'), count($cpm_config->comic_files), $too_many_comics_message) ?>
        </li>

        <?php foreach (array('archive' => __('Archive folder:', 'comicpress-manager'),
                             'rss'     => __('RSS feed folder:', 'comicpress-manager'),
                             'mini'     => __('Minithumb folder:', 'comicpress-manager'))
                       as $type => $title) {
          $realpath = realpath(CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties["${type}_comic_folder"]  . $subdir_path);
          ?>
          <li><strong><?php echo $title ?></strong> <?php echo $cpm_config->properties["${type}_comic_folder"]  . $subdir_path; ?>
            <?php if (
              ($cpm_config->get_scale_method() != CPM_SCALE_NONE) &&
              (cpm_option("cpm-${type}-generate-thumbnails") == 1) &&
              ($cpm_config->separate_thumbs_folder_defined[$type]) &&
              ($cpm_config->thumbs_folder_writable[$type])
            ) { ?>
              (<em><?php _e('generating', 'comicpress-manager') ?></em>)
            <?php } else {
              $reasons = array();

              if ($cpm_config->get_scale_method() == CPM_SCALE_NONE) { $reasons[] = __("No scaling software", 'comicpress-manager'); }
              if (cpm_option("cpm-${type}-generate-thumbnails") == 0) { $reasons[] = __("Generation disabled", 'comicpress-manager'); }
              if (!$cpm_config->separate_thumbs_folder_defined[$type]) { $reasons[] = __("Same as comics folder", 'comicpress-manager'); }
              if (!$cpm_config->thumbs_folder_writable[$type]) { $reasons[] = __("Not writable", 'comicpress-manager'); }
              ?>
              (<em style="cursor: help; text-decoration: underline" title="<?php echo implode(", ", $reasons) ?>">not generating</em>)
            <?php } ?>
            <?php
              if ($realpath !== false) {
                printf(__("(<em>%d files in folder</em>)", 'comicpress-manager'), count(glob($realpath . "/*")));
              } else {
                _e('(<em>folder not found</em>)', 'comicpress-manager');
              }
            ?>
          </li>
        <?php } ?>

        <li><strong>
          <?php
            if (is_array($cpm_config->properties['comiccat']) && count($cpm_config->properties['comiccat']) != 1) {
              _e("Comic categories:", 'comicpress-manager');
            } else {
              _e("Comic category:", 'comicpress-manager');
            }
          ?></strong>
          <?php if (is_array($cpm_config->properties['comiccat'])) { ?>
            <ul>
              <?php foreach ($cpm_config->properties['comiccat'] as $cat_id) { ?>
                <li><a href="<?php echo get_category_link($cat_id) ?>"><?php echo get_cat_name($cat_id) ?></a>
                <?php printf(__('(ID %s)', 'comicpress-manager'), $cat_id) ?></li>
              <?php } ?>
            </ul>
          <?php } else { ?>
            <a href="<?php echo get_category_link($cpm_config->properties['comiccat']) ?>"><?php echo $cpm_config->comic_category_info['name'] ?></a>
            <?php printf(__('(ID %s)', 'comicpress-manager'), $cpm_config->properties['comiccat']) ?>
          <?php } ?>
        </li>
        <li><strong><?php _e('Blog category:', 'comicpress-manager') ?></strong> <a href="<?php echo get_category_link($cpm_config->properties['blogcat']) ?>">
            <?php echo $cpm_config->blog_category_info['name'] ?></a> <?php printf(__('(ID %s)', 'comicpress-manager'), $cpm_config->properties['blogcat']) ?></li>

        <?php if (!$wpmu_version) { ?>
          <li><strong><?php _e("PHP Version:", 'comicpress-manager') ?></strong> <?php echo phpversion() ?>
              <?php if (substr(phpversion(), 0, 3) < 5.2) { ?>
                (<a href="http://gophp5.org/hosts"><?php _e("upgrade strongly recommended", 'comicpress-manager') ?></a>)
              <?php } ?>
          </li>
          <li>
            <strong><?php _e('Theme folder:', 'comicpress-manager') ?></strong>
            <?php $theme_info = get_theme(get_current_theme());
                  if (!empty($theme_info['Template'])) {
                    echo $theme_info['Template'];
                  } else {
                    echo __("<em>Something's misconfigured with your theme...</em>", 'comicpress-manager');
                  } ?>
          </li>
          <?php if (count($cpm_config->detailed_warnings) != 0) { ?>
             <li>
                <strong><?php _e('Additional, non-fatal warnings:', 'comicpress-manager') ?></strong>
                <ul>
                  <?php foreach ($cpm_config->detailed_warnings as $warning) { ?>
                    <li><?php echo $warning ?></li>
                  <?php } ?>
                </ul>
             </li>
          <?php } ?>
          <li>
            <strong><a href="#" onclick="Element.show('debug-info'); $('cpm-right-column').style.minHeight = $('cpm-left-column').offsetHeight + 'px'; return false"><?php _e('Show debug info', 'comicpress-manager') ?></a></strong> (<em><?php _e("this data is sanitized to protect your server's configuration", 'comicpress-manager') ?></em>)
            <?php echo cpm_show_debug_info() ?>
          </li>
        <?php } ?>
      </ul>
    </div>
  <?php
}

/**
 * Show the Latest Posts in the Sidebar.
 */
function cpm_show_latest_posts() {
  global $cpm_config;

  $is_current = false;
  $is_previous = false;
  $current_timestamp = time();
  foreach (cpm_query_posts() as $comic_post) {
    $timestamp = strtotime($comic_post->post_date);

    if ($timestamp < $current_timestamp) {
      $is_current = true;
    }

    if ($is_current) {
      if ($is_previous) {
        $previous_post = $comic_post;
        break;
      }
      $current_post = $comic_post;
      $is_previous = true;
    } else {
      $upcoming_post = $comic_post;
    }
  }

  $found_posts = compact('previous_post', 'current_post', 'upcoming_post');
  $post_titles = array('previous_post' => __("Last Post", 'comicpress-manager'),
                       'current_post' => __("Current Post", 'comicpress-manager'),
                       'upcoming_post' => __("Upcoming Post", 'comicpress-manager'));
  ?>

  <div id="comicpress-latest-posts">
    <?php if (!empty($found_posts)) { ?>
      <?php foreach ($post_titles as $key => $title) {
        if (!empty($found_posts[$key])) {
          $timestamp = strtotime($found_posts[$key]->post_date);
          $post_date = date(CPM_DATE_FORMAT, $timestamp);

          $comic_file = null;
          foreach ($cpm_config->comic_files as $file) {
            if (($result = cpm_breakdown_comic_filename(pathinfo($file, PATHINFO_BASENAME))) !== false) {
              if ($result['date'] == $post_date) { $comic_file = $file; break; }
            }
          }

          ?>
          <div class="<?php echo (!empty($comic_file)) ? "comic-found" : "comic-not-found" ?>">
            <h3><?php echo $title ?> &mdash; <?php echo $post_date ?></h3>

            <h4><?php echo $found_posts[$key]->post_title ?> [<?php echo generate_view_edit_post_links((array)$found_posts[$key]) ?>]</h4>

            <?php if (!empty($comic_file)) { ?>
              <img alt="<?php echo $found_posts[$key]->post_title ?>" src="<?php echo cpm_build_comic_uri($file, CPM_DOCUMENT_ROOT) ?>" width="320" />
            <?php } else { ?>
              <div class="alert">Comic file not found!</div>
            <?php } ?>
          </div>
        <?php }
      }
    } else { ?>
      <p>You don't have any comic posts!</p>
    <?php } ?>
  </div>

  <?php
}

/**
 * Show site debug info.
 */
function cpm_show_debug_info($display_none = true) {
  global $cpm_config;

  ob_start(); ?>
  <span id="debug-info" class="code-block" <?php echo $display_none ? "style=\"display: none\"" : "" ?>><?php
    $output_config = get_object_vars($cpm_config);
    $output_config['comic_files'] = count($cpm_config->comic_files) . " comic files";
    $output_config['config_filepath'] = substr(realpath($cpm_config->config_filepath), CPM_STRLEN_REALPATH_DOCUMENT_ROOT);
    $output_config['path'] = substr(realpath($cpm_config->path), CPM_STRLEN_REALPATH_DOCUMENT_ROOT);
    $output_config['zip_enabled'] = extension_loaded("zip");

    clearstatcache();
    $output_config['folder_perms'] = array();

    $subdir = "";
    if (($subdir = cpm_get_subcomic_directory()) !== false) {
      $subdir = '/' . $subdir;
    }

    $output_config['directory_realpaths'] = array();

    foreach (array(
      'comic' => CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties['comic_folder'] . $subdir,
      'rss' => CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties['rss_comic_folder'] . $subdir,
      'mini' => CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties['mini_comic_folder'] . $subdir,
      'archive' => CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties['archive_comic_folder'] . $subdir,
      'config' => $cpm_config->config_filepath
    ) as $key => $path) {
      if (($s = @stat($path)) !== false) {
        $output_config['folder_perms'][$key] = decoct($s[2]);
      } else {
        $output_config['folder_perms'][$key] = "folder does not exist";
      }
      $output_config['directory_realpaths'][$key] = $path;
    }

    $new_output_config = array();
    foreach ($output_config as $key => $value) {
      if (is_string($value)) {
        $value = htmlentities($value);
      }
      $new_output_config[$key] = $value;
    }

    var_dump($new_output_config); // ignore var_dump
  ?></span>
  <?php

  return ob_get_clean();
}

/**
 * Show the config editor.
 */
function cpm_manager_edit_config() {
  global $cpm_config, $wpmu_version;

  include('cp_configuration_options.php');

  $max_depth = 3;
  $max_depth_message = false;
  $max_directories = 256;

  $folders_to_ignore = implode("|", array('wp-content', 'wp-includes', 'wp-admin'));

  $folder_stack = glob(CPM_DOCUMENT_ROOT . '/*');
  if ($folder_stack === false) { $folder_stack = array(); }
  $found_folders = array();

  while (count($folder_stack) > 0) {
    $file = array_shift($folder_stack);
    if (is_dir($file)) {
      $root_file = substr($file, strlen(CPM_DOCUMENT_ROOT) + 1);
      if (preg_match("#(${folders_to_ignore})$#", $root_file) == 0) {
        if (count(explode("/", $root_file)) <= $max_depth) {
          $found_folders[] = $root_file;

          $files = glob($file . "/*");
          if (is_array($files)) {
            $folder_stack = array_merge($folder_stack, $files);
          }
        } else {
          if (!$max_depth_message) {
            $cpm_config->messages[] = sprintf(__("I went %s levels deep in my search for comic directories. Are you sure you have your site set up correctly?", 'comicpress-manager'), $max_depth);
            $max_depth_message = true;
          }
        }
      }
    }
    if (count($found_folders) == $max_directories) {
      $cpm_config->messages[] = sprintf(__("I found over %s directories from your site root. Are you sure you have your site set up correctly?", 'comicpress-manager'), $max_directories);
      break;
    }
  }

  sort($found_folders);

  ob_start(); ?>

  <form action="" method="post" id="config-editor">
    <input type="hidden" name="action" value="update-config" />

    <table class="form-table">
      <?php foreach ($comicpress_configuration_options as $field_info) {
        $no_wpmu = false;
        extract($field_info);

 //       $ok = ($wpmu_version) ? ($no_wpmu !== true) : true;
        $ok = true;
        if ($ok) {
          $description = " <em>(" . $description . ")</em>";

          $config_id = (isset($field_info['variable_name'])) ? $field_info['variable_name'] : $field_info['id'];

          switch($type) {
            case "category": ?>
              <tr>
                <th scope="row"><?php echo $name ?>:</th>
                <td><select name="<?php echo $config_id ?>" title="<?php _e('All possible WordPress categories', 'comicpress-manager') ?>">
                               <?php foreach (get_all_category_ids() as $cat_id) {
                                 $category = get_category($cat_id); ?>
                                 <option value="<?php echo $category->cat_ID ?>"
                                         <?php echo ($cpm_config->properties[$config_id] == $cat_id) ? " selected" : "" ?>><?php echo $category->cat_name; ?></option>
                               <?php } ?>
                             </select><?php echo $description ?></td>
              </tr>
              <?php break;
            case "folder":
              $directory_found_in_list = in_array($cpm_config->properties[$config_id], $found_folders);
              ?>
              <tr>
                <th scope="row"><?php echo $name ?>:</th>
                <td class="config-field">
                  <input type="radio" name="folder-<?php echo $config_id ?>" id="folder-s-<?php echo $config_id ?>" value="select" <?php echo $directory_found_in_list ? "checked" : "" ?>/> <label for="folder-s-<?php echo $config_id ?>">Select directory from a list</label><br />
                  <div id="folder-select-<?php echo $config_id ?>">
                    <select title="<?php _e("List of possible folders at the root of your site", 'comicpress-manager') ?>" name="select-<?php echo $config_id ?>" id="<?php echo $config_id ?>">
                    <?php
                      foreach ($found_folders as $file) { ?>
                        <option <?php echo ($file == $cpm_config->properties[$config_id]) ? " selected" : "" ?> value="<?php echo $file ?>"><?php echo $file ?></option>
                      <?php } ?>
                    </select><?php echo $description ?>
                  </div>

                  <input type="radio" name="folder-<?php echo $config_id ?>" id="folder-e-<?php echo $config_id ?>" value="enter" <?php echo !$directory_found_in_list ? "checked" : "" ?>/> <label for="folder-e-<?php echo $config_id ?>">Enter in my directory name</label><br />
                  <div id="folder-enter-<?php echo $config_id ?>">
                    <input type="text" name="enter-<?php echo $config_id ?>" value="<?php echo $cpm_config->properties[$config_id] ?>" />
                  </div>
                  <script type="text/javascript">
                    var folder_handler_<?php echo $config_id ?> = function() {
                      if ($("folder-e-<?php echo $config_id ?>").checked) {
                        $("folder-select-<?php echo $config_id ?>").hide();
                        $("folder-enter-<?php echo $config_id ?>").show();
                      } else {
                        $("folder-select-<?php echo $config_id ?>").show();
                        $("folder-enter-<?php echo $config_id ?>").hide();
                      }
                    };

                    ["s", "e"].each(function(w) {
                      Event.observe("folder-" + w + "-<?php echo $config_id ?>", 'change', folder_handler_<?php echo $config_id ?>);
                    });

                    folder_handler_<?php echo $config_id ?>();
                  </script>
                </td>
              </tr>
              <?php break;
            case "integer": ?>
              <tr>
                <th scope="row"><?php echo $name ?>:</th>
                <td><input type="text" name="<?php echo $config_id ?>" size="20" value="<?php echo $cpm_config->properties[$config_id] ?>" /><?php echo $description ?></td>
              </tr>
              <?php break;
          }
        }
      } ?>
      <?php if (!$wpmu_version) { ?>
        <?php
          $all_comic_folders_found = true;
          foreach (array(''. 'rss_', 'archive_') as $folder_name) {
            if (!file_exists(CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties["${folder_name}comic_folder"])) { $all_comic_folders_found = false; break; }
          }

          if (!$all_comic_folders_found) { ?>
            <tr>
              <td colspan="2">
                <p><?php _e("<strong>Create your comics, archive, or RSS folders first</strong>, then reload this page and use the dropdowns to select the target folder. If ComicPress Manager can't automatically find your folders, you can enter the folder names into the dropdowns.", 'comicpress-manager') ?></p>
              </td>
            </tr>
          <?php }
        ?>
        <?php if (!$cpm_config->is_wp_options) { ?>
          <tr>
            <th scope="row"><label for="just-show-config"><?php _e("Don't try to write my config out; just display it", 'comicpress-manager') ?></label></th>
            <td>
              <input type="checkbox" name="just-show-config" id="just-show-config" value="yes" />
              <label for="just-show-config"><em>(if you're having problems writing to your config from ComicPress Manager, check this box)</em></label>
            </td>
          </tr>
        <?php } ?>
      <?php } ?>
      <tr>
        <td colspan="2" align="center">
          <input class="button update-config" type="submit" value="<?php _e("Update Config", 'comicpress-manager') ?>" />
        </td>
      </tr>
    </table>
  </form>

  <?php return ob_get_clean();
}

/**
 * Show the footer.
 */
function cpm_show_footer() {
  $version_string = "";
  foreach (array('/', '/../') as $pathing) {
    if (($path = realpath(dirname(__FILE__) . $pathing . 'comicpress-manager.php')) !== false) {
      if (file_exists($path)) {
        $info = get_plugin_data($path);
        $version_string = sprintf(__("Version %s |", 'comicpress-manager'), $info['Version']);
        break;
      }
    }
  }

  ?>
  <div id="cpm-footer">
    <div id="cpm-footer-paypal">
      <form action="https://www.paypal.com/cgi-bin/webscr" method="post">
      <input type="hidden" name="cmd" value="_s-xclick">
      <input type="hidden" name="encrypted" value="-----BEGIN PKCS7-----MIIHdwYJKoZIhvcNAQcEoIIHaDCCB2QCAQExggEwMIIBLAIBADCBlDCBjjELMAkGA1UEBhMCVVMxCzAJBgNVBAgTAkNBMRYwFAYDVQQHEw1Nb3VudGFpbiBWaWV3MRQwEgYDVQQKEwtQYXlQYWwgSW5jLjETMBEGA1UECxQKbGl2ZV9jZXJ0czERMA8GA1UEAxQIbGl2ZV9hcGkxHDAaBgkqhkiG9w0BCQEWDXJlQHBheXBhbC5jb20CAQAwDQYJKoZIhvcNAQEBBQAEgYBt5XgClPZfdf9s2CHnk4Ka5NQv+Aoswm3efVANJKvHR3h4msnSWwDzlJuve/JD5aE0rP4SRLnWuc4qIhOeeAP+MEGbs8WNDEPtUopmSy6aphnIVduSstqRWWSYElK5Wij/H8aJtiLML3rVBtiixgFBbj2HqD2JXuEgduepEvVMnDELMAkGBSsOAwIaBQAwgfQGCSqGSIb3DQEHATAUBggqhkiG9w0DBwQIlFUk3PoXtLKAgdAjA3AjtLZz9ZnJslgJPALzIwYw8tMbNWvyJXWksgZRdfMw29INEcgMrSYoWNHY4AKpWMrSxUcx3fUlrgvPBBa1P96NcgKfJ6U0KwygOLrolH0JAzX0cC0WU3FYSyuV3BZdWyHyb38/s9AtodBFy26fxGqvwnwgWefQE5p9k66lWA4COoc3hszyFy9ZiJ+3PFtH/j8+5SVvmRUk4EUWBMopccHzLvkpN2WALLAU4RGKGfH30K1H8+t8E/+uKH1jt8p/N6p60jR+n7+GTffo3NahoIIDhzCCA4MwggLsoAMCAQICAQAwDQYJKoZIhvcNAQEFBQAwgY4xCzAJBgNVBAYTAlVTMQswCQYDVQQIEwJDQTEWMBQGA1UEBxMNTW91bnRhaW4gVmlldzEUMBIGA1UEChMLUGF5UGFsIEluYy4xEzARBgNVBAsUCmxpdmVfY2VydHMxETAPBgNVBAMUCGxpdmVfYXBpMRwwGgYJKoZIhvcNAQkBFg1yZUBwYXlwYWwuY29tMB4XDTA0MDIxMzEwMTMxNVoXDTM1MDIxMzEwMTMxNVowgY4xCzAJBgNVBAYTAlVTMQswCQYDVQQIEwJDQTEWMBQGA1UEBxMNTW91bnRhaW4gVmlldzEUMBIGA1UEChMLUGF5UGFsIEluYy4xEzARBgNVBAsUCmxpdmVfY2VydHMxETAPBgNVBAMUCGxpdmVfYXBpMRwwGgYJKoZIhvcNAQkBFg1yZUBwYXlwYWwuY29tMIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDBR07d/ETMS1ycjtkpkvjXZe9k+6CieLuLsPumsJ7QC1odNz3sJiCbs2wC0nLE0uLGaEtXynIgRqIddYCHx88pb5HTXv4SZeuv0Rqq4+axW9PLAAATU8w04qqjaSXgbGLP3NmohqM6bV9kZZwZLR/klDaQGo1u9uDb9lr4Yn+rBQIDAQABo4HuMIHrMB0GA1UdDgQWBBSWn3y7xm8XvVk/UtcKG+wQ1mSUazCBuwYDVR0jBIGzMIGwgBSWn3y7xm8XvVk/UtcKG+wQ1mSUa6GBlKSBkTCBjjELMAkGA1UEBhMCVVMxCzAJBgNVBAgTAkNBMRYwFAYDVQQHEw1Nb3VudGFpbiBWaWV3MRQwEgYDVQQKEwtQYXlQYWwgSW5jLjETMBEGA1UECxQKbGl2ZV9jZXJ0czERMA8GA1UEAxQIbGl2ZV9hcGkxHDAaBgkqhkiG9w0BCQEWDXJlQHBheXBhbC5jb22CAQAwDAYDVR0TBAUwAwEB/zANBgkqhkiG9w0BAQUFAAOBgQCBXzpWmoBa5e9fo6ujionW1hUhPkOBakTr3YCDjbYfvJEiv/2P+IobhOGJr85+XHhN0v4gUkEDI8r2/rNk1m0GA8HKddvTjyGw/XqXa+LSTlDYkqI8OwR8GEYj4efEtcRpRYBxV8KxAW93YDWzFGvruKnnLbDAF6VR5w/cCMn5hzGCAZowggGWAgEBMIGUMIGOMQswCQYDVQQGEwJVUzELMAkGA1UECBMCQ0ExFjAUBgNVBAcTDU1vdW50YWluIFZpZXcxFDASBgNVBAoTC1BheVBhbCBJbmMuMRMwEQYDVQQLFApsaXZlX2NlcnRzMREwDwYDVQQDFAhsaXZlX2FwaTEcMBoGCSqGSIb3DQEJARYNcmVAcGF5cGFsLmNvbQIBADAJBgUrDgMCGgUAoF0wGAYJKoZIhvcNAQkDMQsGCSqGSIb3DQEHATAcBgkqhkiG9w0BCQUxDxcNMDkwMTA2MDAyOTQwWjAjBgkqhkiG9w0BCQQxFgQUITTqZaXyM43N5f08PBPDuRmzzdEwDQYJKoZIhvcNAQEBBQAEgYAV0szDQPbcyW/O9pZ7jUghTRdgHbCX4RyjPzR35IrI8MrqmtK94ENuD6Xf8PxkAJ3QdDr9OvkzWOHFVrb6YrAdh+XxBsMf1lD17UbwN3XZFn5HqvoWNFxNr5j3qx0DBsCh5RlGex+HAvtIoJu21uGRjbOQQsYFdlAPHxokkVP/Xw==-----END PKCS7-----
      ">
      <input type="image" src="https://www.paypal.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" alt="">
      <img alt="" border="0" src="https://www.paypal.com/en_US/i/scr/pixel.gif" width="1" height="1">
      </form>
    </div>
    <div id="cpm-footer-text">
      <?php _e('<a href="http://wordpress.org/extend/plugins/comicpress-manager/" target="_new">ComicPress Manager</a> is built for the <a href="http://www.mindfaucet.com/comicpress/" target="_new">ComicPress</a> theme', 'comicpress-manager') ?> |
      <?php _e('Copyright 2008-2009 <a href="mailto:john@coswellproductions.com?Subject=ComicPress Manager Comments">John Bintz</a>', 'comicpress-manager') ?> |
      <?php _e('Released under the GNU GPL', 'comicpress-manager') ?> |
      <?php echo $version_string ?>
      <?php _e('<a href="http://bugs.comicpress.org/index.php?project=2">Report a Bug</a>', 'comicpress-manager') ?> |
      <?php _e('Uses the <a target="_new" href="http://www.dynarch.com/projects/calendar/">Dynarch DHTML Calendar Widget</a>', 'comicpress-manager') ?>
    </div>
  </div>
<?php }

?>
