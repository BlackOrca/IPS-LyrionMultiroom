<?php

declare(strict_types=1);

/**
 * Testlauf ohne Symcon:  php tests/run_tests.php
 * Benötigt die SymconStubs (https://github.com/symcon/SymconStubs), Pfad über SYMCON_STUBS.
 * Startet einen Fake-LMS (tests/fake_lms.php) und prüft Modul-Logik und Datenfluss.
 */

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        // Minimaler Ersatz, damit der Validator der SymconStubs ohne PHPUnit läuft
        class TestCase
        {
            public static int $assertions = 0;

            private function check(bool $ok, string $message): void
            {
                self::$assertions++;
                if (!$ok) {
                    throw new \RuntimeException($message);
                }
            }
            public function assertTrue($v, string $m = ''): void { $this->check($v === true, $m ?: 'expected true'); }
            public function assertFalse($v, string $m = ''): void { $this->check($v === false, $m ?: 'expected false'); }
            public function assertArrayHasKey($k, $a, string $m = ''): void { $this->check(is_array($a) && array_key_exists($k, $a), $m ?: "missing key $k"); }
            public function assertIsString($v, string $m = ''): void { $this->check(is_string($v), $m ?: 'expected string'); }
            public function assertIsInt($v, string $m = ''): void { $this->check(is_int($v), $m ?: 'expected int'); }
            public function assertIsArray($v, string $m = ''): void { $this->check(is_array($v), $m ?: 'expected array'); }
            public function assertCount(int $n, $v, string $m = ''): void { $this->check(count($v) === $n, $m ?: "expected count $n, got " . count($v)); }
            public function assertGreaterThanOrEqual($e, $v, string $m = ''): void { $this->check($v >= $e, $m ?: "expected >= $e"); }
            public function assertLessThanOrEqual($e, $v, string $m = ''): void { $this->check($v <= $e, $m ?: "expected <= $e"); }
        }
    }
}

namespace {

    use LyrionMultiroom\Helper;

    $stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/stubs';
    require_once $stubs . '/autoload.php';
    require_once $stubs . '/Validator.php';
    require_once __DIR__ . '/../libs/LyrionHelper.php';

