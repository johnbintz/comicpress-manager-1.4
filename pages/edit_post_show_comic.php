<?php

/**
 * Show the comic in the Post editor.
 */
function cpm_show_comic() {
  global $post, $cpm_config;

  $form_target = plugin_basename(realpath(dirname(__FILE__) . "/../comicpress_manager_admin.php"));

  read_current_theme_comicpress_config();

  $has_comic_file = false;
  $in_comics_category = false;

  $thumbnails_to_generate = cpm_get_thumbnails_to_generate();

  $post_categories = array();

  $comic_categories = array();
  extract(cpm_normalize_storyline_structure());
  foreach ($category_tree as $node) {
    $comic_categories[] = end(explode("/", $node));
  }

  if ($post->ID !== 0) {
    $post_time = time();
    foreach (array('post_date', 'post_modified', 'post_date_gmt', 'post_modified_gmt') as $time_value) {
      if (($result = strtotime($post->{$time_value})) !== false) {
        $post_time = $result; break;
      }
    }

    $post_categories = wp_get_post_categories($post->ID);

    if (isset($cpm_config->properties['comiccat'])) {
      $in_comics_category = (count(array_intersect($comic_categories, $post_categories)) > 0);
    }

    $ok = true;
    if (cpm_get_subcomic_directory() !== false) {
      $ok = in_array(get_option('comicpress-manager-manage-subcomic'), wp_get_post_categories($post->ID));
    }

    if ($ok) {
      if (($comic = find_comic_by_date($post_time)) !== false) {
        $comic_uri = cpm_build_comic_uri($comic);

        $comic_filename = preg_replace('#^.*/([^\/]*)$#', '\1', $comic_uri);
        $link = "<strong><a target=\"comic_window\" href=\"${comic_uri}\">${comic_filename}</a></strong>";

        $date_root = substr($comic_filename, 0, strlen(date(CPM_DATE_FORMAT)));
        $thumbnails_found = cpm_find_thumbnails_by_filename($comic);

        $icon_file_to_use = $comic;
        foreach (array('rss', 'archive') as $type) {
          if (isset($thumbnails_found[$type])) {
            $icon_file_to_use = $thumbnails_found[$type];
          }
        }

        $icon_uri = cpm_build_comic_uri($icon_file_to_use);

        $has_comic_file = true;
      }
    }
  }

  ?>

  <script type="text/javascript">
    function show_comic() {
      if ($('comic-icon').offsetWidth > $('comic-icon').offsetHeight) {
        $('preview-comic').width = 400;
      } else {
        $('preview-comic').height = 400;
      }
      Element.clonePosition('comic-hover', 'comic-icon', { setWidth: false, setHeight: false, offsetTop: -((Element.getDimensions('comic-hover')['height'] - Element.getDimensions('comic-icon')['height'])/2) });
      $('comic-hover').show();
    }

    function hide_comic() { $('comic-hover').hide(); }

    var all_comic_categories = [ <?php echo implode(",", $comic_categories) ?> ];
    var storyline_enabled = <?php echo (get_option('comicpress-enable-storyline-support') == 1) ? 'true' : 'false' ?>;

    Event.observe(window, 'load', function() {
      $('post').encoding = "multipart/form-data";

      if (storyline_enabled) {
        $$('div#categories-all input').each(function(i) {
          if (all_comic_categories.indexOf(Number(i.value)) != -1) { i.disabled = true; }
        });
      }
    });
  </script>
  <div id="comicdiv" class="postbox">
    <h3><?php _e("Comic For This Post", 'comicpress-manager') ?></h3>
    <div class="inside" style="overflow: hidden">
      <?php if (count($cpm_config->comic_files) == 0) { ?>
        <div style="border: solid #daa 1px; background-color: #ffe7e7; padding: 5px">
          <strong>It looks like this is a new ComicPress install.</strong> You should test to make
          sure uploading works correctly by visiting <a href="admin.php?page=<?php echo plugin_basename(realpath(dirname(__FILE__) . '/../comicpress_manager_admin.php')) ?>">ComicPress -> Upload</a>.
        </div>
      <?php } ?>
      <?php if ($has_comic_file) { ?>
        <div id="comic-hover" style="border: solid black 1px; position: absolute; display: none" onmouseout="hide_comic()">
          <img id="preview-comic" src="<?php echo $comic_uri ?>" />
        </div>
        <a href="#" onclick="return false" onmouseover="show_comic()"><img id="comic-icon" src="<?php echo $icon_uri ?>" height="100" align="right" /></a>
        <p>
          <?php printf(__("The comic that will be shown with this post is %s.", 'comicpress-manager'), $link) ?>
          <?php _e("Mouse over the icon to the right to see a larger version of the image.", 'comicpress-manager') ?>
        </p>

        <?php
          if (cpm_get_subcomic_directory() !== false) {
            printf(__("Comic files will be uploaded to the <strong>%s</strong> comic subdirectory.", 'comicpress-manager'), get_cat_name(get_option('comicpress-manager-manage-subcomic')));
          }
        ?>

        <?php if (count($thumbnails_found) > 0) { ?>
          <p><?php _e("The following thumbnails for this comic were also found:", 'comicpress-manager') ?>
            <?php foreach ($thumbnails_found as $type => $file) { ?>
              <a target="comic_window" href="<?php echo cpm_build_comic_uri(CPM_DOCUMENT_ROOT . '/' . $file) ?>"><?php echo $type ?></a>
            <?php } ?>
          </p>
        <?php } ?>
      <?php } ?>

      <?php if (cpm_option("cpm-edit-post-integrate") == 1) { ?>
        <p><em><strong>ComicPress Manager Edit Post file management is enabled.</strong></em> Any changes to post date, or deleting this post, will affect any associated comic files.</p>
      <?php } ?>

      <p><strong>NOTE: Upload errors will not be reported.</strong> If you are having trouble uploading files, use the <a href="admin.php?page=<?php echo plugin_basename(realpath(dirname(__FILE__) . '/../comicpress_manager_admin.php')) ?>">ComicPress -> Upload</a> screen.</p>

      <table class="form-table">
        <tr>
          <th scope="row">
            <?php if ($has_comic_file) { ?>
              <?php _e("Replace This Image", 'comicpress-manager') ?>
            <?php } else { ?>
              <?php _e("Upload a New Single Image", 'comicpress-manager') ?>
            <?php } ?>
          </th>
          <td>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo cpm_short_size_string_to_bytes(ini_get('upload_max_filesize')) ?>" />
            <input type="file" id="comicpress-replace-image" name="comicpress-replace-image" class="button" /> <?php echo (empty($thumbnails_to_generate)) ? "" : __("<em>(thumbnails will be generated)</em>", 'comicpress-manager') ?><br />
            <?php if ($has_comic_file) { ?>
              <input type="hidden" name="overwrite-existing-file-choice" value="<?php echo $comic_filename ?>" />
            <?php } ?>
            <input type="hidden" name="upload-destination" value="comic" />
            <input type="hidden" name="thumbnails" value="yes" />
          </td>
          <script type="text/javascript">
            Event.observe('comicpress-replace-image', 'click', function() {
              [<?php echo (is_array($cpm_config->properties['comiccat'])) ?
                          implode(",", $cpm_config->properties['comiccat']) :
                          $cpm_config->properties['comiccat'] ?>].each(function(i) {
                $('in-category-' + i).checked = true;
              });
            });
          </script>
        </tr>
        <?php
          if (cpm_option('cpm-skip-checks') != 1) {
            if (!function_exists('get_comic_path')) { ?>
              <tr>
                <td colspan="2" style="background-color: #fee; border: solid #daa 1px">
                  <?php _e('<strong>It looks like you\'re running an older version of ComicPress.</strong> Storyline, hovertext, and transcript are fully supported in <a href="http://comicpress.org/">ComicPress 2.7</a>. You can use hovertext and transcripts in earlier themes by using <tt>get_post_meta($post->ID, "hovertext", true)</tt> and <tt>get_post_meta($post->ID, "transcript", true)</tt>.', 'comicpress-manager') ?>
                </td>
              </tr>
            <?php }
          } ?>
        <?php if (get_option('comicpress-enable-storyline-support') == 1) { ?>
          <tr>
            <th scope="row">
              <?php
                if (count($category_tree) > 1) {
                  _e("Storyline", 'comicpress-manager');
                } else {
                  _e("Category", 'comicpress-manager');
                }
              ?>
            </th>
            <td>
              <?php cpm_display_storyline_checkboxes($category_tree, $post_categories, null, "post_category") ?>
            </td>
          </tr>
        <?php } ?>
        <tr>
          <th scope="row"><?php _e('&lt;img title&gt;/hover text', 'comicpress-manager') ?></th>
          <td><input type="text" name="comicpress-img-title" size="50" value="<?php echo get_post_meta($post->ID, 'hovertext', true) ?>" /></td>
        </tr>
        <tr>
          <th scope="row"><?php _e("Transcript", 'comicpress-manager') ?></th>
          <td><textarea name="comicpress-transcript" rows="8" cols="50"><?php echo get_post_meta($post->ID, 'transcript', true) ?></textarea></td>
        </tr>
      </table>
    </div>
  </div>

  <?php
}

?>