## About

Scoria is a PHP library that allows you to encode video files into ASCII art, save the recording to a file and play it in the terminal, or encode and play directly without saving.

## Installation

Add these values to your `composer.json` then run `composer update`.

```json
"repositories": [
	{
		"type": "vcs",
		"url": "https://github.com/enxas/scoria"
	}
],
"require": {
	"enxas/scoria": "dev-main"
}
```

Ensure that [FFmpeg](https://www.ffmpeg.org/) is installed on your system..

## Usage

Example usage:

```php
require_once __DIR__ . '/vendor/autoload.php';

use Enxas\Scoria;

$inputFilePath = "./big_buck_bunny_1080p_h264.mov";
$outputFilePath = "./ascii_video.amov";

$scoria = new Scoria(ffmpegPath: "C:\\tools\\ffmpeg.exe");

// Convert video and play it back (slower)
$scoria->encodeAndPlay($inputFilePath);

// OR

// Convert video to ASCII art and save converted video (faster)
$scoria->encode($inputFilePath, $outputFilePath);

// Then play back the ASCII art video
$scoria->play($outputFilePath);
```
