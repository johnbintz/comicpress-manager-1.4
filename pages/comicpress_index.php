<?php

/**
 * The main manager screen.
 */
function cpm_manager_index() {
  global $cpm_config;

  if (cpm_get_subcomic_directory() !== false) {
    $cpm_config->messages[] = sprintf(__("<strong>Reminder:</strong> You are managing the <strong>%s</strong> comic subdirectory.", 'comicpress-manager'), get_cat_name(get_option('comicpress-manager-manage-subcomic')));
  }

  $cpm_config->need_calendars = true;

  $example_date = cpm_generate_example_date(CPM_DATE_FORMAT);

  $example_real_date = date(CPM_DATE_FORMAT);

  $zip_extension_loaded = extension_loaded('zip');

  if (cpm_option('cpm-skip-checks') != 1) {
    if (!function_exists('get_comic_path')) {
      $cpm_config->warnings[] =  __('<strong>It looks like you\'re running an older version of ComicPress.</strong> Storyline, hovertext, and transcript are fully supported in <a href="http://comicpress.org/">ComicPress 2.7</a>. You can use hovertext and transcripts in earlier themes by using <tt>get_post_meta($post->ID, "hovertext", true)</tt> and <tt>get_post_meta($post->ID, "transcript", true)</tt>.', 'comicpress-manager');
    }
  }

  if (count($_POST) == 0 && isset($_GET['upload'])) {
    $cpm_config->warnings[] = sprintf(__("Your uploaded files were larger than the <strong><tt>post_max_size</tt></strong> setting, which is currently <strong><tt>%s</tt></strong>. Either upload fewer/smaller files, upload them via FTP/SFTP, or increase your server's <strong><tt>post_max_size</tt></strong>.", 'comicpress-manager'), ini_get('post_max_size'));
  }

  ob_start(); ?>
    <p>
      <strong>
        <?php _e("ComicPress Manager manages your comics and your time.", 'comicpress-manager') ?>
      </strong>
      <?php _e("It makes uploading new comics, importing comics from a non-ComicPress setup, and batch uploading a lot of comics at once, very fast and configurable.", 'comicpress-manager') ?>
    </p>

    <p>
      <strong>
        <?php _e("ComicPress Manager also manages yours and your Website's sanity.", 'comicpress-manager') ?>
      </strong>

      <?php printf(__("It can check for misconfigured ComicPress setups, for incorrectly-named files (remember, it's <em>%s-single-comic-title.ext</em>) and for when you might be duplicating a post. You will also be shown which comic will appear with which blog post in the Post editor.", 'comicpress-manager'), $example_date) ?>
    </p>

    <p>
      <?php printf(__("<strong>Single comic titles</strong> are generated from the incoming filename.  If you've named your file <strong>%s-my-new-years-day.jpg</strong> and create a new post for the file, the post title will be <strong>My New Years Day</strong>.  This default should handle the majority of cases.  If a comic file does not have a title, the date in <strong>MM/DD/YYYY</strong> format will be used.", 'comicpress-manager'), $example_real_date) ?>
    </p>

    <p>
      <?php _e("<strong>Upload image files</strong> lets you upload multiple comics at a time, and add a default post body for each comic.", 'comicpress-manager') ?>
      <?php if ($zip_extension_loaded) { ?>
        <?php _e("You can <strong>upload a Zip file and create new posts</strong> from the files contained within the Zip file.", 'comicpress-manager') ?>
      <?php } else { ?>
        <?php _e("<strong>You can't upload a Zip file</strong> because you do not have the PHP <strong>zip</strong> extension installed.", 'comicpress-manager') ?>
      <?php } ?>
    </p>

    <p>
      <?php _e("Has ComicPress Manager saved you time and sanity?  <strong>Donate a few bucks to show your appreciation!</strong>", 'comicpress-manager') ?>
      <span style="display: block; text-align: center">
        <form action="https://www.paypal.com/cgi-bin/webscr" method="post">
        <input type="hidden" name="cmd" value="_s-xclick">
        <input type="hidden" name="encrypted" value="-----BEGIN PKCS7-----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-----END PKCS7-----
        ">
        <input type="image" src="https://www.paypal.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" alt="">
        <img alt="" border="0" src="https://www.paypal.com/en_US/i/scr/pixel.gif" width="1" height="1">
        </form>
      </span>
    </p>
  <?php $help_content = ob_get_clean();

  ob_start(); ?>

  <h2 style="padding-right:0;">
    <?php if ($zip_extension_loaded) {
      _e("Upload Image &amp; Zip Files", 'comicpress-manager');
    } else {
      _e("Upload Image Files", 'comicpress-manager');
    } ?>
  </h2>
  <h3>&mdash;
    <?php if (cpm_option('cpm-obfuscate-filenames-on-upload') === "none") { ?>
      <?php _e("any existing files with the same name will be overwritten", 'comicpress-manager') ?>
    <?php } else { ?>
      <?php _e("uploaded filenames will be obfuscated, therefore no old files will be overwritten after uploading", 'comicpress-manager') ?>
    <?php } ?>
  </h3>

    <?php if (!$zip_extension_loaded) { ?>
      <div id="zip-upload-warning">
        <?php printf(__('<strong>You do not have the Zip extension installed.</strong> Uploading a Zip file <strong>will not work</strong>. Either upload files individually or <a href="%s">FTP/SFTP the files to your site and import them</a>.'), "?page=" .  plugin_basename(realpath(dirname(__FILE__) . '/../comicpress_manager_admin.php') . '-import')) ?>
      </div>
    <?php } ?>

  <?php $target_url = add_query_arg("upload", "1") ?>

  <form onsubmit="$('submit').disabled=true" action="<?php echo $target_url ?>" method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="multiple-upload-file" />
    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo cpm_short_size_string_to_bytes(ini_get('upload_max_filesize')) ?>" />
    <div id="multiple-file-upload">
    </div>
    <div style="text-align: center">
      [<a href="#" onclick="add_file_upload(); return false"><?php _e("Add file to upload", 'comicpress-manager') ?></a>]
    </div>

    <table class="form-table">
      <tr>
        <th scope="row"><?php _e("Destination for uploaded files:", 'comicpress-manager') ?></th>
        <td>
          <select name="upload-destination" id="upload-destination">
            <option value="comic"><?php _e("Comics folder", 'comicpress-manager') ?></option>
            <option value="archive_comic"><?php _e("Archive folder", 'comicpress-manager') ?></option>
            <option value="rss_comic"><?php _e("RSS feed folder", 'comicpress-manager') ?></option>
          </select>
        </td>
      </tr>
      <?php if (count($cpm_config->comic_files) > 0) { ?>
        <tr id="overwrite-existing-holder">
          <th scope="row"><?php _e("Overwrite an existing file:", 'comicpress-manager') ?></th>
          <td>
            <select name="overwrite-existing-file-choice" id="overwrite-existing-file-choice">
              <option value=""><?php _e("-- no --", 'comicpress-manager') ?></option>
              <?php foreach ($cpm_config->comic_files as $file) {
                $basename = pathinfo($file, PATHINFO_BASENAME);
                ?>
                <option value="<?php echo $basename ?>"
                <?php echo ($_GET['replace'] == $basename) ? "selected" : "" ?>><?php echo $basename ?></option>
              <?php } ?>
            </select>
          </td>
        </tr>
        <tr id="rebuild-thumbnails">
          <th scope="row"><?php _e("Rebuild thumbnails?", 'comicpress-manager') ?></th>
          <td>
            <label>
              <input type="checkbox" id="replace-comic-rebuild-thumbnails" name="replace-comic-rebuild-thumbnails" value="yes" checked />
              <em>(if replacing a comic in the <strong>comic</strong> folder, you can also regenerate thumbnails)</em>
            </label>
          </td>
        </tr>
      <?php } ?>
      <tr>
        <td align="center" colspan="2">
          <input class="button" id="submit" type="submit" value="<?php
            if (extension_loaded("zip")) {
              _e("Upload Image &amp; Zip Files", 'comicpress-manager');
            } else {
              _e("Upload Image Files", 'comicpress-manager');
            }
          ?>" />
        </td>
      </tr>
    </table>

    <div id="upload-destination-holder">
      <table class="form-table">
        <tr>
          <th scope="row"><?php _e("Generate new posts for each uploaded file:", 'comicpress-manager') ?></th>
          <td>
            <input id="multiple-new-post-checkbox" type="checkbox" name="new_post" value="yes" checked />
            <label for="multiple-new-post-checkbox"><em>(if you only want to upload a series of files to replace others, leave this unchecked)</em></label>
          </td>
        </tr>
      </table>

      <div id="multiple-new-post-holder">
        <table class="form-table" id="specify-date-holder">
          <tr>
            <th scope="row"><?php _e("Date for uploaded file:", 'comicpress-manager') ?></th>
            <td>
              <div class="curtime"><input type="text" id="override-date" name="override-date" /> <?php _e("<em>(click to open calendar. for single file uploads only. can accept any date format parseable by <a href=\"http://us.php.net/strtotime\" target=\"php\">strtotime()</a>)</em>", 'comicpress-manager') ?></div>
            </td>
          </tr>
        </table>

        <?php cpm_post_editor(420) ?>

        <table class="form-table">
          <tr>
            <td align="center">
              <input class="button" id="top-submit" type="submit" value="<?php
                if (extension_loaded("zip")) {
                  _e("Upload Image &amp; Zip Files", 'comicpress-manager');
                } else {
                  _e("Upload Image Files", 'comicpress-manager');
                }
              ?>" />
            </td>
          </tr>
        </table>
      </div>
    </div>
  </form>
  <script type="text/javascript">
    Calendar.setup({
      inputField: "override-date",
      ifFormat: "%Y-%m-%d",
      button: "override-date"
    });
  </script>

  <?php

  $activity_content = ob_get_clean();

  cpm_wrap_content($help_content, $activity_content);
}
