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
$cmd->addOption(
    (new OptionValue(
        'sizeLimit',
        'Size limit (in bytes or with suffix) for images before switching to low definition. 0 = Always LD, -1 = Always HD',
        new IntegerParser(-1)
    ))
        ->setDefault(15000000)
);

$cmd->addValue(new Value('path', 'Path of local directory for downloaded images', new StringParser()));

$args = $cmd->parse();
$cmd->treatDefaultArguments($args);

function getFilename (DateTime $date) {
    return 'ap' . $date->format('ymd');
}

$date_ref = new DateTime();
echo '===== ' . $date_ref->format('d/m/Y H:i:s') . ' =====' . "\n";

echo 'Local directory : ' . $args->path . "\n";
if (!file_exists($args->path) || !is_dir($args->path)) {
    if (!mkdir($args->path, 775, true)) {
        die('Failed to create local directory' . "\n");
    }
}
if (substr($args->path, -1, 1) != DIRECTORY_SEPARATOR) {
    $args->path .= DIRECTORY_SEPARATOR;
}

/*
 * Build filenames list to remove and download
 */
echo 'Generate files lists' . "\n";
try {
    $finalBasenames = array(
        getFilename($date_ref),
    );

    for ($year = 1; $year <= $args->years; $year++) {
        $finalBasenames[] = getFilename((clone $date_ref)->sub(new DateInterval('P' . $year . 'Y')));

        if ($args->square) {
            for ($day = 1; $day <= $args->days; $day++) {
                $finalBasenames[] = getFilename((clone $date_ref)->sub(new DateInterval('P' . $year . 'Y' . $day . 'D')));
            }
        }
    }

    if (!$args->square) {
        for ($day = 1; $day <= $args->days; $day++) {
            $finalBasenames[] = getFilename((clone $date_ref)->sub(new DateInterval('P' . $day . 'D')));
        }
    }
}
catch (Exception $e) {
    die('EXCEPTION ' . $e->getMessage() . "\n");
}

$toDownload = array();
$toRemove = array();

$files = scandir($args->path);
foreach ($files as $file) {
    $filename = pathinfo($file, PATHINFO_FILENAME);
    if (in_array($filename, $finalBasenames)) {
        if ($args->force) {
            $toDownload[] = $filename;
        }
    }
    else {
        if (substr($file, 0, 1) != '.' && $file != THUMBS_FILE) {
            $toRemove[] = $file;
        }
    }
}

foreach ($finalBasenames as $finalBasename) {
    if (count(glob($args->path . $finalBasename . '.*')) == 0) {
        $toDownload[] = $finalBasename;
    }
}

echo count($toDownload) . ' files to download' . "\n";
echo count($toRemove) . ' files to delete' . "\n";

/*
 * (Re)Download files
 */
echo 'Downloading' . "\n";

$nb = 0;
foreach ($toDownload as $file) {
    echo '  ... ' . $file;

    $url = URL_ROOT . $file . '.html';
    echo ' => ' . $url . "\n";

    $html = file_get_contents($url);
    if ($html === false) {
        echo '      Failed download HTML' . "\n";
        continue;
    }

    /* https://apod.nasa.gov/apod/ap191023.html */
    if (!preg_match('@<a href="(?<filename_high>(?<filename_base>[^"]+)(?:_?[0-9]+)?\.(?<extension>[a-z0-9_]+))">\s*<img src="(?<filename_low>\k<filename_base>(?:_?[0-9]+)?\.\k<extension>)@i',
                    $html, $matches
    )) {
        echo '      No usable image' . "\n";
        continue;
    }

    $url_download = '';
    if ($args->sizeLimit == -1) {
        $url_download = $matches['filename_high'];
    }
    else {
        $size = false;
        $headers = get_headers(URL_ROOT . $matches['filename_high'], 1);
        if (isset($headers['Content-Length'])) {
            $size = $headers['Content-Length'];
        }
        else {
            $size = @filesize(URL_ROOT . $matches['filename_high']);
        }

        if ($size === false || $size == 0) {
            $url_download = $matches['filename_low'];
        }
        else {
            if ($size > $args->sizeLimit) {
                $url_download = $matches['filename_low'];
            }
            else {
                $url_download = $matches['filename_high'];
            }
        }
    }

    $out_path = $args->path . $file . '.' . $matches['extension'];
    echo '      Download ' . $url_download . ' to ' . $out_path . "\n";
    if (file_put_contents($out_path, fopen(URL_ROOT . $url_download, 'r')) !== false) {
        $nb++;
    }
}

/*
 * Cleanup old files (except hidden)
 */
if (!$args->noCleanup && $nb > 0) {
    echo 'Cleanup old files' . "\n";

    foreach ($toRemove as $file) {
        echo '  Delete : ' . $file . "\n";
        unlink($args->path . $file);
    }
}
