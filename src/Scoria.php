<?php

declare(strict_types=1);

namespace Enxas;

class Scoria
{
	private $ffmpegPath = "";
	private $frameCount = 0;
	private $grayCharacters = "$@B%8&WM#*+=-:. "; // ASCII gradient from dark to light

	// Default encoding settings
	private $defaultSettings = [
		'framerate' => 24,
		'resolution' => '1600x900',
	];

	public function __construct(string $ffmpegPath, private int $widthSubsample = 16, private int $heightSubsample = 9)
	{
		if (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
			$this->ffmpegPath = $ffmpegPath;
		} else {
			echo "FFmpeg not found or not executable.";
		}
	}

	/**
	 * Get current time in milliseconds
	 */
	private static function time_ms(): float
	{
		return round(microtime(true) * 1000);
	}

	/**
	 * Encode to ASCII and play an ASCII art video file in the terminal
	 */
	public function encodeAndPlay(string $filename, array $settings = []): void
	{
		// Merge default settings with provided settings
		$settings = array_merge($this->defaultSettings, $settings);
		$frameDelay = (int)(1000000 / $settings['framerate']);
		$deltatime = self::time_ms();

		$frameCallback = function (string $asciiFrame) use (&$deltatime, $frameDelay) {
			$elapsed = (int) max(0, $frameDelay - (self::time_ms() - $deltatime));
			$this->playFrame($asciiFrame, $elapsed);
			$deltatime = self::time_ms();
		};

		// Process the video and save frames
		$this->processVideoFrames($filename, $frameCallback, $settings);
	}

	/**
	 * Decode and play an ASCII art video file in the terminal
	 */
	public function play(string $filename, int $fps = 24): void
	{
		$frames = $this->retrieveFramesFromFile($filename);
		$frameDelay = (int)(1000000 / $fps);

		echo "Playing {$filename} at {$fps} FPS...\n";
		sleep(2); // Give user a moment to prepare

		foreach ($frames as $frame) {
			$this->playFrame($frame, $frameDelay);
		}

		echo "\nPlayback complete.\n";
	}

	private function playFrame(string $frame, int $frameDelay): void
	{
		echo "\x1b[H" . $frame;  // Clear screen and display frame
		usleep($frameDelay);     // Wait appropriate time for framerate
	}

	/**
	 * Encode a video file to ASCII art format
	 */
	public function encode(string $inputFileName, string $outputFileName, array $settings = []): void
	{
		// Merge default settings with provided settings
		$settings = array_merge($this->defaultSettings, $settings);

		// Open output file
		$file = fopen($outputFileName, 'w');

		if (!$file) {
			throw new \Exception("Failed to open file for writing: {$outputFileName}");
		}

		$frameCallback = fn(string $asciiFrame) => $this->saveAsciiFrame($file, $asciiFrame);

		// Process the video and save frames
		$this->processVideoFrames($inputFileName, $frameCallback, $settings);

		fclose($file);

		echo "\nEncoding complete: {$this->frameCount} frames processed.\n";
	}

	/**
	 * Process video frames and convert to ASCII art
	 */
	private function processVideoFrames(string $videoPath, callable $frameCallback, array $settings): void
	{
		// Get FFmpeg command with hardware acceleration
		$cmd = $this->buildFfmpegCommand($videoPath, $settings);

		// Open process
		$process = proc_open($cmd, [
			0 => ["pipe", "r"],
			1 => ["pipe", "w"],
			2 => ["pipe", "w"]
		], $pipes);

		if (!is_resource($process)) {
			throw new \Exception("Failed to start FFmpeg");
		}

		// Process frames
		$this->processFrameStream($pipes, $process, $frameCallback);

		// Clean up
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		
		proc_close($process);
	}

	/**
	 * Build FFmpeg command with appropriate hardware acceleration
	 */
	private function buildFfmpegCommand(string $videoPath, array $settings): string
	{
		// Configure hardware acceleration based on OS
		$hwaccel = $this->getHardwareAcceleration();

		// Build FFmpeg command
		return "\"$this->ffmpegPath\" $hwaccel -i " . escapeshellarg($videoPath) .
			" -vf \"fps={$settings['framerate']},scale={$settings['resolution']},format=gray\" " .
			" -fflags nobuffer -loglevel error -flush_packets 1 -update 1 " .
			" -f image2pipe -pix_fmt gray -vcodec png -";
	}

	/**
	 * Get hardware acceleration parameters based on OS
	 */
	private function getHardwareAcceleration(): string
	{
		return match (true) {
			stristr(PHP_OS, 'linux') => " -hwaccel vaapi -vaapi_device /dev/dri/renderD128 ",
			stristr(PHP_OS, 'darwin') => " -hwaccel videotoolbox ",
			stristr(PHP_OS, 'win') => " -hwaccel dxva2 ",
			default => "",
		};
	}

