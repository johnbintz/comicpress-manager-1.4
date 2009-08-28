<?php

/**
 * Functions that are tested by test_comicpress_manager.php live here,
 * to reduce the amount of WordPress simulation necessary for testing.
 */

class ComicPressConfig {
  var $properties = array(
    // Leave these alone! These values should be read from your comicpress-config.php file.
    // If your values from comicpress-config.php are not being read, then something is wrong in your config.
    'comic_folder'         => 'comics',
    'comiccat'             => '1',
    'blogcat'              => '2',
    'rss_comic_folder'     => 'comics',
    'archive_comic_folder' => 'comics',
    'archive_comic_width'  => '380',
    'rss_comic_width'      => '380',
    'blog_postcount'       => '10'
  );

  var $warnings, $messages, $errors, $detailed_warnings, $show_config_editor;
  var $config_method, $config_filepath, $path, $plugin_path;
  var $comic_files, $blog_category_info, $comic_category_info;
  var $scale_method_cache, $identify_method_cache, $can_write_config;
  var $need_calendars = false;
  var $is_wp_options = false;

  var $import_safe_exit = null;
  var $did_first_run;

  var $is_cpm_managing_posts, $is_cpm_modifying_categories;
  var $wpmu_disk_space_message;

  var $separate_thumbs_folder_defined = array('rss' => null, 'archive' => null);
  var $thumbs_folder_writable = array('rss' => null, 'archive' => null);
  var $allowed_extensions = array("gif", "jpg", "jpeg", "png");

  function get_scale_method() {
    if (!isset($this->scale_method_cache)) {
      $this->scale_method_cache = CPM_SCALE_NONE;
      $result = @shell_exec("which convert") . @shell_exec("which identify");
      if (!empty($result)) {
        $this->scale_method_cache = CPM_SCALE_IMAGEMAGICK;
      } else {
        if (extension_loaded("gd")) {
          $this->scale_method_cache = CPM_SCALE_GD;
        }
      }
    }
    return $this->scale_method_cache;
  }

  function ComicPressConfig() {
    if (function_exists('cpm_wpmu_config_setup')) { cpm_wpmu_config_setup($this); }
  }
}

/**
 * Get a ComicPress Manager option from WP Options.
 */
function cpm_option($name) { return get_option("comicpress-manager-${name}"); }

/**
 * Calculate the document root where comics are stored.
 */
function cpm_calculate_document_root() {
  global $cpm_attempted_document_roots, $wpmu_version;
  $cpm_attempted_document_roots = array();

  $document_root = null;

  $parsed_url = parse_url(get_option('home'));

  $translated_script_filename = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);

  foreach (array('SCRIPT_NAME', 'SCRIPT_URL') as $var_to_try) {
    $root_to_try = substr($translated_script_filename, 0, -strlen($_SERVER[$var_to_try]))  . $parsed_url['path'];
    $cpm_attempted_document_roots[] = $root_to_try;

    if (file_exists($root_to_try . '/index.php')) {
      $document_root = $root_to_try;
      break;
    }
  }

  if (is_null($document_root)) { $document_root = $_SERVER['DOCUMENT_ROOT'] . $parsed_url['path']; }

  if ($wpmu_version) {
    $document_root = cpm_wpmu_modify_path($document_root);
  }

  return untrailingslashit($document_root);
}

/**
 * Define the constants for the document root.
 */
function cpm_get_cpm_document_root() {
  if (!defined('CPM_DOCUMENT_ROOT')) {
    define('CPM_DOCUMENT_ROOT', cpm_calculate_document_root());
    define("CPM_STRLEN_REALPATH_DOCUMENT_ROOT", strlen(realpath(CPM_DOCUMENT_ROOT)));
  }
}

/**
 * Transform a date()-compatible string into a human-parseable string.
 * Useful for generating examples of date() usage.
 */
