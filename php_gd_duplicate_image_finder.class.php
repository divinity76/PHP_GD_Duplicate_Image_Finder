<?php
class phpDupeImage {
	
    // Copyright pawz, 2010.
    // Free for any profit or non-profit use.
    // You may freely distribute or modify this code.

    // Width for thumbnail images we use for fingerprinting.
    // Default is 150 which works pretty well.
    public $thumbWidth = 150;                               
    // Sets how sensitive the fingerprinting will be. 
    // Higher numbers are less sensitive (more likely to match). Floats are allowed.
    public $sensitivity = 2;                                
    // Sets how much deviation is tolerated between two images when doing an thorough comparison.
    public $deviation = 10;                                 
    // Defines what table the image fingerprints are stored in.
    public $image_table = 'pictures';                       
    // Defines the name of the field which contains the fingerprint.
    public $fingerprint_field = 'pictures_fingerprint';     
    // Sets the name of the field which contains the filename.
    public $filename_field = 'pictures_image';              
    // Sets the path to where completed files are stored for checking against.
    public $completed_files_path = '';                      
    // Sets the width and height of the thumbnail sized image we use for deep comparison.
    public $small_size = 16;     
	//pdo database connection.	
	public $pdo_connection=false;


    /* *******************************************************
    *   Is Unique
    *
    *   This function checks whether the file is unique
    *   (ie, the checksum is not already in the database)
    *   If the fingerprint is already in the database, it 
    *   calls are_duplicates to compare them in more detail
    *   It returns an md5 hash if unique or -1 if not.
    ******************************************************* */
    function is_unique($filename) {
        $fingerprint = $this->fingerprint($filename);
		$sqlR=$this->pdo_connection->query('SELECT `'.$this->filename_field.'` FROM `'.$this->image_table.'` WHERE `'.$this->fingerprint_field.'` = '.$this->pdo_connection->quote($fingerprint));
		//Warning: SQLite3, for instance, does not support rowCount() for SELECT statements.
		//$realRowCount=0;
		while(false!==($row=$sqlR->fetch(PDO::FETCH_ASSOC))){
			//++$realRowCount;
                if ($this->are_duplicates($filename, 
                $this->completed_files_path."/".$row[$this->filename_field])) {
					return -1;
                }			
		}
		//var_dump('almost matches:',$realRowCount);
	    // No matching fingerprints found so return true.
		return $fingerprint;
    }


    /* *******************************************************
    *   Are Duplicates
    *
    *   This function compares two images by resizing them
    *   to a common size and then analysing the colours of
    *   each pixel and calculating the difference between
    *   both images for each colour channel and returns
    *   an index representing how similar they are.
    ******************************************************* */
    function are_duplicates($file1, $file2) {

        // Load in both images and resize them to 16x16 pixels
        $image1_src = @imagecreatefromjpeg($file1);
        $image2_src = @imagecreatefromjpeg($file2);
        list($image1_width, $image1_height) = getimagesize($file1);
        list($image2_width, $image2_height) = getimagesize($file2);
        $image1_small = imagecreatetruecolor($this->small_size, $this->small_size);
        $image2_small = imagecreatetruecolor($this->small_size, $this->small_size);
        imagecopyresampled($image1_small, $image1_src, 0, 0, 0, 0, 
        $this->small_size, $this->small_size, $image1_width, $image1_height);
        imagecopyresampled($image2_small, $image2_src, 0, 0, 0, 0, 
        $this->small_size, $this->small_size, $image2_width, $image2_height);

        // Compare the pixels of each image and figure out the colour difference between them
        for ($x = 0; $x < 16; ++$x) {
            for ($y = 0; $y < 16; ++$y) {
                $image1_color = imagecolorsforindex($image1_small, 
                imagecolorat($image1_small, $x, $y));
                $image2_color = imagecolorsforindex($image2_small, 
                imagecolorat($image2_small, $x, $y));
                $difference +=  abs($image1_color['red'] - $image2_color['red']) + 
                                abs($image1_color['green'] - $image2_color['green']) +
                                abs($image1_color['blue'] - $image2_color['blue']);
            }
        }
        $difference = $difference / 256;
        if ($difference <= $this->deviation) {
            return 1;
        } else {
            return 0;
        }

    }

    /* *******************************************************
    *   Fingerprint
    *
    *   This function analyses the filename passed to it and
    *   returns an md5 checksum of the file's histogram.
    ******************************************************* */
    function fingerprint($filename) {

        // Load the image. Escape out if it's not a valid jpeg.
        if (!$image = @imagecreatefromjpeg($this->filePath."/".$filename)) {
            return -1;
        }

        // Create thumbnail sized copy for fingerprinting
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = $this->thumbWidth / $width;
        $newwidth = $this->thumbWidth;
        $newheight = round($height * $ratio); 
        $smallimage = imagecreatetruecolor($newwidth, $newheight);
        imagecopyresampled($smallimage, $image, 0, 0, 0, 0, 
        $newwidth, $newheight, $width, $height);
        $palette = imagecreatetruecolor(1, 1);
        $gsimage = imagecreatetruecolor($newwidth, $newheight);

        // Convert each pixel to greyscale, round it off, and add it to the histogram count
        $numpixels = $newwidth * $newheight;
        $histogram = array();
        for ($i = 0; $i < $newwidth; ++$i) {
            for ($j = 0; $j < $newheight; ++$j) {
                $pos = imagecolorat($smallimage, $i, $j);
                $cols = imagecolorsforindex($smallimage, $pos);
                $r = $cols['red'];
                $g = $cols['green'];
                $b = $cols['blue'];
                // Convert the colour to greyscale using 30% Red, 59% Blue and 11% Green
                $greyscale = round(($r * 0.3) + ($g * 0.59) + ($b * 0.11));                 
                ++$greyscale;
                $value = (round($greyscale / 16) * 16) -1;
                ++$histogram[$value];
            }
        }

        // Normalize the histogram by dividing the total of each colour by the total number of pixels
        $normhist = array();
        foreach ($histogram as $value => $count) {
            $normhist[$value] = $count / $numpixels;
        }

        // Find maximum value (most frequent colour)
        $max = 0;
        for ($i=0; $i<255; ++$i) {
            if ($normhist[$i] > $max) {
                $max = $normhist[$i];
            }
        }

        // Create a string from the histogram (with all possible values)
        $histstring = "";
        for ($i = -1; $i <= 255; $i = $i + 16) {
            $h = ($normhist[$i] / $max) * $this->sensitivity;
            if ($i < 0) {
                $index = 0;
            } else {
                $index = $i;
            }
            $height = round($h);
            $histstring .= $height;
        }

        // Destroy all the images that we've created
        imagedestroy($image);
        imagedestroy($smallimage);
        imagedestroy($palette);
        imagedestroy($gsimage);

        // Generate an md5sum of the histogram values and return it
        $checksum = md5($histstring);
        return $checksum;

    }
}
