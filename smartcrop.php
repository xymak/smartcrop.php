<?php

namespace xymak\image;

class smartcrop {
	public $defaultOptions = [ 
			'width' => 0,
			'height' => 0,
			'aspect' => 0,
			'cropWidth' => 0,
			'cropHeight' => 0,
			'detailWeight' => 0.2,
			'skinColor' => [ 
					0.78,
					0.57,
					0.44 
			],
			'skinBias' => 0.01,
			'skinBrightnessMin' => 0.2,
			'skinBrightnessMax' => 1.0,
			'skinThreshold' => 0.8,
			'skinWeight' => 1.8,
			'saturationBrightnessMin' => 0.05,
			'saturationBrightnessMax' => 0.9,
			'saturationThreshold' => 0.4,
			'saturationBias' => 0.2,
			'saturationWeight' => 0.3,
			'scoreDownSample' => 8,
			'step' => 8,
			'scaleStep' => 0.1,
			'minScale' => 1.0,
			'maxScale' => 1.0,
			'edgeRadius' => 0.4,
			'edgeWeight' => - 20.0,
			'outsideImportance' => - 0.5,
			'boostWeight' => 100.0,
			'ruleOfThirds' => true,
			'prescale' => true,
			'imageOperations' => null,
			'canvasFactory' => 'defaultCanvasFactory',
			'debug' => true 
	];
	public $options = [ ];
	public $inputImage;
	public $scale;
	public $prescale;
	public $oImg;
	public $od = [ ];
	public $aSample = [ ];
	public $h = 0;
	public $w = 0;
	public function __construct($inputImage, $options) {
		$this->options = array_merge ( $this->defaultOptions, $options );
		$this->inputImage = $inputImage;
		
		if ($this->options ['aspect']) {
			$this->options ['width'] = $this->options ['aspect'];
			$this->options ['height'] = 1;
		}
		
		$this->scale = 1;
		$this->prescale = 1;
		
		$this->canvasImageOpen ( $inputImage );
		$this->canvasImageScale ();
		
		return $this;
	}
	/**
	 * Convert a image to gd resource
	 * 
	 * @param string $image
	 * @return \xymak\image\smartcrop
	 */
	public function canvasImageOpen($image) {
		$this->oImg = imagecreatefromstring ( file_get_contents ( $image ) );
		return $this;
	}
	/**
	 * Scale the image before smartcrop analyse
	 * 
	 * @return \xymak\image\smartcrop
	 */
	public function canvasImageScale() {
		$imageOriginalWidth = imagesx ( $this->oImg );
		$imageOriginalHeight = imagesy ( $this->oImg );
		$scale = min ( $imageOriginalWidth / $this->options ['width'], $imageOriginalHeight / $this->options ['height'] );
		
		$this->options ['cropWidth'] = ceil ( $this->options ['width'] * $scale );
		$this->options ['cropHeight'] = ceil ( $this->options ['height'] * $scale );
		
		$this->options ['minScale'] = min ( $this->options ['maxScale'], max ( 1 / $scale, $this->options ['minScale'] ) );
		
		if ($this->options ['prescale'] !== false) {
			$this->preScale = 1 / $scale / $this->options ['minScale'];
			if ($this->preScale < 1) {
				$this->canvasImageResample ( ceil ( $imageOriginalWidth * $this->preScale ), ceil ( $imageOriginalHeight * $this->preScale ) );
				$this->options ['cropWidth'] = ceil ( $this->options ['cropWidth'] * $this->preScale );
				$this->options ['cropHeight'] = ceil ( $this->options ['cropHeight'] * $this->preScale );
			} else {
				$this->preScale = 1;
			}
		}
		
		return $this;
	}
	/**
	 * Function for scale image
	 * 
	 * @param integer $width
	 * @param integer $height
	 * @return \xymak\image\smartcrop
	 */
	public function canvasImageResample($width, $height) {
		$oCanvas = imagecreatetruecolor ( $width, $height );
		imagecopyresampled ( $oCanvas, $this->oImg, 0, 0, 0, 0, $width, $height, imagesx ( $this->oImg ), imagesy ( $this->oImg ) );
		$this->oImg = $oCanvas;
		return $this;
	}
	/**
	 * Analyse the image, find out the optimal crop scheme
	 * 
	 * @return array
	 */
	public function analyse() {
		$result = [ ];
		$w = $this->w = imagesx ( $this->oImg );
		$h = $this->h = imagesy ( $this->oImg );
		
		$this->od = new \SplFixedArray ( $h * $w * 3 );
		$this->aSample = new \SplFixedArray ( $h * $w );
		for($y = 0; $y < $h; $y ++) {
			for($x = 0; $x < $w; $x ++) {
				$p = ($y) * $this->w * 3 + ($x) * 3;
				$aRgb = $this->getRgbColorAt ( $x, $y );
				$this->od [$p + 1] = $this->edgeDetect ( $x, $y, $w, $h );
				$this->od [$p] = $this->skinDetect ( $aRgb [0], $aRgb [1], $aRgb [2], $this->sample ( $x, $y ) );
				$this->od [$p + 2] = $this->saturationDetect ( $aRgb [0], $aRgb [1], $aRgb [2], $this->sample ( $x, $y ) );
			}
		}
		
		$scoreOutput = $this->downSample ( $this->options ['scoreDownSample'] );
		$topScore = - INF;
		$topCrop = null;
		$crops = $this->generateCrops ();
		
		foreach ( $crops as &$crop ) {
			$crop ['score'] = $this->score ( $scoreOutput, $crop );
			if ($crop ['score'] ['total'] > $topScore) {
				$topCrop = $crop;
				$topScore = $crop ['score'] ['total'];
			}
		}
		
		$result ['topCrop'] = $topCrop;
		
		if ($this->options ['debug'] && $topCrop) {
			$result ['crops'] = $crops;
			$result ['debugOutput'] = $scoreOutput;
			$result ['debugOptions'] = $this->options;
			$result ['debugTopCrop'] = array_merge ( [ ], $result ['topCrop'] );
		}
		
		return $result;
	}
	/**
	 * @param int $factor
	 * @return \SplFixedArray
	 */
	public function downSample($factor) {
		$width = floor ( $this->w / $factor );
		$height = floor ( $this->h / $factor );
		
		$ifactor2 = 1 / ($factor * $factor);
		
		$data = new \SplFixedArray ( $height * $width * 4 );
		for($y = 0; $y < $height; $y ++) {
			for($x = 0; $x < $width; $x ++) {
				$r = 0;
				$g = 0;
				$b = 0;
				$a = 0;
				
				$mr = 0;
				$mg = 0;
				$mb = 0;
				
				for($v = 0; $v < $factor; $v ++) {
					for($u = 0; $u < $factor; $u ++) {
						$p = ($y * $factor + $v) * $this->w * 3 + ($x * $factor + $u) * 3;
						$pR = $this->od [$p];
						$pG = $this->od [$p + 1];
						$pB = $this->od [$p + 2];
						$pA = 0;
						$r += $pR;
						$g += $pG;
						$b += $pB;
						$a += $pA;
						$mr = max ( $mr, $pR );
						$mg = max ( $mg, $pG );
						$mb = max ( $mb, $pB );
					}
				}
				
				$p = ($y) * $width * 4 + ($x) * 4;
				$data [$p] = round ( $r * $ifactor2 * 0.5 + $mr * 0.5, 0, PHP_ROUND_HALF_EVEN );
				$data [$p + 1] = round ( $g * $ifactor2 * 0.7 + $mg * 0.3, 0, PHP_ROUND_HALF_EVEN );
				$data [$p + 2] = round ( $b * $ifactor2, 0, PHP_ROUND_HALF_EVEN );
				$data [$p + 3] = round ( $a * $ifactor2, 0, PHP_ROUND_HALF_EVEN );
			}
		}
		
		return $data;
	}
	/**
	 * @param integer $x
	 * @param integer $y
	 * @param integer $w
	 * @param integer $h
	 * @return integer
	 */
	public function edgeDetect($x, $y, $w, $h) {
		if ($x === 0 || $x >= $w - 1 || $y === 0 || $y >= $h - 1) {
			$lightness = $this->sample ( $x, $y );
		} else {
			$leftLightness = $this->sample ( $x - 1, $y );
			$centerLightness = $this->sample ( $x, $y );
			$rightLightness = $this->sample ( $x + 1, $y );
			$topLightness = $this->sample ( $x, $y - 1 );
			$bottomLightness = $this->sample ( $x, $y + 1 );
			$lightness = $centerLightness * 4 - $leftLightness - $rightLightness - $topLightness - $bottomLightness;
		}
		return round ( $lightness, 0, PHP_ROUND_HALF_EVEN );
	}
	/**
	 * @param integer $r
	 * @param integer $g
	 * @param integer $b
	 * @param float $lightness
	 * @return integer
	 */
	public function skinDetect($r, $g, $b, $lightness) {
		$lightness = $lightness / 255;
		$skin = $this->skinColor ( $r, $g, $b );
		$isSkinColor = $skin > $this->options ['skinThreshold'];
		$isSkinBrightness = $lightness > $this->options ['skinBrightnessMin'] && $lightness <= $this->options ['skinBrightnessMax'];
		if ($isSkinColor && $isSkinBrightness) {
			return round ( ($skin - $this->options ['skinThreshold']) * (255 / (1 - $this->options ['skinThreshold'])), 0, PHP_ROUND_HALF_EVEN );
		} else {
			return 0;
		}
	}
	/**
	 * @param integer $r
	 * @param integer $g
	 * @param integer $b
	 * @param integer $lightness
	 * @return integer
	 */
	public function saturationDetect($r, $g, $b, $lightness) {
		$lightness = $lightness / 255;
		$sat = $this->saturation ( $r, $g, $b );
		$acceptableSaturation = $sat > $this->options ['saturationThreshold'];
		$acceptableLightness = $lightness >= $this->options ['saturationBrightnessMin'] && $lightness <= $this->options ['saturationBrightnessMax'];
		if ($acceptableLightness && $acceptableSaturation) {
			return round ( ($sat - $this->options ['saturationThreshold']) * (255 / (1 - $this->options ['saturationThreshold'])), 0, PHP_ROUND_HALF_EVEN );
		} else {
			return 0;
		}
	}
	/**
	 * Generate crop schemes
	 * 
	 * @return array
	 */
	public function generateCrops() {
		$w = imagesx ( $this->oImg );
		$h = imagesy ( $this->oImg );
		$results = [ ];
		$minDimension = min ( $w, $h );
		$cropWidth = empty ( $this->options ['cropWidth'] ) ? $minDimension : $this->options ['cropWidth'];
		$cropHeight = empty ( $this->options ['cropHeight'] ) ? $minDimension : $this->options ['cropHeight'];
		for($scale = $this->options ['maxScale']; $scale >= $this->options ['minScale']; $scale -= $this->options ['scaleStep']) {
			for($y = 0; $y + $cropHeight * $scale <= $h; $y += $this->options ['step']) {
				for($x = 0; $x + $cropWidth * $scale <= $w; $x += $this->options ['step']) {
					$results [] = [ 
							'x' => $x,
							'y' => $y,
							'width' => $cropWidth * $scale,
							'height' => $cropHeight * $scale 
					];
				}
			}
		}
		
		return $results;
	}
	/**
	 * Score a crop scheme
	 * 
	 * @param array $output
	 * @param array $crop
	 * @return array
	 */
	public function score($output, $crop) {
		$result = [ 
				'detail' => 0,
				'saturation' => 0,
				'skin' => 0,
				'boost' => 0,
				'total' => 0 
		];
		
		$downSample = $this->options ['scoreDownSample'];
		$invDownSample = 1 / $downSample;
		$outputHeightDownSample = floor ( $this->h / $downSample ) * $downSample;
		$outputWidthDownSample = floor ( $this->w / $downSample ) * $downSample;
		$outputWidth = floor ( $this->w / $downSample );
		
		for($y = 0; $y < $outputHeightDownSample; $y += $downSample) {
			for($x = 0; $x < $outputWidthDownSample; $x += $downSample) {
				$i = $this->importance ( $crop, $x, $y );
				$p = floor ( $y / $downSample ) * $outputWidth * 4 + floor ( $x / $downSample ) * 4;
				$detail = $output [$p + 1] / 255;
				
				$result ['skin'] += $output [$p] / 255 * ($detail + $this->options ['skinBias']) * $i;
				$result ['saturation'] += $output [$p + 2] / 255 * ($detail + $this->options ['saturationBias']) * $i;
				$result ['detail'] = $p;
			}
		}
		
		$result ['total'] = ($result ['detail'] * $this->options ['detailWeight'] + $result ['skin'] * $this->options ['skinWeight'] + $result ['saturation'] * $this->options ['saturationWeight'] + $result ['boost'] * $this->options ['boostWeight']) / ($crop ['width'] * $crop ['height']);
		
		return $result;
	}
	/**
	 * @param array $crop
	 * @param integer $x
	 * @param integer $y
	 * @return float|number
	 */
	public function importance($crop, $x, $y) {
		if ($crop ['x'] > $x || $x >= $crop ['x'] + $crop ['width'] || $crop ['y'] > $y || $y > $crop ['y'] + $crop ['height']) {
			return $this->options ['outsideImportance'];
		}
		$x = ($x - $crop ['x']) / $crop ['width'];
		$y = ($y - $crop ['y']) / $crop ['height'];
		$px = abs ( 0.5 - $x ) * 2;
		$py = abs ( 0.5 - $y ) * 2;
		$dx = max ( $px - 1.0 + $this->options ['edgeRadius'], 0 );
		$dy = max ( $py - 1.0 + $this->options ['edgeRadius'], 0 );
		$d = ($dx * $dx + $dy * $dy) * $this->options ['edgeWeight'];
		$s = 1.41 - sqrt ( $px * $px + $py * $py );
		if ($this->options ['ruleOfThirds']) {
			$s += (max ( 0, $s + $d + 0.5 ) * 1.2) * ($this->thirds ( $px ) + $this->thirds ( $py ));
		}
		return $s + $d;
	}
	/**
	 * @param integer $x
	 * @return float
	 */
	function thirds($x) {
		$x = (($x - (1 / 3) + 1.0) % 2.0 * 0.5 - 0.5) * 16;
		return max ( 1.0 - $x * $x, 0.0 );
	}
	/**
	 * @param integer $x
	 * @param integer $y
	 * @return float
	 */
	public function sample($x, $y) {
		$p = $y * $this->w + $x;
		if (isset ( $this->aSample [$p] )) {
			return $this->aSample [$p];
		} else {
			$aRgbColor = $this->getRgbColorAt ( $x, $y );
			$this->aSample [$p] = $this->cie ( $aRgbColor [0], $aRgbColor [1], $aRgbColor [2] );
			return $this->aSample [$p];
		}
	}
	/**
	 * @param integer $x
	 * @param integer $y
	 * @return float
	 */
	public function getRgbColorAt($x, $y) {
		$rgb = imagecolorat ( $this->oImg, $x, $y );
		return [ 
				$rgb >> 16,
				$rgb >> 8 & 255,
				$rgb & 255 
		];
	}
	/**
	 * @param integer $r
	 * @param integer $g
	 * @param integer $b
	 * @return float
	 */
	public function cie($r, $g, $b) {
		return 0.5126 * $b + 0.7152 * $g + 0.0722 * $r;
	}
	/**
	 * @param integer $r
	 * @param integer $g
	 * @param integer $b
	 * @return float
	 */
	public function skinColor($r, $g, $b) {
		$mag = sqrt ( $r * $r + $g * $g + $b * $b );
		$mag = $mag > 0 ? $mag : 1;
		$rd = ($r / $mag - $this->options ['skinColor'] [0]);
		$gd = ($g / $mag - $this->options ['skinColor'] [1]);
		$bd = ($b / $mag - $this->options ['skinColor'] [2]);
		$d = sqrt ( $rd * $rd + $gd * $gd + $bd * $bd );
		return 1 - $d;
	}
	/**
	 * @param integer $r
	 * @param integer $g
	 * @param integer $b
	 * @return float
	 */
	public function saturation($r, $g, $b) {
		$maximum = max ( $r / 255, $g / 255, $b / 255 );
		$minumum = min ( $r / 255, $g / 255, $b / 255 );
		
		if ($maximum === $minumum) {
			return 0;
		}
		
		$l = ($maximum + $minumum) / 2;
		$d = ($maximum - $minumum);
		
		return $l > 0.5 ? $d / (2 - $maximum - $minumum) : $d / ($maximum + $minumum);
	}
	/**
	 * Crop image
	 * 
	 * @param integer $x
	 * @param integer $y
	 * @param integer $width
	 * @param integer $height
	 * @return \xymak\image\smartcrop
	 */
	public function crop($x, $y, $width, $height) {
		$oCanvas = imagecreatetruecolor ( $width, $height );
		imagecopyresampled ( $oCanvas, $this->oImg, 0, 0, $x, $y, $width, $height, $width, $height );
		$this->oImg = $oCanvas;
		return $this;
	}
	/**
	 * Output a image
	 */
	public function output() {
		header ( "Content-Type: image/jpeg" );
		imagejpeg ( $this->oImg );
	}
}