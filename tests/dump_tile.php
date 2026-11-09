<?php

declare(strict_types=1);

/**
 * Erzeugt Kachel-HTML und Beispielnachrichten aus dem echten Modul gegen den Fake-LMS,
 * damit die Oberfläche im Browser getestet werden kann.
 *   php tests/dump_tile.php <ausgabeordner> [single]
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/stubs';
require_once $stubs . '/autoload.php';

$out = $argv[1] ?? sys_get_temp_dir() . '/lmr';
$single = ($argv[2] ?? '') === 'single';
$noSpotify = ($argv[2] ?? '') === 'nospotify';
@mkdir($out, 0777, true);

$port = 19078;
$stateFile = $out . '/fake_state.json';
@unlink($stateFile);
if ($single) {
    // Nur ein Player, wie aktuell bei Dennis
    $initial = [
        'players' => [
            'aa:bb:cc:00:00:01' => ['name' => 'Wohnzimmer', 'power' => 1, 'connected' => 1, 'mode' => 'play', 'time' => 61.0, 'volume' => 32, 'shuffle' => 1, 'repeat' => 2, 'tracks' => 20,
                'track' => ['title' => 'Golden Hour Drive', 'artist' => 'Nordlicht Kollektiv', 'album' => 'Weite Straßen', 'artwork_url' => 'https://i.example/cover-a.jpg', 'duration' => 238]]
        ],
        'groups' => [],
        'accounts' => ['acc_dennis' => ['displayName' => 'Dennis'], 'acc_family' => ['displayName' => 'Familie']],
        'activeAccount' => 'acc_dennis',
        'playerAccount' => [],
        'log' => []
    ];
    file_put_contents($stateFile, json_encode($initial));
}
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake_lms.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['LMS_STATE' => $stateFile]);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}

IPS\Kernel::reset();
IPS\ModuleLoader::loadLibrary($stubs . '/IOStubs/library.json');
IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
$id = IPS_CreateInstance('{91D1C7C4-ADEB-4A3B-A4B4-F288AF7D5140}');
$module = IPS\InstanceManager::getInstanceInterface($id);
IPS_SetProperty($id, 'Host', '127.0.0.1');
IPS_SetProperty($id, 'WebPort', $port);
if (!$single) {
    IPS_SetProperty($id, 'Players', json_encode([
        ['id' => 'aa:bb:cc:00:00:01', 'area' => 'Erdgeschoss', 'visible' => true],
        ['id' => 'aa:bb:cc:00:00:02', 'area' => 'Erdgeschoss', 'visible' => true],
        ['id' => 'aa:bb:cc:00:00:03', 'area' => 'Obergeschoss', 'visible' => true],
        ['id' => 'aa:bb:cc:00:00:04', 'area' => 'Obergeschoss', 'visible' => true]
    ]));
}
if ($noSpotify) {
    IPS_SetProperty($id, 'SpotifyEnabled', false);
}
IPS_ApplyChanges($id);

file_put_contents($out . '/tile.html', $module->GetVisualizationTile());

$messages = static function () use ($module): array {
    $list = [];
    foreach ($module->getMessages() as $m) {
        if ($m['Message'] === 10541) {
            $list[] = json_decode($m['Data'][0], true);
        }
    }
    return $list;
};
$capture = static function (callable $fn) use ($messages): array {
    $before = count($messages());
    $fn();
    return array_slice($messages(), $before);
};
$browse = static fn (array $req) => $capture(static fn () => IPS_RequestAction($id, 'UI', json_encode($req + ['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'req' => 'X'])));
// Schlüssel: Quelle:Einstieg:Art – so findet der Test-Harness die passende Antwort
$responses = [
    'spotify::root'       => $browse(['src' => 'spotify', 'id' => '']),
    'spotify::item'       => $browse(['src' => 'spotify', 'id' => 'ab12.1']),
    'spotify::search'     => $browse(['src' => 'spotify', 'id' => 'ab12.2', 'q' => 'nordlicht']),
    'spotify::long'       => $browse(['src' => 'spotify', 'id' => 'ab12.3']),
    'library:artists:root' => $browse(['src' => 'library', 'root' => 'artists', 'id' => '']),
    'library:artists:item' => $browse(['src' => 'library', 'root' => 'artists', 'id' => 'lib.1']),
    'favorites::root'     => $browse(['src' => 'favorites', 'id' => ''])
];
file_put_contents($out . '/responses.json', json_encode($responses));
proc_terminate($server);
echo "ok\n";