function cpm_transform_date_string($string, $replacements) {
  if (!is_array($replacements)) { return false; }
  if (!is_string($string)) { return false; }

  $transformed_string = $string;
  foreach (array("Y", "m", "d") as $required_key) {
    if (!isset($replacements[$required_key])) { return false; }
    $transformed_string = preg_replace('#(?<![\\\])' . $required_key . '#', $replacements[$required_key], $transformed_string);
  }

  $transformed_string = str_replace('\\', '', $transformed_string);
  return $transformed_string;
}

/**
 * Generate an example date string.
 */
function cpm_generate_example_date($example_date) {
  return cpm_transform_date_string($example_date, array('Y' => "YYYY", 'm' => "MM", 'd' => "DD"));
}

/**
 * Build the URI to a comic file.
 */
function cpm_build_comic_uri($filename, $base_dir = null) {
	global $wpmu_version;
  if (!is_null($base_dir)) {
    if (strlen($filename) < strlen($base_dir)) { return false; }
  }
  if (($realpath_result = realpath($filename)) !== false) {
    $filename = $realpath_result;
  }
  if (!is_null($base_dir)) {
    $filename = substr($filename, strlen($base_dir));
  }
  $parts = explode('/', str_replace('\\', '/', $filename));
  if (count($parts) < 2) { return false; }

  $parsed_url = parse_url(get_bloginfo('url'));
  $path = $parsed_url['path'];
	if ($wpmu_version) { $path = cpm_wpmu_fix_folder_to_use($path); }

  $count = (cpm_get_subcomic_directory() !== false) ? 3 : 2;

  return $path . '/' . implode('/', array_slice($parts, -$count, $count));
}

/**
 * Breakdown the name of a comic file into a date and proper title.
 */
function cpm_breakdown_comic_filename($filename, $allow_override = false) {
  $pattern = CPM_DATE_FORMAT;
  if ($allow_override) {
    if (isset($_POST['upload-date-format']) && !empty($_POST['upload-date-format'])) { $pattern = $_POST['upload-date-format']; }
  }

  $pattern = cpm_transform_date_string($pattern, array("Y" => '[0-9]{4,4}',
                                                       "m" => '[0-9]{2,2}',
                                                       "d" => '[0-9]{2,2}'));

  if (@preg_match("#^(${pattern})(.*)\.[^\.]+$#", $filename, $matches) > 0) {
    list($all, $date, $title) = $matches;

    if (strtotime($date) === false) { return false; }
    $converted_title = ucwords(trim(preg_replace('/[\-\_]/', ' ', $title)));

    return compact('date', 'title', 'converted_title');
  } else {
    return false;
  }
}

/**
 * Generate a hash for passing to wp_insert_post()
 * @param string $filename_date The post date.
 * @param string $filename_converted_title The title of the comic.
 * @return array The post information or false if the date is invalid.
 */
function generate_post_hash($filename_date, $filename_converted_title) {
  if (isset($_POST['time']) && !empty($_POST['time'])) {
    if (strtolower($_POST['time']) == "now") {
      $filename_date .= " " . strftime("%H:%M:%S");
    } else {
      $filename_date .= " " . $_POST['time'];
    }
  }
  if (($timestamp = strtotime($filename_date)) !== false) {
    if ($filename_converted_title == "") {
      $filename_converted_title = strftime("%m/%d/%Y", $timestamp);
    }

    extract(cpm_normalize_storyline_structure());

    $selected_categories = array();
    if (isset($_POST['in-comic-category'])) {
      foreach ($category_tree as $node) {
        $category_id = end(explode("/", $node));
        if (in_array($category_id, $_POST['in-comic-category'])) {
          $selected_categories[$category_id] = get_cat_name($category_id);
        }
      }
    }

    $category_name = implode(",", array_values($selected_categories));

    $post_content = "";
    if (isset($_POST['content']) && !empty($_POST['content'])) {
      $post_content = $_POST['content'];
      $post_content = preg_replace('/\{date\}/', date('F j, Y', $timestamp), $post_content);
      $post_content = preg_replace('/\{title\}/', $filename_converted_title, $post_content);
      $post_content = preg_replace('/\{category\}/', $category_name, $post_content);
    }

    $override_title = $_POST['override-title-to-use'];
    $tags = $_POST['tags'];
    if (get_magic_quotes_gpc()) {
      $override_title = stripslashes($override_title);
      $tags = stripslashes($tags);
    }

    $post_title    = !empty($override_title) ? $override_title : $filename_converted_title;
    $post_date     = date('Y-m-d H:i:s', $timestamp);
    $post_date_gmt = get_gmt_from_date($post_date);
    $post_category = array_keys($selected_categories);

    if (isset($_POST['additional-categories'])) {
      if (is_array($_POST['additional-categories'])) {
        $post_category = array_merge($post_category, array_intersect(get_all_category_ids(), $_POST['additional-categories']));
      }
    }

    $publish_mode = ($timestamp > time()) ? "future" : "publish";
    $post_status   = isset($_POST['publish']) ? $publish_mode : "draft";
    $tags_input    = $tags;

    return compact('post_content', 'post_title', 'post_date', 'post_date_gmt', 'post_category', 'post_status', 'tags_input');
  }

  return false;
}

