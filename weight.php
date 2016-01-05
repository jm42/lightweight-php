<?php

main($argv);

function main(array $argv)
{
    if (count($argv) > 1) {
        $dir = realpath($argv[1]);
    } else {
        $dir = getcwd();
    }

    if (!file_exists($dir . '/composer.json') ||
        !file_exists($dir . '/composer.lock')
    ) {
        echo "Directory does not contain composer.json and composer.lock\n";
        exit(1);
    }

    $weight = new Weight($dir);
    $title = 'Lightweight PHP (by Logical Lines of Code)'
           . ' (generated ' . date('Y/m/d') . ')';

    list($counts, $extras) = $weight->run();

    $char = new Chart($counts, $title, $extras);
    $char->draw(888);
    $char->save('weight.png');
}

class Weight
{
    protected $root;     /* root directory where composer.json is found  */
    protected $vendor;   /* name of the vendor folder                    */
    protected $packages; /* all package information from composer.lock   */
    protected $weight;   /* weight cache for names packages              */

    public function __construct($root)
    {
        $this->root = $root;
    }

    protected function parse($root)
    {
        $data = json_decode(file_get_contents($root . '/composer.json'), true);
        $lock = json_decode(file_get_contents($root . '/composer.lock'), true);

        $this->vendor = @$data['config']['vendor-dir'] ?: 'vendor';

        foreach ($lock['packages'] as $package) {
            $this->packages[$package['name']] = $package;
        }

        return $data;
    }

    protected function weightPackage($name)
    {
        if (substr(strtolower($name), 0, 4) === 'ext-' ||
            substr(strtolower($name), 0, 4) === 'lib-'
        ) {
            return 0;
        }

        if (isset($this->weight[$name])) {
            return $this->weight[$name];
        }

        $package = $this->packages[$name];
        $autoload = (array) @$package['autoload'];

        $paths = array();
        array_walk_recursive($autoload, function ($path, $_) use (&$paths)
        {
            $paths[] = $path;
        });

        $weight = 0;
        foreach ($paths as $path) {
            $fullpath = $this->root . '/' . $this->vendor . '/'
                      . $name . '/' . $path;

            if (is_file($fullpath)) {
                $weight += $this->weightFile($fullpath);
            } else {
                $weight += $this->weightDir($fullpath);
            }
        }

        if (empty($package['require'])) {
            return $this->weight[$name] = $weight;
        }

        foreach ($package['require'] as $reqname => $reqversion) {
            if (strtolower($reqname) === 'php') {
                continue;
            }

            $weight += $this->weightPackage($reqname);
        }

        return $this->weight[$name] = $weight;
    }

    protected function weightDir($dirname)
    {
        if (!is_dir($dirname)) {
            return 0;
        }

        $weight = 0;

        $iter = new RegexIterator(
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dirname)
            ),
            '/^((?!Test).)+\.php$/i',
            RecursiveRegexIterator::GET_MATCH
        );

        foreach ($iter as $filematch) {
            $weight += $this->weightFile($filematch[0]);
        }

        return $weight;
    }

    protected function weightFile($filename)
    {
        $buffer = file_get_contents($filename);
        $weight = substr_count($buffer, ';') + substr_count($buffer, '{');

        return $weight;
    }

    public function run()
    {
        $package = $this->parse($this->root);

        $weight = array();
        $extras = array();

        foreach ($package['require'] as $name => $version) {
            $weight[$name] = $this->weightPackage($name);
            $extras[$name] = array(
                substr($this->packages[$name]['time'], 0, 10),
            );
        }

        return array($weight, $extras);
    }
}

class Chart
{
    const LIGHTWEIGHT = 2000;

    protected $data;
    protected $more;
    protected $title;
    protected $image;

    public function __construct(array $data, $title = null, $extras = array())
    {
        $this->data = $data;
        $this->more = $extras;
        $this->title = $title;
    }

    public function save($filename = 'image.png')
    {
        switch (substr($filename, -3)) {
            case 'png':
                imagePNG($this->image, $filename);
                break;

            case 'jpg':
                imageJPEG($this->image, $filename);
                break;
        }
    }

