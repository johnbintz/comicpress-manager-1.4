<?php

function cpm_action_multiple_upload_file() {
  global $cpm_config;

  if (strtotime($_POST['time']) === false) {
    $cpm_config->warnings[] = sprintf(__('<strong>There was an error in the post time (%1$s)</strong>.  The time is not parseable by strtotime().', 'comicpress-manager'), $_POST['time']);
  } else {
    $files_to_handle = array();

    foreach ($_FILES as $name => $info) {
      if (strpos($name, "upload-") !== false) {
        if (is_uploaded_file($_FILES[$name]['tmp_name'])) {
          $files_to_handle[] = $name;
        } else {
          switch ($_FILES[$name]['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
              $cpm_config->warnings[] = sprintf(__("<strong>The file %s was too large.</strong>  The max allowed filesize for uploads to your server is %s.", 'comicpress-manager'), $_FILES[$name]['name'], ini_get('upload_max_filesize'));
              break;
            case UPLOAD_ERR_NO_FILE:
              break;
            default:
              $cpm_config->warnings[] = sprintf(__("<strong>There was an error in uploading %s.</strong>  The <a href='http://php.net/manual/en/features.file-upload.errors.php'>PHP upload error code</a> was %s.", 'comicpress-manager'), $_FILES[$name]['name'], $_FILES[$name]['error']);
              break;
          }
        }
      }
    }

    if (count($files_to_handle) > 0) {
      cpm_handle_file_uploads($files_to_handle);

      $cpm_config->comic_files = cpm_read_comics_folder();
    } else {
      $cpm_config->warnings[] = __("<strong>You didn't upload any files!</strong>", 'comicpress-manager');
    }
  }
}

?>