/**
 * Retrieve posts from the WordPress database.
 */
function cpm_query_posts() {
  global $cpm_config;
  $query_posts_string = "posts_per_page=999999&post_status=draft,pending,future,inherit,publish&cat=";

  $comic_categories = array();
  extract(cpm_get_all_comic_categories());
  foreach ($category_tree as $node) {
    $comic_categories[] = end(explode("/", $node));
  }

  $query_posts_string .= implode(",", $comic_categories);

  $result = query_posts($query_posts_string);
  if (empty($result)) { $result = array(); }
  return $result;
}

/**
 * Get the absolute filepath to the comic folder.
 */
function get_comic_folder_path() {
  global $cpm_config;

  $output = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties['comic_folder'];

  if (($subdir = cpm_get_subcomic_directory()) !== false) {
    $output .= '/' . $subdir;
  }

  return $output;
}

function cpm_get_subcomic_directory() {
  global $cpm_config;

  if (function_exists('get_option')) {
    $result = get_option('comicpress-manager-manage-subcomic');
    if (!empty($result)) {
      if ($result != $cpm_config->properties['comiccat']) {
        if (($category = get_category($result)) !== false) {
          return $category->slug;
        }
      }
    }
  }
  return false;
}

/**
 * Find all the valid comics in the comics folder.
 * If CPM_SKIP_CHECKS is enabled, comic file validity is not checked, improving speed.
 * @return array The list of valid comic files in the comic folder.
 */
function cpm_read_comics_folder() {
  global $cpm_config;

  $glob_results = glob(get_comic_folder_path() . "/*");
  if ($glob_results === false) {
    //$cpm_config->messages[] = "FYI: glob({$cpm_config->path}/*) returned false. This can happen on some PHP installations if you have no files in your comic directory. This message will disappear once you upload a comic to your site.";
    return array(); 
  }

  $filtered_glob_results = array();
  foreach ($glob_results as $result) {
    if (in_array(strtolower(pathinfo($result, PATHINFO_EXTENSION)), $cpm_config->allowed_extensions)) {
      $filtered_glob_results[] = $result;
    }
  }

  if (cpm_option("cpm-skip-checks") == 1) {
    return $filtered_glob_results;
  } else {
    $files = array();
    foreach ($filtered_glob_results as $file) {
      if (cpm_breakdown_comic_filename(pathinfo($file, PATHINFO_BASENAME)) !== false) {
        $files[] = $file;
      }
    }
    return $files;
  }
}

/**
 * Read information about the current installation.
 */