    public function draw($width = 640, $height = 480)
    {
        $this->image = imageCreateTrueColor($width, $height);

        imageFilledRectangle($this->image, 0, 0, $width, $height,
            imageColorAllocate($this->image, 255, 255, 255)
        );

        $padding = 10;

        $x = $padding;
        $y = $padding;

        $titleHeight = $this->drawTitle($x, $y, 5);
        if ($titleHeight > 0) {
            $y += $titleHeight;
            $y += $padding;
        }

        $count = count($this->data);

        $color = imageColorAllocate($this->image, 0, 0, 0);
        $colors = $this->generateRandomColors();

        $font = 2;
        $fontWidth = imageFontWidth($font);
        $fontHeight = imageFontHeight($font);

        $chartHeight = $height - $y - $padding;
        $barHeight = floor($chartHeight / $count);

        $extrasWidth = 0;
        foreach ($this->more as $extras) {
            $extraWidth = 0;
            foreach ($extras as $extra) {
                $extraWidth += $fontWidth * strlen($extra) + $padding;
            }

            if ($extrasWidth < $extraWidth) {
                $extrasWidth = $extraWidth;
            }
        }

        $barMaxValue = 0;
        $keyWidthMax = 0;
        foreach ($this->data as $key => $value) {
            $keyWidth = $fontWidth * strlen($key);
            if ($keyWidthMax < $keyWidth) {
                $keyWidthMax = $keyWidth;
            }
            if ($barMaxValue < $value) {
                $barMaxValue = $value;
            }
        }

        $i = 0;
        foreach ($this->data as $key => $value) {
            $keyWidth = $fontWidth * strlen($key);

            $tmpX = $x + $keyWidthMax - $keyWidth;
            $tmpY = $y + $barHeight * $i + ($barHeight / 2 - $fontHeight / 2);

            imageString($this->image, $font, $tmpX, $tmpY, $key, $colors[$key]);

            $i++;
        }

        $x += $keyWidthMax + $padding;
        imageLine($this->image, $x, $y, $x, $y + $chartHeight, $color);
        $x += $padding;

        $barMaxWidth = $width - $padding - $x - $extrasWidth;

        if ($barMaxValue > self::LIGHTWEIGHT) {
            $xmicro = $x + self::LIGHTWEIGHT * $barMaxWidth / $barMaxValue;
            $ymicro = $y;
        }

        $i = 0;
        foreach ($this->data as $key => $value) {
            $tmpY1 = $y + $barHeight * $i;
            $tmpY2 = $y + $barHeight * ($i + 1);

            $barWidth = $value * $barMaxWidth / $barMaxValue;

            imageFilledRectangle($this->image,
                $x, $tmpY1,
                $x + $barWidth, $tmpY2,
                $colors[$key]
            );

            $valueWidth = $fontWidth * strlen($value);

            $tmpX = $x + $barMaxWidth - $valueWidth - $padding;
            $tmpY = ($tmpY1 + $tmpY2 - $fontHeight) / 2;

            imageString($this->image, $font, $tmpX, $tmpY, $value, $color);

            $extraWidth = $padding;
            foreach ($this->more[$key] as $extra) {
                $tmpX = $x + $barMaxWidth + $extraWidth;
                $extraWidth += $fontWidth * strlen($extra) + $padding;

                imageString($this->image, $font, $tmpX, $tmpY, $extra, $color);
            }

            $i++;
        }

        if ($barMaxValue > self::LIGHTWEIGHT) {
            imageDashedLine($this->image, $xmicro, $y, $xmicro, $y + $chartHeight, $color);
        }
    }

    protected function drawTitle($x = 0, $y = 0, $font = 1)
    {
        if ($this->title === null) {
            return 0;
        }

        imageString($this->image, $font, $x, $y, $this->title,
            imageColorAllocate($this->image, 0, 0, 0)
        );

        return imageFontHeight($font);
    }

    protected function generateRandomColors()
    {
        $frequency = 5 / count($this->data);
        $colors = array();
        $index = 0;

        foreach ($this->data as $key => $value) {
            $r = sin($frequency * $index + 0) * (127) + 128;
            $g = sin($frequency * $index + 1) * (127) + 128;
            $b = sin($frequency * $index + 3) * (127) + 128;

            $colors[$key] = imageColorAllocate($this->image, $r, $g, $b);
            $index++;
        }

        return $colors;
    }
}