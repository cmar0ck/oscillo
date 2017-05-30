 <?php
/*
 * Etch A Sketch!
 * Copyright (c) 2012, Alex Duchesne <alex@alexou.net>.
 * Copyright (c) 2014, Chris <emotionalslavery@gmail.com>.
 * This file is subject to the ISC license. In short you can do 
 * what ever you want, so long that you keep my name up there.
 *
 * Changes from oscillo.php:
 *
 * - Now accepts folders of mixed gif and png
 *
 * - Now throws file missing error *before* processing
 *
 * - Now traces the image by looking for the closest next pixel, 
 *   instead of doing each columns of pixel sequentially.
 *  
 * - Fixed the freezing issue with fritzkatz_reencoded.gif
 *   It was caused by empty frame (zero pixel) and both loop_frames() and
 *   create_wav_samples_from_picture() looped endlessly...
 */

 
set_time_limit(0);
require 'GifFrameExtractor.php';


$WavSamplingRate = 48000;
$WavChannels = 2; // Stereo, X and Y.
$WavBytes = 1; // Amplitude = 0 - 255
$BytePack = array(1 => 'C', 2 => 'v', 4 => 'V');
$Multiplicator = 2;

$ResMax = pow(2, $WavBytes * 8) / $Multiplicator;

/**
 * The following functions could be made a lot more efficient. 
 * The reason they are split like that was to support realtime output.
 */

 
/**
 * Create amplitude map (x(right)-y(left)) from picture resource
 *
 * @param img $frame: A picture resource. The script will use only black pixels (RGB 0,0,0)
 * @return array
 */ 
function create_wav_samples_from_picture($frame, $BgColor = 255){

    global $WavBytes, $BytePack, $Multiplicator, $ResMax;

    $FrameX = imagesx($frame);
    $FrameY = imagesy($frame);
    
    if ($FrameX != $FrameY || $FrameX != $ResMax || $FrameY != $ResMax){
        throw new exception("Invalid image size, required resolution: ({$ResMax}x{$ResMax})...");
    }

    $samples = array();

    $pixel_map = array();
    $pixel_done = 0;
    $pixel_count = 0;
    
    for ($x = 0; $x < $ResMax; $x++)
    {
        for ($y = 0; $y < $ResMax; $y++)
        {
            if (imagecolorat($frame, $x, $y) !== $BgColor) {
                $pixel_map[$x][$y] = 1;
                $pixel_count++;
            }
        }
    }
    
    if ($pixel_count == 0) //Create empty samples so we can loop_frames later and create an actual delay
    {
        $samples[] = pack($BytePack[$WavBytes], 0); // L
        $samples[] = pack($BytePack[$WavBytes], 0); // R 
    }
    
    $x = 0;
    $y = 0;
    
    while ($pixel_done < $pixel_count)
    {
        if (!isset($pixel_map[$x][$y])) {
            reset($pixel_map);
            $x = key($pixel_map);
            if (!empty($pixel_map[$x])) {
                reset($pixel_map[$x]);
                $y = key($pixel_map[$x]);
            } else {
                unset($pixel_map[$x]);
                continue;
            }
        }
        
        while (isset($pixel_map[$x][$y]))
        {
            $radius = 3;
            for ($subX = ($x - $radius) > 0 ? $x - $radius : 0; $subX < $x+$radius && $subX < $ResMax; $subX++)
            {
                for ($subY = ($y - $radius) > 0 ? $y - $radius : 0; $subY < $y+$radius && $subY < $ResMax; $subY++)
                {
                    if (isset($pixel_map[$subX][$subY])) {
                        $samples[] = pack($BytePack[$WavBytes], $subX * $Multiplicator); // L
                        $samples[] = pack($BytePack[$WavBytes], ($ResMax - $subY) * $Multiplicator); // R
                        unset($pixel_map[$subX][$subY]);
                        $pixel_done++;
                    }
                }
            }
            
            
            unset($pixel_map[$x][$y]);
            $pixel_done++;
            
            for ($radius = 1; $radius <= 15; $radius++) {
                for ($x = $subX - $radius; $x < $ResMax && $x <= $subX + $radius; $x++) {
                    for ($y = $subY - $radius; $y < $ResMax && $y <= $subY + $radius; $y++) {
                        if (isset($pixel_map[$x][$y])) {
                            break 3;
                        }
                    }
                }
            }
            // if ($subY >= $ResMax || $subX >= $ResMax) {
                // break;
            // }
        }

    }
    
    static $count = 0;
    echo 'Done frame '.$count++."\n";
    
    return $samples;
}

/**
 * Create a WAV file (type: RIFF) from amplitude map.
 * (Code will produce a .wav file with the following specs: WAV PCM 8bits stereo 48kHz.)
 *
 * @param string $file : File path for output wav.
 * @param string $samples : A string containing the audio information (an array occupies too much memory).
 * @return boolean
 */