	/**
	 * Process the stream of frames from FFmpeg
	 */
	private function processFrameStream(array $pipes, mixed $process, callable $frameCallback): void
	{
		// Set up for PNG processing
		$buffer = '';
		$this->frameCount = 0;

		stream_set_blocking($pipes[1], false); // STDOUT stream
		stream_set_blocking($pipes[2], false); // STDERR stream

		// Process each frame
		while (true) {
			$status = proc_get_status($process);
			if (!$status['running'] && feof($pipes[1])) {
				break;
			}

			// Read data from FFmpeg output
			if (($chunk = fread($pipes[1], 4096)) !== false && $chunk !== '') {
				$buffer .= $chunk;
			}

			// Process complete PNG images in the buffer
			$buffer = $this->processFrames($buffer, $frameCallback);
		}
	}

	/**
	 * Process complete PNG frames in the buffer
	 */
	private function processFrames(string $buffer, callable $frameCallback): string
	{
		$pngSignature = "\x89PNG\r\n\x1a\n";
		$iendSignature = "IEND\xAE\x42\x60\x82";

		while (($pngStart = strpos($buffer, $pngSignature)) !== false) {
			// Trim anything before the PNG header
			$buffer = substr($buffer, $pngStart);

			// Look for IEND chunk (end of PNG file)
			$iendPos = strpos($buffer, $iendSignature);
			if ($iendPos === false) {
				break; // IEND not found, need more data
			}

			// Extract complete PNG
			$frameData = substr($buffer, 0, $iendPos + 8);
			$buffer = substr($buffer, $iendPos + 8); // Remove processed PNG

			// Convert frame to ASCII
			$asciiFrame = $this->convertFrameToAscii($frameData);

			// Call frame callback
			$frameCallback($asciiFrame);
		}

		return $buffer;
	}

	/**
	 * Convert a PNG frame to ASCII art
	 */
	private function convertFrameToAscii(string $frameData): string
	{
		// Convert to GD image
		$image = @imagecreatefromstring($frameData);
		if (!$image) {
			throw new \Exception("Failed to create image from frame data");
		}

		// Get dimensions and calculate subsampling
		$width = imagesx($image);
		$height = imagesy($image);
		$newWidth = (int)ceil($width / $this->heightSubsample);
		$newHeight = (int)ceil($height / $this->widthSubsample);

		// Convert to ASCII art
		$asciiFrame = $this->imageToAscii($image, $newWidth, $newHeight, $width, $height);

		// Free memory
		imagedestroy($image);

		return $asciiFrame;
	}

	/**
	 * Convert an image to ASCII art
	 */
	private function imageToAscii(\GdImage $image, int $newWidth, int $newHeight, int $width, int $height): string
	{
		$asciiFrame = [];

		for ($y = 0; $y < $newHeight; $y++) {
			$row = '';
			$baseY = $y * $this->widthSubsample;

			for ($x = 0; $x < $newWidth; $x++) {
				$baseX = $x * $this->heightSubsample;

				// Sample center pixel for each block
				$sampleX = min($baseX + intdiv($this->heightSubsample, 2), $width - 1);
				$sampleY = min($baseY + intdiv($this->widthSubsample, 2), $height - 1);

				$grayValue = imagecolorat($image, $sampleX, $sampleY);
				// Map color value to ASCII character
				$index = (int) ceil((strlen($this->grayCharacters) - 1) * $grayValue / 255);
				$row .= $this->grayCharacters[$index];
			}

			$asciiFrame[] = $row;
		}

		// Join rows with newlines
		return implode(PHP_EOL, $asciiFrame);
	}

	/**
	 * Compress and save an ASCII frame to the output file
	 */
	private function saveAsciiFrame(mixed $file, string $asciiFrame): void
	{
		$compressed = gzcompress($asciiFrame);
		fwrite($file, base64_encode($compressed) . "\n");

		// Show progress
		$this->frameCount++;
		echo "\rProcessed frame: " . $this->frameCount;
	}

	/**
	 * Retrieve frames from an ASCII movie file
	 */
	private function retrieveFramesFromFile(string $filename): array
	{
		$frames = [];
		$file = fopen($filename, 'r');
		if (!$file) {
			throw new \Exception("Failed to open file for reading: {$filename}");
		}

		while (($line = fgets($file)) !== false) {
			$frames[] = gzuncompress(base64_decode(trim($line)));
		}

		fclose($file);

		return $frames;
	}
}
