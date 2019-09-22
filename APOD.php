<?php

/* ==============================
 * = Script téléchargement APOD =
 * ==============================
 * Supprime du répertoire les anciennes photos et télécharge celle manquantes depuis le site de l'APOD (Astronomical Pictures Of Day)
 */

const PHOTO_ROOT = 'F:\\Images\\APOD\\';
const URL_ROOT = 'https://apod.nasa.gov/apod/';

const NB_YEARS = 5;
const NB_DAYS  = 3;

const EXCLUDED_FILES = array('.', '..', 'Thumbs.db');

function echo_p($msg, $nb_tab = 0) {
    echo str_repeat("\t", $nb_tab).$msg."\n";
}
function die_p($msg, $nb_tab = 0) {
    die(str_repeat("\t", $nb_tab).$msg."\n");
}

function getFilename(DateTime $date) {
    return 'ap'.$date->format('ymd');
}

echo_p('===== '.(new DateTime())->format('d/m/Y H:i:s').' =====');

if(!file_exists(PHOTO_ROOT) || ! is_dir(PHOTO_ROOT)) {
    if(!mkdir(PHOTO_ROOT, 775, true))
        die_p('Echec création répertoire des photos');
}

/*
 * Constitutions des noms de fichiers
 */
echo_p('Génération des noms de fichiers');

$date_ref = new DateTime();
$names = array(
    getFilename($date_ref),
);

for($day = 1; $day <= NB_DAYS; $day++)
    $names[] = getFilename((clone $date_ref)->sub(new DateInterval('P'.$day.'D')));

for($year = 1; $year <= NB_YEARS; $year++)
    $names[] = getFilename((clone $date_ref)->sub(new DateInterval('P'.$year.'Y')));

/*
 * Nettoyage fichiers
 */
echo_p('Nettoyage des fichiers existants');

$files = scandir(PHOTO_ROOT);
$files = array_diff($files, EXCLUDED_FILES);

$restant = array();
foreach($files as $idx => $file) {
    echo_p('Fichier '.$file, 1);

    $base = pathinfo($file, PATHINFO_FILENAME);
    if(!in_array($base, $names)) {
        echo_p('Suppression', 2);
        unlink(PHOTO_ROOT.$file);
    }
    else
        $restant[] = $base;
}

/*
 * Téléchargement fichiers manquant
 */
echo_p('Vérification fichiers');
foreach($names as $name) {
    echo_p('... '.$name, 1);
    if(!in_array($name, $restant)) {
        $url = URL_ROOT.$name.'.html';
        echo_p('Traitement '.$url, 2);

        $html = file_get_contents($url);
        if($html === false) {
            echo_p('Échec téléchargement', 3);
            continue;
        }

        if(!preg_match('@<a href="(([^"]+)(?:_[0-9]+)?\.([a-z0-9_]+))">\s*<img src="\2@i', $html, $matches)) {
            echo_p('HTML incorrect', 3);
            continue;
        }

        echo_p('Téléchargement '.$matches[1].' ('.$matches[3].')', 3);
        file_put_contents(PHOTO_ROOT.$name.'.'.$matches[3], file_get_contents(URL_ROOT.$matches[1]));
    }
}

echo_p('Terminé');
