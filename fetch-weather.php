<?php
// Préchauffage météo — exécuté par GitHub Actions toutes les heures.
// Écrit un fichier JSON par commune dans data/, au même format que
// celui attendu par le cache de extra/ecrans/meteo/index.php sur OVH.

$COMMUNES = [
    'vannes'         => ['nom' => 'Vannes',                 'lat' => 47.6588, 'lon' => -2.7599],
    'saint-ave'      => ['nom' => 'Saint-Avé',              'lat' => 47.6833, 'lon' => -2.7333],
    'sene'           => ['nom' => 'Séné',                   'lat' => 47.6000, 'lon' => -2.7167],
    'arradon'        => ['nom' => 'Arradon',                'lat' => 47.6167, 'lon' => -2.8167],
    'arzon'          => ['nom' => 'Arzon',                  'lat' => 47.5500, 'lon' => -2.8833],
    'sarzeau'        => ['nom' => 'Sarzeau',                'lat' => 47.5167, 'lon' => -2.7667],
    'st-gildas'      => ['nom' => 'Saint-Gildas-de-Rhuys', 'lat' => 47.5033, 'lon' => -2.8383],
    'saint-gildas'   => ['nom' => 'Saint-Gildas-de-Rhuys', 'lat' => 47.5033, 'lon' => -2.8383],
    'baden'          => ['nom' => 'Baden',                  'lat' => 47.6167, 'lon' => -2.9000],
    'ile-aux-moines' => ['nom' => 'Île-aux-Moines',         'lat' => 47.5983, 'lon' => -2.9833],
    'iam'            => ['nom' => 'Île-aux-Moines',         'lat' => 47.5983, 'lon' => -2.9833],
    'ile-darz'       => ['nom' => "Île d'Arz",              'lat' => 47.6000, 'lon' => -2.8333],
    'arz'            => ['nom' => "Île d'Arz",              'lat' => 47.6000, 'lon' => -2.8333],
    'le-bono'        => ['nom' => 'Le Bono',                'lat' => 47.6500, 'lon' => -2.9500],
    'auray'          => ['nom' => 'Auray',                  'lat' => 47.6667, 'lon' => -2.9833],
    'locmariaquer'   => ['nom' => 'Locmariaquer',           'lat' => 47.5667, 'lon' => -3.0000],
    'muzillac'       => ['nom' => 'Muzillac',               'lat' => 47.5500, 'lon' => -2.4833],
];

define('OUT_DIR', __DIR__ . '/data');

function fetchJson(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

if (!is_dir(OUT_DIR)) mkdir(OUT_DIR, 0755, true);

// Dédoublonne par coordonnées GPS (ex: iam et ile-aux-moines = 1 seul appel)
$vus     = [];
$uniques = [];
foreach ($COMMUNES as $cle => $c) {
    $key = $c['lat'] . ',' . $c['lon'];
    if (!isset($vus[$key])) {
        $vus[$key]     = $cle;
        $uniques[$cle] = $c;
    }
}

echo count($uniques) . " point(s) GPS unique(s) à interroger\n\n";
$echecs = 0;

foreach ($uniques as $cle => $commune) {
    $apiUrl = 'https://api.open-meteo.com/v1/forecast?'
        . 'latitude='   . $commune['lat']
        . '&longitude=' . $commune['lon']
        . '&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m,wind_direction_10m'
        . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,wind_direction_10m_dominant'
        . '&timezone=Europe%2FParis'
        . '&forecast_days=6';

    $data = fetchJson($apiUrl);

    if (isset($data['hourly'], $data['daily'])) {
        // Écrit ce point GPS + tous ses alias (même coordonnées)
        foreach ($COMMUNES as $alias => $c) {
            if ($c['lat'] === $commune['lat'] && $c['lon'] === $commune['lon']) {
                $payload = ['commune' => $c, 'data' => $data];
                file_put_contents(OUT_DIR . "/meteo_$alias.json", json_encode($payload));
                echo "[OK] $alias\n";
            }
        }
    } else {
        $raison = $data['reason'] ?? 'erreur inconnue';
        echo "[FAIL] $cle — $raison\n";
        $echecs++;
    }

    usleep(300000); // 300ms entre appels, on n'est pas pressé
}

echo "\nTerminé — $echecs échec(s)\n";
exit($echecs > 0 && $echecs === count($uniques) ? 1 : 0); // échoue seulement si TOUT a échoué
