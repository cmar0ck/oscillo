<html><body><pre><form method="post" enctype="multipart/form-data">GIF File: <input type="file" name="inputfile"><input type="submit"></form>No input file specified.

Usage: php oscillo.php inputfile outputfile

       Supported input formats are GIF and PNG images.
       inputfile can be a directory containing PNG files.
       Output will be a 48khz 8bit stereo WAV file. 
       If your OS supports it (Cygwin/Linux), you can output to /dev/dsp for realtime playback.
