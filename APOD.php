<?php

use CommandLine\Argument\Option\Flag;
use CommandLine\Argument\Option\Value AS OptionValue;
use CommandLine\Argument\Parser\IntegerParser;
use CommandLine\Argument\Parser\StringParser;
use CommandLine\Argument\Value\Value;
use CommandLine\CommandLine;

require_once __DIR__ . '/vendor/autoload.php';

const URL_ROOT = 'https://apod.nasa.gov/apod/';
const THUMBS_FILE = 'Thumbs.db';

const DESCRIPTION = <<<TXT
APOD images downloader.
Download multiple images from APOD (https://apod.nasa.gov/apod/) website.

By default, download images of last X days (see options) and last Y years (for the current day)
If "square" method is used, download pictures of last X days for EVERY Y last years. 
TXT;

$cmd = new CommandLine('APODImages', DESCRIPTION, 'php APOD.php');
$cmd->addDefaultArguments();

$cmd->addOption(new Flag('force', false, 'Force re-download of files'));
$cmd->addOption(new Flag('noCleanup', false, 'Skip cleanup of old files', 'no-cleanup'));
$cmd->addOption(new Flag('square', false, 'Download with "square" method ?'));
$cmd->addOption((new OptionValue('days', 'Number of days', new IntegerParser(0)))->setDefault(3));
$cmd->addOption((new OptionValue('years', 'Number of years', new IntegerParser(0)))->setDefault(5));

$cmd->addValue(new Value('path', 'Path of local directory for downloaded images', new StringParser()));

$args = $cmd->parse();
$cmd->treatDefaultArguments($args);

function getFilename (DateTime $date) {
    return 'ap'.$date->format('ymd');
}

$date_ref = new DateTime();
echo '===== '.$date_ref->format('d/m/Y H:i:s').' ====='."\n";

echo 'Local directory : ' . $args->path . "\n";
if (!file_exists($args->path) || ! is_dir($args->path)) {
    if (!mkdir($args->path, 775, true)) {
        die('Failed to create local directory' . "\n");
    }
}
if (substr($args->path, -1, 1) != DIRECTORY_SEPARATOR)
    $args->path .= DIRECTORY_SEPARATOR;

/*
 * Build filenames list
 */
try {
    $names = array(
        getFilename($date_ref),
    );

    for ($year = 1; $year <= $args->years; $year++) {
            $names[] = getFilename((clone $date_ref)->sub(new DateInterval('P' . $year . 'Y')));

        if ($args->square) {
            for ($day = 1; $day <= $args->days; $day++)
                $names[] = getFilename((clone $date_ref)->sub(new DateInterval('P' . $year . 'Y' . $day . 'D')));
        }
    }

    if (!$args->square) {
        for ($day = 1; $day <= $args->days; $day++) {
            $names[] = getFilename((clone $date_ref)->sub(new DateInterval('P' . $day . 'D')));
        }
    }
}
catch (Exception $e) {
    die('EXCEPTION '.$e->getMessage()."\n");
}

/*
 * Cleanup old files (except hidden)
 */
if (!$args->noCleanup) {
    echo 'Cleanup old files' . "\n";

    $files = scandir($args->path);

    $restant = array();
    foreach ($files as $idx => $file) {
        echo '  File : '.$file."\n";
        if (substr($file, 0, 1) == '.' || $file == THUMBS_FILE) {
            echo '    Skipped'."\n";
            continue;
        }

        $base = pathinfo($file, PATHINFO_FILENAME);
        if (!in_array($base, $names)) {
            echo '    Deleted'."\n";
            unlink($args->path . $file);
        }
        else {
            echo '    Keeped'."\n";
            $restant[] = $base;
        }
    }
}

/*
 * (Re)Download files
 */
echo 'Download files'."\n";

$nb = 0;
foreach ($names as $name) {
    echo '  ... '.$name;
    if (!in_array($name, $restant) || $args->force) {
        $url = URL_ROOT.$name.'.html';
        echo ' => '.$url."\n";

        $html = file_get_contents($url);
        if ($html === false) {
            echo '      Failed download HTML'."\n";
            continue;
        }

        if (!preg_match('@<a href="(([^"]+)(?:_[0-9]+)?\.([a-z0-9_]+))">\s*<img src="\2@i', $html, $matches)) {
            echo '      No usable image'."\n";
            continue;
        }

        $out_path = $args->path . $name . '.' . $matches[3];
        echo '      Download ' . $matches[1] . ' to ' . $out_path . "\n";
        if (file_put_contents($out_path, file_get_contents(URL_ROOT . $matches[1])) !== false) {
            $nb++;
        }
    }
    else {
        echo "\n";
    }
}

/*
 * End
 */
echo $nb . ' files downloaded'."\n";