function cpm_read_information_and_check_config() {
  global $cpm_config, $cpm_attempted_document_roots, $blog_id, $wpmu_version;

  $cpm_config->config_method = read_current_theme_comicpress_config();
  $cpm_config->config_filepath = get_functions_php_filepath();
  $cpm_config->can_write_config = can_write_comicpress_config($cpm_config->config_filepath);

  $cpm_config->path = get_comic_folder_path();
  $cpm_config->plugin_path = PLUGINDIR . '/' . plugin_basename(__FILE__);

  foreach (array_keys($cpm_config->separate_thumbs_folder_defined) as $type) {
    $cpm_config->separate_thumbs_folder_defined[$type] = ($cpm_config->properties['comic_folder'] != $cpm_config->properties[$type . '_comic_folder']);
  }

  $cpm_config->errors = array();
  $cpm_config->warnings = array();
  $cpm_config->detailed_warnings = array();
  $cpm_config->messages = array();
  $cpm_config->show_config_editor = true;

  $folders = array(
    array('comic folder', 'comic_folder', true, ""),
    array('RSS feed folder', 'rss_comic_folder', false, 'rss'),
    array('archive folder', 'archive_comic_folder', false, 'archive'));

  foreach ($folders as $folder_info) {
    list ($name, $property, $is_fatal, $thumb_type) = $folder_info;
    if ($thumb_type != "") {
      $cpm_config->thumbs_folder_writable[$thumb_type] = null;
    }
  }

  if (cpm_option("cpm-skip-checks") == 1) {
    // if the user knows what they're doing, disabling all of the checks improves performance
    foreach ($folders as $folder_info) {
      list ($name, $property, $is_fatal, $thumb_type) = $folder_info;
      $path = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties[$property];
      if ($thumb_type != "") {
        $cpm_config->thumbs_folder_writable[$thumb_type] = true;
      }
    }

    foreach (array('comic', 'blog') as $type) {
      $result = (object)get_category($cpm_config->properties["${type}cat"]);
      if (!is_wp_error($result)) {
        $cpm_config->{"${type}_category_info"} = get_object_vars($result);
      }
    }

    $cpm_config->comic_files = cpm_read_comics_folder();
  } else {
    // quick check to see if the theme is ComicPress.
    // this needs to be made more robust.
    if (preg_match('/ComicPress/', get_current_theme()) == 0) {
      $cpm_config->detailed_warnings[] = __("The current theme isn't the ComicPress theme.  If you've renamed the theme, ignore this warning.", 'comicpress-manager');
    }

    $any_cpm_document_root_failures = false;

    if (!$wpmu_version) {
      // is the site root configured properly?
      if (!file_exists(CPM_DOCUMENT_ROOT)) {
        $cpm_config->warnings[] = sprintf(__('The comics site root <strong>%s</strong> does not exist. Check your <a href="options-general.php">WordPress address and address settings</a>.', 'comicpress-manager'), CPM_DOCUMENT_ROOT);
        $any_cpm_document_root_failures = true;
      }

      if (!file_exists(CPM_DOCUMENT_ROOT . '/index.php')) {
        $cpm_config->warnings[] = sprintf(__('The comics site root <strong>%s</strong> does not contain a WordPress index.php file. Check your <a href="options-general.php">WordPress address and address settings</a>.', 'comicpress-manager'), CPM_DOCUMENT_ROOT);
        $any_cpm_document_root_failures = true;
      }
    }

    if ($any_cpm_document_root_failures) {
      $cpm_config->warnings[] = print_r($cpm_attempted_document_roots, true);
    }

    // folders that are the same as the comics folder won't be written to
    $all_the_same = array();
    foreach ($cpm_config->separate_thumbs_folder_defined as $type => $value) {
      if (!$value) { $all_the_same[] = $type; }
    }

    if (count($all_the_same) > 0) {
      $cpm_config->detailed_warnings[] = sprintf(__("The <strong>%s</strong> folders and the comics folder are the same.  You won't be able to generate thumbnails until you change these folders.", 'comicpress-manager'), implode(", ", $all_the_same));
    }

    if (cpm_option('cpm-did-first-run') == 1) {
      // check the existence and writability of all image folders
      foreach ($folders as $folder_info) {
        list ($name, $property, $is_fatal, $thumb_type) = $folder_info;
        if (($thumb_type == "") || ($cpm_config->separate_thumbs_folder_defined[$thumb_type] == true)) {
          $path = CPM_DOCUMENT_ROOT . '/' . $cpm_config->properties[$property];
          if (!file_exists($path)) {
            $cpm_config->errors[] = sprintf(__('The %1$s <strong>%2$s</strong> does not exist.  Did you create it within the <strong>%3$s</strong> folder?' , 'comicpress-manager'), $name, $cpm_config->properties[$property], CPM_DOCUMENT_ROOT);
          } else {
            do {
              $tmp_filename = "test-" . md5(rand());
            } while (file_exists($path . '/' . $tmp_filename));

            $ok_to_warn = true;
            if ($thumb_type != "") {
              $ok_to_warn = (cpm_option("cpm-${thumb_type}-generate-thumbnails") == 1);
            }

            if ($ok_to_warn) {
              if (!@touch($path . '/' . $tmp_filename)) {
                $message = sprintf(__('The %1$s <strong>%2$s</strong> is not writable by the Webserver.', 'comicpress-manager'), $name, $cpm_config->properties[$property]);
                if ($is_fatal) {
                  $cpm_config->errors[] = $message;
                } else {
                  $cpm_config->warnings[] = $message;
                }
                if ($thumb_type != "") {
                  $cpm_config->thumbs_folder_writable[$thumb_type] = false;
                }
              } else {
                if (@stat($path . '/' . $tmp_filename) === false) {
                  $cpm_config->errors[] = __('<strong>Files written to the %s directory by the Webserver cannot be read again!</strong>  Are you using IIS7 with FastCGI?', $cpm_config->properties[$property]);
                  if ($thumb_type != "") {
                    $cpm_config->thumbs_folder_writable[$thumb_type] = false;
                  }
                }
              }
            }

            if (is_null($cpm_config->thumbs_folder_writable[$thumb_type])) {
              @unlink($path . '/' . $tmp_filename);
              if ($thumb_type != "") {
                $cpm_config->thumbs_folder_writable[$thumb_type] = true;
              }
            }
          }
        }
      }
    }

    // to generate thumbnails, a supported image processor is needed
    if ($cpm_config->get_scale_method() == CPM_SCALE_NONE) {
      $cpm_config->detailed_warnings[] = __("No image resize methods are installed (GD or ImageMagick).  You are unable to generate thumbnails automatically.", 'comicpress-manager');
    }

    // are there enough categories created?
    if (count(get_all_category_ids()) < 2) {
      $cpm_config->errors[] = __("You need to define at least two categories, a blog category and a comics category, to use ComicPress.  Visit <a href=\"categories.php\">Manage -> Categories</a> and create at least two categories, then return here to continue your configuration.", 'comicpress-manager');
      $cpm_config->show_config_editor = false;
    } else {
      // ensure the defined comic category exists
      if (is_null($cpm_config->properties['comiccat'])) {
        // all non-blog categories are comic categories
        $cpm_config->comic_category_info = array(
          'name' => __("All other categories", 'comicpress-manager'),
        );
        $cpm_config->properties['comiccat'] = array_diff(get_all_category_ids(), array($cpm_config->properties['blogcat']));

        if (count($cpm_config->properties['comiccat']) == 1) {
          $cpm_config->properties['comiccat'] = $cpm_config->properties['comiccat'][0];
          $cpm_config->comic_category_info = get_object_vars(get_category($cpm_config->properties['comiccat']));
        }
      } else {
        if (!is_numeric($cpm_config->properties['comiccat'])) {
          // the property is non-numeric
          $cpm_config->errors[] = __("The comic category needs to be defined as a number, not an alphanumeric string.", 'comicpress-manager');
        } else {
          // one comic category is specified
          if (is_null($cpm_config->comic_category_info = get_category($cpm_config->properties['comiccat']))) {
            $cpm_config->errors[] = sprintf(__("The requested category ID for your comic, <strong>%s</strong>, doesn't exist!", 'comicpress-manager'), $cpm_config->properties['comiccat']);
          } else {
            $cpm_config->comic_category_info = get_object_vars($cpm_config->comic_category_info);
          }
        }
      }

      // ensure the defined blog category exists
      // TODO: multiple blog categories
      if (!is_numeric($cpm_config->properties['blogcat'])) {
        // the property is non-numeric
        $cpm_config->errors[] = __("The blog category needs to be defined as a number, not an alphanumeric string.", 'comicpress-manager');
      } else {
        if (is_null($cpm_config->blog_category_info = get_category($cpm_config->properties['blogcat']))) {
          $cpm_config->errors[] = sprintf(__("The requested category ID for your blog, <strong>%s</strong>, doesn't exist!", 'comicpress-manager'), $cpm_config->properties['blogcat']);
        } else {
          $cpm_config->blog_category_info = get_object_vars($cpm_config->blog_category_info);
        }

        if (!is_array($cpm_config->properties['blogcat']) && !is_array($cpm_config->properties['comiccat'])) {
          if ($cpm_config->properties['blogcat'] == $cpm_config->properties['comiccat']) {
            $cpm_config->warnings[] = __("Your comic and blog categories are the same.  This will cause browsing problems for visitors to your site.", 'comicpress-manager');
          }
        }
      }
    }

    // a quick note if you have no comics uploaded.
    // could be a sign of something more serious.
    if (count($cpm_config->comic_files = cpm_read_comics_folder()) == 0) {
      $cpm_config->detailed_warnings[] = __("Your comics folder is empty!", 'comicpress-manager');
    }
  }
}