    $failures = 0;
    $passed = 0;
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $failures, $passed;
        if ($ok) {
            $passed++;
            echo "  ok   $name\n";
        } else {
            $failures++;
            echo "  FAIL $name" . ($detail !== '' ? "  → $detail" : '') . "\n";
        }
    }
    function section(string $title): void
    {
        echo "\n== $title\n";
    }

    // ------------------------------------------------------------------ Validator
    section('Struktur (SymconStubs-Validator)');
    $validator = new class() extends TestCaseSymconValidation {
        public function run(): void
        {
            $this->validateLibrary(__DIR__ . '/..');
            $this->validateModule(__DIR__ . '/../LyrionMultiroom');
        }
    };
    try {
        $validator->run();
        check('library.json und module.json gültig, alle Parameter typisiert', true);
    } catch (Throwable $e) {
        check('Validator', false, $e->getMessage());
    }

    // ------------------------------------------------------------------ Helper
    section('Helper');
    $n = Helper::ParseNotification('aa%3Abb%3Acc%3A00%3A00%3A01 playlist newsong Golden%20Hour 3');
    check('ParseNotification Player/Befehl', $n['player'] === 'aa:bb:cc:00:00:01' && $n['cmd'] === 'playlist' && $n['args'][1] === 'Golden Hour', json_encode($n));
    check('RefreshScope Player', Helper::RefreshScope($n, 'plugin.spoton') === 'aa:bb:cc:00:00:01');
    check('RefreshScope sync → *', Helper::RefreshScope(Helper::ParseNotification('aa%3Abb%3Acc%3A00%3A00%3A01 sync -'), 'plugin.spoton') === '*');
    check('RefreshScope client new → *', Helper::RefreshScope(Helper::ParseNotification('aa%3Abb%3Acc%3A00%3A00%3A09 client new'), 'plugin.spoton') === '*');
    check('RefreshScope prefset Konto', Helper::RefreshScope(Helper::ParseNotification('aa%3Abb%3Acc%3A00%3A00%3A01 prefset plugin.spoton activeAccount acc2'), 'plugin.spoton') === 'aa:bb:cc:00:00:01');
    check('RefreshScope fremde prefset ignoriert', Helper::RefreshScope(Helper::ParseNotification('aa%3Abb%3Acc%3A00%3A00%3A01 prefset server volume 3'), 'plugin.spoton') === null);
    check('RefreshScope subscribe-Echo ignoriert', Helper::RefreshScope(Helper::ParseNotification('subscribe client%2Csync'), 'plugin.spoton') === null);

    $zones = Helper::BuildZones(['a', 'b', 'c', 'd'], [['c', 'a']]);
    check('BuildZones Master zuerst, Rest einzeln', $zones === [['id' => 'b', 'members' => ['b']], ['id' => 'c', 'members' => ['c', 'a']], ['id' => 'd', 'members' => ['d']]], json_encode($zones));

    $plan = Helper::PlanZone([['a', 'b']], 'a', ['a', 'b', 'c'], true);
    check('PlanZone: Raum hinzufügen', $plan === [['c', ['power', '1']], ['a', ['sync', 'c']]], json_encode($plan));
    $plan = Helper::PlanZone([['a', 'b', 'c']], 'a', ['a', 'c'], false);
    check('PlanZone: Raum entfernen', $plan === [['b', ['sync', '-']]], json_encode($plan));
    $plan = Helper::PlanZone([['a', 'b']], 'a', [], false);
    check('PlanZone: Zone auflösen', $plan === [['b', ['sync', '-']]], json_encode($plan));
    $plan = Helper::PlanZone([['a', 'b'], ['x', 'y']], 'a', ['a', 'b', 'y'], false);
    check('PlanZone: Raum aus fremder Zone übernehmen', $plan === [['y', ['sync', '-']], ['a', ['sync', 'y']]], json_encode($plan));
    $plan = Helper::PlanZone([['a', 'b']], 'a', ['b', 'c'], false);
    check('PlanZone: Master abgewählt, b übernimmt', $plan === [['a', ['sync', '-']], ['b', ['sync', '-']], ['b', ['sync', 'c']]], json_encode($plan));
    $plan = Helper::PlanZone([], 'a', ['a'], false);
    check('PlanZone: Einzelplayer ohne Änderung', $plan === [], json_encode($plan));

    check('CoverPath http → imageproxy', Helper::CoverPath(['artwork_url' => 'https://i.scdn.co/image/ab'], 'aa:bb', 400) === '/imageproxy/https%3A%2F%2Fi.scdn.co%2Fimage%2Fab/image_400x400.jpg');
    check('CoverPath coverid', Helper::CoverPath(['coverid' => '0a1b2c3d'], 'aa:bb', 300) === '/music/0a1b2c3d/cover_300x300.jpg');
    check('CoverPath Fallback current', Helper::CoverPath(['coverid' => '-12'], 'aa:bb:cc:00:00:01', 300) === '/music/current/cover_300x300.jpg?player=aa%3Abb%3Acc%3A00%3A00%3A01');
    check('ThumbPath imageproxy mit Größe', Helper::ThumbPath('/imageproxy/https%3A%2F%2Fx%2Fy.jpg/image.jpg', 120) === '/imageproxy/https%3A%2F%2Fx%2Fy.jpg/image_120x120.jpg');
    check('ThumbPath Pfad-Traversal abgelehnt', Helper::ThumbPath('../../etc/passwd', 120) === null);
    $acc = Helper::NormalizeAccounts(['z1' => ['displayName' => 'Zoe', 'token' => 'geheim'], 'a1' => ['displayName' => 'Anna']]);
    check('NormalizeAccounts nur ID+Name, sortiert', $acc === [['id' => 'a1', 'name' => 'Anna'], ['id' => 'z1', 'name' => 'Zoe']], json_encode($acc));
    $st = Helper::NormalizeStatus(['mode' => 'play', 'mixer volume' => -30, 'playlist_tracks' => 3, 'remoteMeta' => ['title' => 'T', 'artist' => 'A', 'artwork_url' => 'https://x/y.jpg']]);
    check('NormalizeStatus Stumm + remoteMeta', $st['muted'] && $st['volume'] === 30 && $st['title'] === 'T' && $st['coverKey'] !== null, json_encode($st));

    // ------------------------------------------------------------------ Integration
    section('Integration gegen Fake-LMS');
    $port = 19077;
    $stateFile = sys_get_temp_dir() . '/fake_lms_state_' . getmypid() . '.json';
    @unlink($stateFile);
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake_lms.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['LMS_STATE' => $stateFile]
    );
    register_shutdown_function(static function () use ($server, $stateFile): void {
        proc_terminate($server);
        @unlink($stateFile);
    });
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    $fake = static fn (): array => json_decode((string) file_get_contents($stateFile), true);

    IPS\Kernel::reset();
    IPS\ModuleLoader::loadLibrary($stubs . '/IOStubs/library.json');
    IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

    $io = IPS_CreateInstance('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
    IPS_SetName($io, 'Client Socket');
    IPS_ApplyChanges($io);
    $id = IPS_CreateInstance('{91D1C7C4-ADEB-4A3B-A4B4-F288AF7D5140}');
    IPS_ConnectInstance($id, $io);
    $module = IPS\InstanceManager::getInstanceInterface($id);

    // Ohne Host: Status 104
    IPS_ApplyChanges($id);
    check('Ohne Host → Status 104', IPS_GetInstance($id)['InstanceStatus'] === 104, (string) IPS_GetInstance($id)['InstanceStatus']);

    IPS_SetProperty($id, 'Host', 'http://127.0.0.1:9000/');   // absichtlich "unsauber"
    IPS_SetProperty($id, 'WebPort', $port);
    IPS_ApplyChanges($id);
    check('Mit Host → aktiv (Host normalisiert)', IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, (string) IPS_GetInstance($id)['InstanceStatus']);
    check('Client Socket vom Modul geöffnet', IPS_GetProperty($io, 'Open') === true);
    check('Client Socket sinnvoll benannt', IPS_GetName($io) === 'Lyrion CLI (127.0.0.1)', IPS_GetName($io));
    $formActions = json_decode($module->GetConfigurationForm(), true)['actions'];
    check('Formular zeigt Zustand der Live-Verbindung', str_starts_with($formActions[0]['caption'], 'Live events:'), $formActions[0]['caption']);

    $cli = [];
    $ioIf = IPS\InstanceManager::getInstanceInterface($io);
    while ($ioIf->HasText()) {
        $cli[] = $ioIf->PopText();
    }
    check('CLI subscribe gesendet', count($cli) > 0 && str_starts_with($cli[0], 'subscribe ') && str_ends_with($cli[0], "\n"), json_encode($cli));

    $visu = static function () use ($module): array {
        $out = [];
        foreach ($module->getMessages() as $msg) {
            if ($msg['Message'] === 10541) {
                $out[] = json_decode($msg['Data'][0], true);
            }
        }
        return $out;
    };
    $lastState = static function () use ($visu): ?array {
        $state = null;
        foreach ($visu() as $m) {
            if (($m['t'] ?? '') === 'state') {
                $state = $m;
            }
        }
        return $state;
    };
    $runDebounce = static function () use ($id): void {
        IPS_RequestAction($id, 'Debounce', 0);
    };

    $s = $lastState();
    check('Zustand an Kachel gesendet', $s !== null && $s['online'] === true);
    check('4 Player erkannt', count($s['players']) === 4, (string) count($s['players'] ?? []));
    $zoneIds = array_map(static fn ($z) => $z['id'] . ':' . count($z['members']), $s['zones']);
    check('Zonen: Wohnzimmer+Küche, Kinderzimmer, Bad', $zoneIds === ['aa:bb:cc:00:00:04:1', 'aa:bb:cc:00:00:03:1', 'aa:bb:cc:00:00:01:2'], json_encode($zoneIds));
    $wz = array_values(array_filter($s['zones'], static fn ($z) => $z['id'] === 'aa:bb:cc:00:00:01'))[0];
    check('Zone EG: Titel, Konto, Cover-Key', $wz['title'] === 'Golden Hour Drive' && $wz['accountName'] === 'Dennis' && $wz['cover'] !== null, json_encode($wz));
    $kz = array_values(array_filter($s['zones'], static fn ($z) => $z['id'] === 'aa:bb:cc:00:00:03'))[0];
    check('Kinderzimmer: eigenes Konto Familie', $kz['accountName'] === 'Familie' && $kz['mode'] === 'pause');
    check('Spotify erkannt, 2 Konten', $s['spotify'] === true && count($s['accounts']) === 2);
    check('Musikquellen erkannt: Bibliothek, Favoriten, Spotify', $s['sources'] === ['library', 'favorites', 'spotify'], json_encode($s['sources']));
    $covers = array_values(array_filter($visu(), static fn ($m) => ($m['t'] ?? '') === 'covers'));
    check('Cover als data-URI gesendet', count($covers) > 0 && str_starts_with((string) reset($covers[0]['covers']), 'data:image/jpeg;base64,'));
    check('Keine Token im Zustand', !str_contains(json_encode($s), 'spotifyUserId'));

    // Kachel-HTML
    $tile = $module->GetVisualizationTile();
    check('Kachel enthält Startzustand per handleMessage', str_contains($tile, 'handleMessage(') && str_contains($tile, 'function handleMessage'));
    check('Kachel-JSON gegen </script> geschützt', !preg_match('#handleMessage\([^)]*</script#', $tile));

    // Bedienung: Raum zur Zone hinzufügen
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'setZone', 'p' => 'aa:bb:cc:00:00:01', 'v' => ['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02', 'aa:bb:cc:00:00:04']]));
    $f = $fake();
    check('Fake-LMS: Bad in Gruppe und eingeschaltet', $f['groups'] === [['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02', 'aa:bb:cc:00:00:04']] && $f['players']['aa:bb:cc:00:00:04']['power'] === 1, json_encode($f['groups']));
    $runDebounce();
    $s = $lastState();
    $big = array_values(array_filter($s['zones'], static fn ($z) => $z['id'] === 'aa:bb:cc:00:00:01'))[0];
    check('Kachel: Zone EG hat jetzt 3 Räume', count($big['members']) === 3 && count($s['zones']) === 2, json_encode(array_column($s['zones'], 'members')));

    // Kinderzimmer übernehmen (spielt eigenes Programm) und Küche entfernen
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'setZone', 'p' => 'aa:bb:cc:00:00:01', 'v' => ['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:04', 'aa:bb:cc:00:00:03']]));
    $runDebounce();
    $f = $fake();
    check('Fake-LMS: Küche raus, Kinderzimmer rein', $f['groups'] === [['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:04', 'aa:bb:cc:00:00:03']], json_encode($f['groups']));

    // Zone auflösen
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'setZone', 'p' => 'aa:bb:cc:00:00:01', 'v' => []]));
    $runDebounce();
    check('Zone aufgelöst', $fake()['groups'] === [], json_encode($fake()['groups']));
    check('Kachel: 4 Einzelzonen', count($lastState()['zones']) === 4);

    // Lautstärke, Pause, Konto
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'vol', 'p' => 'aa:bb:cc:00:00:02', 'v' => 140]));
    check('Lautstärke begrenzt auf 100', $fake()['players']['aa:bb:cc:00:00:02']['volume'] === 100);
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'toggle', 'p' => 'aa:bb:cc:00:00:01']));
    check('Toggle pausiert spielenden Player', $fake()['players']['aa:bb:cc:00:00:01']['mode'] === 'pause');
    $runDebounce();
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'toggle', 'p' => 'aa:bb:cc:00:00:01']));
    check('Toggle setzt pausierten fort (pause 0)', $fake()['players']['aa:bb:cc:00:00:01']['mode'] === 'play' && in_array('aa:bb:cc:00:00:01 pause 0', $fake()['log'], true));
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'account', 'p' => 'aa:bb:cc:00:00:01', 'v' => 'acc_family']));
    check('Konto per playerpref gesetzt', ($fake()['playerAccount']['aa:bb:cc:00:00:01'] ?? '') === 'acc_family');
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'account', 'p' => 'aa:bb:cc:00:00:01', 'v' => 'gibtsnicht']));
    check('Unbekanntes Konto ignoriert', ($fake()['playerAccount']['aa:bb:cc:00:00:01'] ?? '') === 'acc_family');
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'vol', 'p' => 'ff:ff:ff:ff:ff:ff', 'v' => 10]));
    check('Unbekannter Player ignoriert', !str_contains(implode("\n", $fake()['log']), 'ff:ff:ff:ff:ff:ff'));

    // Browse
    $before = count($visu());
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'spotify', 'id' => '', 'req' => 'r1']));
    $new = array_slice($visu(), $before);
    $browse = array_values(array_filter($new, static fn ($m) => ($m['t'] ?? '') === 'browse'))[0] ?? null;
    check('Browse Root ohne Kontoumschalter', $browse !== null && $browse['req'] === 'r1' && count($browse['items']) === 3 && $browse['items'][1]['type'] === 'search', json_encode($browse));
    $thumbs = array_values(array_filter($new, static fn ($m) => ($m['t'] ?? '') === 'thumbs'))[0] ?? null;
    check('Vorschaubilder nachgeliefert', $thumbs !== null && count($thumbs['images']) === 3);
    $before = count($visu());
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'spotify', 'id' => 'ab12.1', 'req' => 'r2']));
    $browse = array_values(array_filter(array_slice($visu(), $before), static fn ($m) => ($m['t'] ?? '') === 'browse'))[0];
    check('Browse nutzt Konto des Players (Familie)', $browse['items'][0]['name'] === 'Die Waldbande', $browse['items'][0]['name']);
    $before = count($visu());
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'spotify', 'id' => 'ab12.2', 'q' => 'jazz', 'req' => 'r3']));
    $browse = array_values(array_filter(array_slice($visu(), $before), static fn ($m) => ($m['t'] ?? '') === 'browse'))[0];
    check('Suche übergibt search:', $browse['items'][0]['name'] === 'Jazz – Treffer 1' && in_array('aa:bb:cc:00:00:01 spoton items 0 200 want_url:0 item_id:ab12.2 search:jazz', $fake()['log'], true));
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'playItem', 'p' => 'aa:bb:cc:00:00:03', 'src' => 'spotify', 'id' => 'ab12.1.0', 'v' => 'play']));
    $runDebounce();
    $kz = array_values(array_filter($lastState()['zones'], static fn ($z) => $z['id'] === 'aa:bb:cc:00:00:03'))[0];
    check('Titel aus Spotify gestartet', $kz['title'] === 'Gestartet ab12.1.0' && $kz['mode'] === 'play', json_encode($kz));

    // Lokale Bibliothek und Favoriten
    $browseMsg = static function (array $request) use ($id, $visu): ?array {
        $before = count($visu());
        IPS_RequestAction($id, 'UI', json_encode($request));
        return array_values(array_filter(array_slice($visu(), $before), static fn ($m) => ($m['t'] ?? '') === 'browse'))[0] ?? null;
    };
    $lib = $browseMsg(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'library', 'root' => 'artists', 'id' => '', 'req' => 'l1']);
    check('Bibliothek: Interpreten', $lib !== null && $lib['items'][1]['name'] === 'Hans Zimmer' && in_array('aa:bb:cc:00:00:01 browselibrary items 0 200 want_url:0 mode:artists', $fake()['log'], true), json_encode($lib));
    $lib = $browseMsg(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'library', 'root' => 'artists', 'id' => 'lib.1', 'req' => 'l2']);
    check('Bibliothek: Unterebene behält den Einstieg (mode)', count($lib['items']) === 3 && in_array('aa:bb:cc:00:00:01 browselibrary items 0 200 want_url:0 mode:artists item_id:lib.1', $fake()['log'], true));
    $lib = $browseMsg(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'library', 'root' => 'search', 'id' => 'lib.2', 'q' => 'zimmer', 'req' => 'l3']);
    check('Bibliothek: Suche', ($lib['items'][0]['name'] ?? '') === 'Fund: zimmer');
    $lib = $browseMsg(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'library', 'root' => 'evil', 'id' => '', 'req' => 'l4']);
    check('Bibliothek: unbekannter Einstieg abgelehnt', $lib !== null && ($lib['error'] ?? false) === true);
    IPS_RequestAction($id, 'UI', json_encode(['c' => 'playItem', 'p' => 'aa:bb:cc:00:00:02', 'src' => 'library', 'root' => 'albums', 'id' => 'lib.0.1', 'v' => 'insert']));
    check('Bibliothek: Titel einreihen', in_array('aa:bb:cc:00:00:02 browselibrary playlist insert item_id:lib.0.1 mode:albums', $fake()['log'], true));
    $fav = $browseMsg(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'favorites', 'id' => '', 'req' => 'f1']);
    check('Favoriten: Liste', count($fav['items']) === 3 && $fav['items'][0]['name'] === 'Deutschlandfunk');
    check('LMR_PlayFavorite per Name', $module->PlayFavorite('Küche', 'WDR 2') === true && in_array('aa:bb:cc:00:00:02 favorites playlist play item_id:fav.1', $fake()['log'], true));
    check('LMR_PlayFavorite per Position', $module->PlayFavorite('Küche', '1') === true && in_array('aa:bb:cc:00:00:02 favorites playlist play item_id:fav.0', $fake()['log'], true));
    $runDebounce();

    // Live-Ereignis über den CLI-Socket
    $f = $fake();
    $f['players']['aa:bb:cc:00:00:03']['volume'] = 77;
    file_put_contents($stateFile, json_encode($f));
    $ioIf->PushText("aa%3Abb%3Acc%3A00%3A00%3A03 mixer volume 7");
    $ioIf->PushText("7\n");
    $dirty = json_decode((string) (function () { return $this->GetBuffer('Dirty'); })->call($module), true);
    check('CLI-Ereignis über zwei Pakete zusammengesetzt', $dirty === ['aa:bb:cc:00:00:03'], json_encode($dirty));
    $runDebounce();
    $kzp = array_values(array_filter($lastState()['players'], static fn ($p) => $p['id'] === 'aa:bb:cc:00:00:03'))[0];
    check('Lautstärke nach Ereignis aktualisiert', $kzp['volume'] === 77);

    // Neuer Player taucht auf
    $f = $fake();
    $f['players']['aa:bb:cc:00:00:05'] = ['name' => 'Terrasse', 'power' => 0, 'connected' => 1, 'mode' => 'stop', 'time' => 0.0, 'volume' => 40, 'shuffle' => 0, 'repeat' => 0, 'tracks' => 0, 'track' => null];
    file_put_contents($stateFile, json_encode($f));
    $ioIf->PushText("aa%3Abb%3Acc%3A00%3A00%3A05 client new\n");
    $runDebounce();
    check('Neuer Player erscheint automatisch', count($lastState()['players']) === 5);

    // Konfiguration: Bereich + Ausblenden
    IPS_SetProperty($id, 'Players', json_encode([
        ['id' => 'aa:bb:cc:00:00:01', 'name' => 'Wohnzimmer', 'area' => 'Erdgeschoss', 'visible' => true],
        ['id' => 'aa:bb:cc:00:00:04', 'name' => 'Bad', 'area' => 'Obergeschoss', 'visible' => false]
    ]));
    IPS_ApplyChanges($id);
    $s = $lastState();
    check('Ausgeblendeter Player fehlt, Reihenfolge aus Liste', count($s['players']) === 4 && $s['players'][0]['id'] === 'aa:bb:cc:00:00:01' && $s['players'][0]['area'] === 'Erdgeschoss', json_encode(array_column($s['players'], 'name')));
    $form = json_decode($module->GetConfigurationForm(), true);
    $list = $form['elements'][1]['items'][1]['values'];
    check('Formular listet gespeicherte + neue Player', count($list) === 5 && $list[2]['rowColor'] === '#E8F5E9', json_encode(array_column($list, 'name')));

    // Öffentliche Funktionen
    check('LMR_SetVolume per Name', $module->SetVolume('Küche', 33) === true && $fake()['players']['aa:bb:cc:00:00:02']['volume'] === 33);
    check('LMR_SetZone per Namen', $module->SetZone('Wohnzimmer', 'Wohnzimmer, Küche') === true && $fake()['groups'] === [['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02']]);
    $runDebounce();
    check('LMR_SetSpotifyAccount per Kontoname', $module->SetSpotifyAccount('Küche', 'Dennis') === true && $fake()['playerAccount']['aa:bb:cc:00:00:02'] === 'acc_dennis');
    check('LMR_GetSpotifyAccounts', json_decode($module->GetSpotifyAccounts(), true)[0]['name'] === 'Dennis');
    $res = json_decode($module->SendCommand('', '["serverstatus","0","1"]'), true);
    check('LMR_SendCommand', ($res['version'] ?? '') === '9.1.1');
    check('LMR_SetZoneVolume (relativ)', (function () {
        $this->SetZoneVolume('aa:bb:cc:00:00:01', 50);
        return true;
    })->call($module) && abs($fake()['players']['aa:bb:cc:00:00:01']['volume'] - $fake()['players']['aa:bb:cc:00:00:02']['volume']) === abs(35 - 33));

    // Spotify abschalten (für Nutzer ohne SpotOn)
    IPS_SetProperty($id, 'SpotifyEnabled', false);
    IPS_ApplyChanges($id);
    $s = $lastState();
    check('Spotify abgeschaltet: keine Spotify-Quelle, keine Konten', $s['spotify'] === false && $s['sources'] === ['library', 'favorites'] && $s['accounts'] === [], json_encode($s['sources']));
    $logBefore = count($fake()['log']);
    IPS_RequestAction($id, 'Poll', 0);
    $newLog = implode("\n", array_slice($fake()['log'], $logBefore));
    check('Spotify abgeschaltet: keine SpotOn-Abfragen mehr', !str_contains($newLog, 'spoton') && !str_contains($newLog, 'plugin.spoton'), $newLog);
    $sp = $browseMsg(['c' => 'browse', 'p' => 'aa:bb:cc:00:00:01', 'src' => 'spotify', 'id' => '', 'req' => 's9']);
    check('Spotify abgeschaltet: Browse abgelehnt', ($sp['error'] ?? false) === true);
    IPS_SetProperty($id, 'SpotifyEnabled', true);
    IPS_ApplyChanges($id);

    // LMS offline
    proc_terminate($server);
    usleep(300000);
    IPS_RequestAction($id, 'Poll', 0);
    check('LMS weg → Status 202, Kachel offline', IPS_GetInstance($id)['InstanceStatus'] === 202 && $lastState()['online'] === false);

    echo "\n$passed bestanden, $failures fehlgeschlagen\n";
    exit($failures > 0 ? 1 : 0);
}
