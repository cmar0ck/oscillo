 <?php
/*
 * Etch A Sketch!
 * Copyright (c) 2012, Alex Duchesne <alex@alexou.net>.
 * Copyright (c) 2014, Chris <emotionalslavery@gmail.com>.
 * This file is subject to the ISC license. In short you can do 
 * what ever you want, so long that you keep my name up there.
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

    $done = array();
    $done_count = 0;
    $pixels = pow($ResMax, 2);
    
    $x = 0;
    $y = 0;
    
    while ($done_count <= $pixels)
    {
        if (isset($done[$x][$y]) || $x >= $ResMax || $y >= $ResMax) {
            for ($x = 0; $x < $ResMax; $x++) { // I believe we should start at the old position and wrap around instead
                for ($y = 0; $y < $ResMax; $y++) {
                    if (!isset($done[$x][$y])) {
                        break 2;
                    }
                }
            }
        }
        
        // if ($x >= $ResMax) {
            // $x = 0;
        // }
        // if ($y >= $ResMax) {
            // $y = 0;
        // }
        
        // if (isset($done[$x][$y])) {
            // echo "a $x $y\n";
            // for (; $x < $ResMax; $x++) {
                // for (; $y < $ResMax; $y++) {
                    // echo "c $x $y\n";
                    // if (!isset($done[$x][$y])) {
                        // echo "x $x.$y\n";
                        // break 2;
                    // }
                // }
            // }
            // echo "b $x $y\n";
            // if ($x >= $ResMax || $y >= $ResMax) {
                // $x = $y = 0;
                // continue;
            // }
        // }

        $done[$x][$y] = true; //seems to be faster than using $x.'x'.$y
        $done_count++;

        while (imagecolorat($frame, $x, $y) !== $BgColor)
        {
            for ($subX = ($x - 3) > 0 ? $x - 3 : 0; $subX < $x+3 && $subX < $ResMax; $subX++)
            {
                for ($subY = ($y - 3) > 0 ? $y - 3 : 0; $subY < $y+3 && $subY < $ResMax; $subY++)
                {
                    if (!isset($done[$subX][$subY]) && imagecolorat($frame, $subX, $subY) !== $BgColor) {
                        $samples[] = pack($BytePack[$WavBytes], $subX * $Multiplicator); // L
                        $samples[] = pack($BytePack[$WavBytes], ($ResMax - $subY) * $Multiplicator); // R
                    }
                    $done[$subX][$subY] = true;
                    $done_count++;
                }
            }
            
            if ($subX + 1 >= $ResMax) {
                $x = $subX - 1;
                $y = $subY;
            } elseif ($subY + 1 >= $ResMax) {
                $x = $subX;
                $y = $subY - 1;
            } else {
                $x = $subX - 1;
                $y = $subY - 1;
            }
            
            if ($subY >= $ResMax || $subX >= $ResMax) {
                break;
            }
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


/* OUTPUT TO LINUX SOUNDCARD DEVICE
-----------------------------------
$fp = fopen('/dev/dsp', 'w');

$fmt = "fmt ".
        pack("V", 16).
        pack("v", 1).
        pack("v", $WavChannels).
        pack("V", $WavSamplingRate).
        pack("V", $WavSamplingRate * $WavChannels * $WavBytes).
        pack("v", $WavChannels * $WavBytes).
        pack("v", $WavBytes*8);

$sound = "data".
         pack("V", 4294967295).
         $sound;

$header = "RIFF".
          pack("V", 4 + (8 + strlen($fmt)) + (8 + strlen($sound))).
          "WAVE";

fwrite($fp, $header.$fmt.$sound);


while (1) {
    if ($frame = imagecreatefrompng('test.png')) {
        $samples = create_wav_samples_from_picture($frame, imagecolorat ($frame, 1,1));
        if ($samples) {
            $loop = loop_frames($samples, 250);
            fwrite($fp, $loop);
            echo 'Playing for '.(strlen($loop) / ($WavSamplingRate * $WavChannels)).'s. Actual frame length: '.(count($samples) / ($WavSamplingRate * $WavChannels))."s\n";
            usleep(150000);
            continue;
        }
    }
    echo "Waiting\n";
    usleep(750000);
}

exit();
*/

try {
    if (php_sapi_name() !== 'cli')
    {    
        echo '<html><body><pre>';

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
    $output = 'output.wav';

    if ($argc == 3) {
        $output = $argv[2];
    }

    if (!file_exists($inputFile))
    {
        throw new exception("File $inputFile not found!");
    }

    $soundData = '';

    if (GifFrameExtractor::isAnimatedGif($inputFile))
    {
        echo "Processing GIF... \n";
        $gfe = new GifFrameExtractor();
        $gfe->extract($inputFile);
        foreach ($gfe->getFrames() as $i => $frame)
        {
            $soundData .= loop_frames(implode(create_wav_samples_from_picture($frame['image'], imagecolorat($frame['image'], 1, 1))), $frame['duration'] * 10);
        }
        $soundData .= $soundData;
    }
    else
    {
        if (is_dir($inputFile))
        {
            foreach (glob($inputFile . '/*.png') as $file)
            {
                echo "Processing PNG ($file) ... \n";
                $frame = imagecreatefrompng($file);
                $soundData .= loop_frames(implode(create_wav_samples_from_picture($frame, imagecolorat($frame, 1, 1))), 1000);
            }
        }
        else
        {
            echo "Processing PNG ($inputFile) ... \n";
            $frame = imagecreatefrompng($inputFile);
            $soundData .= loop_frames(implode(create_wav_samples_from_picture($frame, imagecolorat($frame, 1, 1))), 10000);
        }
    }

    create_wav_from_samples($output, $soundData);

    echo "Done! \n";
    
    if (php_sapi_name() !== 'cli') {
        echo '<a href="output.wav">Download file</a>';
    }
}
catch (exception $e)
{
    echo $e->getMessage() . "\n";
} 

?>