/**
 * Read the ComicPress config from a file.
 */
function read_current_theme_comicpress_config() {
  global $cpm_config, $wpmu_version;

  if ($wpmu_version) {
    cpm_wpmu_load_options();
    return __("WordPress Options", 'comicpress-manager');
  }

  $current_theme_info = get_theme(get_current_theme());

  $method = null;

  $config_json_file = ABSPATH . '/' . $current_theme_info['Template Dir'] . '/config.json';

  // harmonious json_decode
  if (function_exists("json_decode")) {
    if (file_exists($config_json_file)) {
      $config = json_decode(file_get_contents($config_json_file), true);

      $cpm_config->properties = array_merge($cpm_config->properties, $config);
      $method = "config.json";
    }
  }
  //harmonious_end

  if (is_null($method)) {
    if (!is_null($filepath = get_functions_php_filepath())) {
      read_comicpress_config_functions_php($filepath);
      $method = basename($filepath);
    }
  }

  return $method;
}

/**
 * Read the ComicPress config from a functions.php file.
 * Note: this isn't super-robust, but should cover basic use cases.
 */
function read_comicpress_config_functions_php($filepath) {
  global $cpm_config;

  if (!file_exists($filepath)) { $cpm_config->warnings[] = "file not found: ${filepath}"; return; }

  $file = file_get_contents($filepath);

  $variable_values = array();

  foreach (array_keys($cpm_config->properties) as $variable) {
    if (preg_match("#\\$${variable}\ *\=\ *([^\;]*)\;#", $file, $matches) > 0) {
      $variable_values[$variable] = preg_replace('#"#', '', $matches[1]);
    }
  }

  $cpm_config->properties = array_merge($cpm_config->properties, $variable_values);
}

