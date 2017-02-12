# php-smartcrop-extension

smartcrop implementation in PHP.

smartcrop finds optimal crops for images, based on Jonas Wagner's [smartcrop.js](https://github.com/jwagner/smartcrop.js).

![Example](./example.png)

## Installation

Make sure you have PHP environment.

Additionally PHP GD extension is needed to be load into PHP.

Copy smartcrop.php to your directory
## Example 
```
<?php
require(__DIR__ . '/smartcrop.php');
use xymak\image;
//Get a instance
$smartcrop = new xymak\image\smartcrop('/path/to/a/image',[
        'width' => 400,
        'height' => 400
] );
//Analyse the image and get the optimal crop scheme
$res = $smartcrop->analyse();
//Generate a crop based on optimal crop scheme
$smartcrop->crop($res['topCrop']['x'], $res['topCrop']['y'], $res['topCrop']['width'], $res['topCrop']['height']);
//Output the image
$smartcrop->output();
```