function create_wav_from_samples($file, $samples)
{
    global $WavSamplingRate, $WavChannels, $WavBytes;

    if ($file !== '/dev/dsp' && file_exists($file))
    {
        throw new exception("The file {$file} already exists!");
    }

    if (!($file = fopen($file, 'w')))
    {
        throw new exception("Can't open {$file} for writing");
    }

    $fmt = "fmt " .
        pack("V", 16) .
        pack("v", 1) .
        pack("v", $WavChannels) .
        pack("V", $WavSamplingRate) .
        pack("V", $WavSamplingRate * $WavChannels * $WavBytes) .
        pack("v", $WavChannels * $WavBytes) .
        pack("v", $WavBytes * 8);

    $sound = "data" .
        pack("V", strlen($samples)) .
        $samples;

    $header = "RIFF" .
        pack("V", 4 + (8 + strlen($fmt)) + (8 + strlen($sound))) .
        "WAVE";

    return fwrite($file, $header . $fmt . $sound) && fclose($file);
}

/**
 * Will loop the frames enough to cover $miliseconds (or a bit more).
 *
 * @param string $frames : Frames.
 * @param int $miliseconds :
 * @return array
 */
function loop_frames($frames, $miliseconds)
{
    global $WavSamplingRate, $WavChannels;

    if (empty($frames))
    {
        return '';
    }
    
    if (is_array($frames))
    {
        $frames = implode($frames);
    }
    
    $wavSamples = $frames;

    while (strlen($wavSamples) < $WavSamplingRate * $WavChannels * $miliseconds / 1000)
    {
        $wavSamples .= $frames;
    }

    return $wavSamples;
}




try {
    if (PHP_SAPI !== 'cli')
    {    
        echo '<pre>';

        if (!empty($_FILES['inputfile']))
        {
            @unlink('output.wav');
            $argv = array('', $_FILES['inputfile']['tmp_name'], 'output.wav');
            $argc = count($argv);
        }
        else
        {
            echo '<form method="post" enctype="multipart/form-data">GIF File: <input type="file" name="inputfile"><input type="submit"></form>';
            $argc = 0;
        }
    }
    
    
    if ($argc < 2)
    {
        throw new exception ("No input file specified.\n\n".
                             "Usage: php oscillo.php inputfile outputfile\n\n".
                             "       Supported input formats are GIF and PNG images.\n".
                             "       inputfile can be a directory containing PNG files.\n".
                             "       Output will be a 48khz 8bit stereo WAV file. \n".
                             "       If your OS supports it (Cygwin/Linux), you can output to /dev/dsp for realtime playback.");
    }


    $inputFile = $argv[1];
    $outputFile = 'output.wav';

    if ($argc == 3) {
        $outputFile = $argv[2];
    }

    if (!file_exists($inputFile))
    {
        throw new exception("Input file $inputFile not found!");
    }

    if ($outputFile !== '/dev/dsp' && file_exists($outputFile)) // Better test before processing :)
    {
        throw new exception("Output file $outputFile exists!");
    }

    
    $soundData = '';

    
    if (is_dir($inputFile))
    {
        $inputFiles = glob($inputFile.'/*.{png,gif}', GLOB_BRACE);
        $pngDuration = 500;
    }
    else
    {
        $inputFiles = array($inputFile);
        $pngDuration = 10000;
    }
    
    
    foreach($inputFiles as $file)
    {
        if (GifFrameExtractor::isAnimatedGif($file))
        {
            echo "Processing GIF ($file) ... \n";
            $gfe = new GifFrameExtractor();
            $gfe->extract($file);
            foreach ($gfe->getFrames() as $frame)
            {
                 // According to http://nullsleep.tumblr.com/post/16524517190/animated-gif-minimum-frame-delay-browser
                 // anything below 100ms is usually rounded to 100ms
                $frame['duration'] >= 10 or $frame['duration'] = 10;
                $soundData .= loop_frames(create_wav_samples_from_picture($frame['image'], imagecolorat($frame['image'], 1, 1)), $frame['duration'] * 10);
            }
        }
        else
        {
            echo "Processing PNG ($file) ... \n";
            $frame = imagecreatefrompng($file);
            $soundData .= loop_frames(create_wav_samples_from_picture($frame, imagecolorat($frame, 1, 1)), $pngDuration);
        }
    }
    
    create_wav_from_samples($outputFile, $soundData);

    echo "Done!\n";
    
    if (PHP_SAPI !== 'cli') {
        echo '<a href="output.wav">Download file</a>';
    }
}
catch (exception $e)
{
    echo $e->getMessage() . "\n";
} 