/**
 * Get the path to the currently used config.
 */
function get_functions_php_filepath() {
  $template_files = glob(TEMPLATEPATH . '/*');
  if ($template_files === false) { $template_files = array(); }

  foreach (array("comicpress-config.php", "functions.php") as $possible_file) {
    foreach ($template_files as $file) {
      if (pathinfo($file, PATHINFO_BASENAME) == $possible_file) {
        return $file;
      }
    }
  }
  return null;
}

/**
 * See if we can write to the config folder.
 */
function can_write_comicpress_config($filepath) {
  $perm_check_filename = $filepath . '-' . md5(rand());
  if (@touch($perm_check_filename) === true) {
    $move_check_filename = $perm_check_filename . '-' . md5(rand());
    if (@rename($perm_check_filename, $move_check_filename)) {
      @unlink($move_check_filename);
      return true;
    } else {
      @unlink($perm_check_filename);
      return false;
    }
  }
  return false;
}

function cpm_get_thumbnails_to_generate() {
  global $cpm_config;
  $thumbnails_to_generate = array();

  if ($cpm_config->get_scale_method() != CPM_SCALE_NONE) {
    foreach ($cpm_config->thumbs_folder_writable as $type => $value) {
      if ($value) {
        if ($cpm_config->separate_thumbs_folder_defined[$type] !== false) {
          if (cpm_option("cpm-${type}-generate-thumbnails") == 1) {
            $thumbnails_to_generate[] = $type;
          }
        }
      }
    }
  }

  return $thumbnails_to_generate;
}

