<?php

$file = "1990-01-01-sample-comic-cmyk.jpeg";
$target = "1990-01-01-sample-comic-from-cmyk.jpeg";

var_dump(getimagesize($file));

$cmyk_data = imagecreatefromjpeg($file);

imagejpeg($cmyk_data, $target, 80);

var_dump(getimagesize($target));

?>