/**
 * Get a tree of the categories that are children of the comic category.
 */
function cpm_get_all_comic_categories() {
  global $cpm_config;

  $max_id = 0;

  foreach (get_all_category_ids() as $category_id) {
    $category = get_category($category_id);
    $category_tree[] = $category->parent . '/' . $category_id;
    $max_id = max($max_id, $category_id);
  }

  do {
    $all_ok = true;
    for ($i = 0; $i < count($category_tree); ++$i) {
      $current_parts = explode("/", $category_tree[$i]);
      if (reset($current_parts) != 0) {

        $all_ok = false;
        for ($j = 0; $j < count($category_tree); ++$j) {
          $j_parts = explode("/", $category_tree[$j]);

          if (end($j_parts) == reset($current_parts)) {
            $category_tree[$i] = implode("/", array_merge($j_parts, array_slice($current_parts, 1)));
            break;
          }
        }
      }
    }
  } while (!$all_ok);

  $new_category_tree = array();
  foreach ($category_tree as $node) {
    $parts = explode("/", $node);
    if ($parts[1] == $cpm_config->properties['comiccat']) { $new_category_tree[] = $node; }
  }
  $category_tree = $new_category_tree;

  return compact('category_tree', 'max_id');
}

/**
 * Normalize a storyline structure, merging it with category changes as necessary.
 * @return array A compact()ed array with the $max_id found and the $category_tree.
 */
function cpm_normalize_storyline_structure() {
  global $cpm_config;

  extract(cpm_get_all_comic_categories());

  do {
    $did_normalize = false;

    // sort it by this order as best as possible
    if ($result = get_option("comicpress-storyline-category-order")) {
      $sorted_tree = explode(",", $result);

      $new_sorted_tree = array();
      foreach ($sorted_tree as $node) {
        if (in_array($node, $category_tree)) {
          $new_sorted_tree[] = $node;
        } else {
          $did_normalize = true;
        }
      }
      $sorted_tree = $new_sorted_tree;

      foreach ($category_tree as $node) {
        if (!in_array($node, $sorted_tree)) {
          // try to find the nearest sibling
          $parts = explode("/", $node);

          while (count($parts) > 0) {
            array_pop($parts);
            $node_snippit = implode("/", $parts);
            $last_sibling = null;
            for ($i = 0; $i < count($sorted_tree); ++$i) {
              if (strpos($sorted_tree[$i], $node_snippit) === 0) {
                $last_sibling = $i;
              }
            }
            if (!is_null($last_sibling)) {
              $did_normalize = true;
              array_splice($sorted_tree, $last_sibling + 1, 0, $node);
              break;
            }
          }
        }
      }

      $category_tree = $sorted_tree;
    } else {
      sort($category_tree);
    }
    if ($did_normalize || empty($result)) {
      update_option("comicpress-storyline-category-order", implode(",", $category_tree));
    }
  } while ($did_normalize);

  return compact('category_tree', 'max_id');
}

function cpm_short_size_string_to_bytes($string) {
  $max_bytes = trim($string);

  $last = strtolower(substr($max_bytes, -1, 1));
  switch($last) {
    case 'g': $max_bytes *= 1024;
    case 'm': $max_bytes *= 1024;
    case 'k': $max_bytes *= 1024;
  }

  return $max_bytes;
}

?>
