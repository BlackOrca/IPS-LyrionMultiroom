<?php

declare(strict_types=1);

/**
 * Minimaler Fake eines Lyrion Music Server für Tests (php -S 127.0.0.1:19000 tests/fake_lms.php).
 * Bildet JSON-RPC (/jsonrpc.js) und Cover-URLs nach. Zustand liegt in einer JSON-Datei.
 */

$stateFile = getenv('LMS_STATE') ?: sys_get_temp_dir() . '/fake_lms_state.json';

function initialState(): array
{
    $track = static fn (string $title, string $artist, string $album, string $art, float $dur) => [
        'title' => $title, 'artist' => $artist, 'album' => $album, 'artwork_url' => $art, 'duration' => $dur
    ];
    return [
        'players' => [
            'aa:bb:cc:00:00:01' => ['name' => 'Wohnzimmer', 'power' => 1, 'connected' => 1, 'mode' => 'play', 'time' => 102.0, 'volume' => 35, 'shuffle' => 0, 'repeat' => 0, 'tracks' => 12,
                'track' => $track('Golden Hour Drive', 'Nordlicht Kollektiv', 'Weite Straßen', 'https://i.example/cover-a.jpg', 238)],
            'aa:bb:cc:00:00:02' => ['name' => 'Küche', 'power' => 1, 'connected' => 1, 'mode' => 'play', 'time' => 102.0, 'volume' => 28, 'shuffle' => 0, 'repeat' => 0, 'tracks' => 12,
                'track' => $track('Golden Hour Drive', 'Nordlicht Kollektiv', 'Weite Straßen', 'https://i.example/cover-a.jpg', 238)],
            'aa:bb:cc:00:00:03' => ['name' => 'Kinderzimmer', 'power' => 1, 'connected' => 1, 'mode' => 'pause', 'time' => 1450.0, 'volume' => 18, 'shuffle' => 0, 'repeat' => 0, 'tracks' => 1,
                'track' => $track('Die Waldbande, Folge 12', 'Hörspiel', '', 'https://i.example/cover-b.jpg', 2370)],
            'aa:bb:cc:00:00:04' => ['name' => 'Bad', 'power' => 0, 'connected' => 1, 'mode' => 'stop', 'time' => 0.0, 'volume' => 20, 'shuffle' => 0, 'repeat' => 0, 'tracks' => 0, 'track' => null]
        ],
        'groups' => [['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02']],
        'accounts' => ['acc_dennis' => ['displayName' => 'Dennis', 'spotifyUserId' => 'x1'], 'acc_family' => ['displayName' => 'Familie', 'spotifyUserId' => 'x2']],
        'activeAccount' => 'acc_dennis',
        'playerAccount' => ['aa:bb:cc:00:00:03' => 'acc_family'],
        'log' => []
    ];
}

$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
if (!is_array($state)) {
    $state = initialState();
}
$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
};

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ----------------------------------------------------------------- Bilder
if (str_starts_with($uri, '/music/') || str_starts_with($uri, '/imageproxy/') || str_starts_with($uri, '/plugins/') || str_starts_with($uri, '/html/')) {
    $seed = crc32($_SERVER['REQUEST_URI']);
    $img = imagecreatetruecolor(64, 64);
    $bg = imagecolorallocate($img, ($seed >> 16) & 0x7F, ($seed >> 8) & 0x5F, $seed & 0x9F);
    $fg = imagecolorallocate($img, 255, 181, 71);
    imagefilledrectangle($img, 0, 0, 63, 63, $bg);
    imagefilledellipse($img, 40, 22, 28, 28, $fg);
    header('Content-Type: image/jpeg');
    imagejpeg($img, null, 80);
    return true;
}

if ($uri !== '/jsonrpc.js') {
    http_response_code(404);
    echo 'not found';
    return true;
}

// ----------------------------------------------------------------- JSON-RPC
$req = json_decode((string) file_get_contents('php://input'), true);
[$player, $cmd] = $req['params'];
$cmd = array_map('strval', $cmd);
$state['log'][] = trim($player . ' ' . implode(' ', $cmd));
$result = [];

$tagged = static function (array $cmd, string $tag): ?string {
    foreach ($cmd as $part) {
        if (str_starts_with($part, $tag . ':')) {
            return substr($part, strlen($tag) + 1);
        }
    }
    return null;
};
$groupOf = static function (string $id) use (&$state): ?int {
    foreach ($state['groups'] as $i => $g) {
        if (in_array($id, $g, true)) {
            return $i;
        }
    }
    return null;
};
$peers = static function (string $id) use (&$state, $groupOf): array {
    $g = $groupOf($id);
    return $g === null ? [$id] : $state['groups'][$g];
};

switch ($cmd[0]) {
    case 'serverstatus':
        $loop = [];
        foreach ($state['players'] as $id => $p) {
            $loop[] = ['playerid' => $id, 'name' => $p['name'], 'modelname' => 'SqueezeLite', 'model' => 'squeezelite', 'connected' => $p['connected'], 'power' => $p['power'], 'isplaying' => $p['mode'] === 'play' ? 1 : 0];
        }
        $result = ['version' => '9.1.1', 'player count' => count($loop), 'players_loop' => $loop];
        break;

    case 'syncgroups':
        $loop = [];
        foreach ($state['groups'] as $g) {
            $loop[] = ['sync_members' => implode(',', $g), 'sync_member_names' => implode(',', array_map(static fn ($id) => $state['players'][$id]['name'], $g))];
        }
        $result = $loop ? ['syncgroups_loop' => $loop] : [];
        break;

    case 'status':
        $p = $state['players'][$player] ?? null;
        if ($p === null) {
            $result = ['error' => 'invalid player'];
            break;
        }
        $result = [
            'player_name' => $p['name'], 'player_connected' => $p['connected'], 'power' => $p['power'], 'mode' => $p['mode'],
            'mixer volume' => $p['volume'], 'playlist repeat' => $p['repeat'], 'playlist shuffle' => $p['shuffle'], 'playlist_tracks' => $p['tracks']
        ];
        if ($p['track']) {
            $result['time'] = $p['time'];
            $result['duration'] = $p['track']['duration'];
            $result['can_seek'] = 1;
            $result['remote'] = 1;
            $result['current_title'] = $p['track']['title'];
            $result['remoteMeta'] = $p['track'] + ['id' => -1, 'coverid' => '-123'];
            $result['playlist_loop'] = [['playlist index' => 0, 'id' => -1, 'title' => $p['track']['title'], 'coverid' => '-123']];
        }
        break;

    case 'can':
        $result = ['_can' => ($cmd[1] ?? '') === 'spoton' ? 1 : 0];
        break;

    case 'pref':
        if ($cmd[1] === 'plugin.spoton:accounts') {
            $result = ['_p2' => $state['accounts']];
        } elseif ($cmd[1] === 'plugin.spoton:activeAccount') {
            $result = ['_p2' => $state['activeAccount']];
        }
        break;

    case 'playerpref':
        if ($cmd[1] === 'plugin.spoton:activeAccount') {
            if ($cmd[2] === '?') {
                $result = ['_p2' => $state['playerAccount'][$player] ?? null];
            } else {
                $state['playerAccount'][$player] = $cmd[2];
            }
        }
        break;

    case 'sync':
        if ($cmd[1] === '-') {
            $g = $groupOf($player);
            if ($g !== null) {
                $state['groups'][$g] = array_values(array_diff($state['groups'][$g], [$player]));
                if (count($state['groups'][$g]) < 2) {
                    array_splice($state['groups'], $g, 1);
                }
                $state['groups'] = array_values($state['groups']);
            }
        } else {
            $buddy = $cmd[1];
            $g = $groupOf($player);
            if ($groupOf($buddy) !== null) {
                $result = ['error' => 'buddy already synced'];
                break;
            }
            if ($g === null) {
                $state['groups'][] = [$player, $buddy];
            } else {
                $state['groups'][$g][] = $buddy;
            }
            // Buddy übernimmt Wiedergabe des Masters
            $state['players'][$buddy]['track'] = $state['players'][$player]['track'];
            $state['players'][$buddy]['mode'] = $state['players'][$player]['mode'];
            $state['players'][$buddy]['time'] = $state['players'][$player]['time'];
            $state['players'][$buddy]['tracks'] = $state['players'][$player]['tracks'];
        }
        break;

    case 'power':
        $state['players'][$player]['power'] = (int) $cmd[1];
        break;

    case 'mixer':
        if ($cmd[1] === 'volume') {
            $state['players'][$player]['volume'] = (int) $cmd[2];
        } elseif ($cmd[1] === 'muting') {
            $v = abs($state['players'][$player]['volume']);
            $state['players'][$player]['volume'] = $cmd[2] === '1' ? -$v : $v;
        }
        break;

    case 'pause':
    case 'play':
    case 'stop':
        $mode = $cmd[0] === 'pause' ? (($cmd[1] ?? '1') === '1' ? 'pause' : 'play') : $cmd[0];
        foreach ($peers($player) as $id) {
            $state['players'][$id]['mode'] = $mode;
        }
        break;

    case 'time':
        foreach ($peers($player) as $id) {
            $state['players'][$id]['time'] = (float) $cmd[1];
        }
        break;

    case 'playlist':
        if ($cmd[1] === 'shuffle' || $cmd[1] === 'repeat') {
            foreach ($peers($player) as $id) {
                $state['players'][$id][$cmd[1]] = (int) $cmd[2];
            }
        } elseif ($cmd[1] === 'index') {
            foreach ($peers($player) as $id) {
                $state['players'][$id]['time'] = 0.0;
                if ($state['players'][$id]['track']) {
                    $state['players'][$id]['track']['title'] .= ' ' . $cmd[2];
                }
            }
        }
        break;

    case 'spoton':
        $account = $state['playerAccount'][$player] ?? $state['activeAccount'];
        $name = $state['accounts'][$account]['displayName'] ?? '?';
        if ($cmd[1] === 'items') {
            $id = $tagged($cmd, 'item_id');
            $search = $tagged($cmd, 'search');
            $prefix = 'ab12';
            if ($id === null) {
                $loop = [
                    ['id' => $prefix . '.0', 'name' => 'Aktives Konto: ' . $name, 'type' => 'link', 'image' => 'plugins/SpotOn/html/images/account.png', 'isaudio' => 0, 'hasitems' => 1],
                    ['id' => $prefix . '.1', 'name' => 'Home', 'type' => 'link', 'image' => 'plugins/SpotOn/html/images/home.png', 'isaudio' => 0, 'hasitems' => 1],
                    ['id' => $prefix . '.2', 'name' => 'Suche', 'type' => 'search', 'image' => 'plugins/SpotOn/html/images/search.png', 'isaudio' => 0, 'hasitems' => 1],
                    ['id' => $prefix . '.3', 'name' => 'Bibliothek', 'type' => 'link', 'image' => 'html/images/musicfolder.png', 'isaudio' => 0, 'hasitems' => 1]
                ];
                $result = ['count' => count($loop), 'title' => 'SpotOn', 'loop_loop' => $loop];
            } elseif ($search !== null) {
                $loop = [];
                for ($i = 0; $i < 3; $i++) {
                    $loop[] = ['id' => $id . '.' . $i, 'name' => ucfirst($search) . ' – Treffer ' . ($i + 1), 'type' => 'audio', 'image' => 'https://i.example/s' . $i . '.jpg', 'isaudio' => 1, 'hasitems' => 0];
                }
                $result = ['count' => 3, 'title' => 'Suche', 'loop_loop' => $loop];
            } else {
                $names = $account === 'acc_family'
                    ? ['Die Waldbande', 'Kinderlieder', 'Sonntagsfrühstück', 'Lieblingssongs']
                    : ['Daily Mix 1', 'Discover Weekly', 'Fokus am Schreibtisch', 'Lieblingssongs'];
                $loop = [];
                foreach ($names as $i => $n) {
                    $loop[] = ['id' => $id . '.' . $i, 'name' => $n, 'type' => 'playlist', 'image' => '/imageproxy/https%3A%2F%2Fi.example%2Fp' . $i . '.jpg/image.jpg', 'isaudio' => 1, 'hasitems' => 1];
                }
                $loop[] = ['id' => $id . '.9', 'name' => 'Hinweis: nur Text', 'type' => 'text', 'isaudio' => 0, 'hasitems' => 0];
                $result = ['count' => count($loop), 'title' => 'Liste', 'loop_loop' => $loop];
            }
        } elseif ($cmd[1] === 'playlist') {
            $id = $tagged($cmd, 'item_id');
            foreach ($peers($player) as $pid) {
                $state['players'][$pid]['track'] = ['title' => 'Gestartet ' . $id, 'artist' => 'Konto ' . $name, 'album' => '', 'artwork_url' => 'https://i.example/' . md5((string) $id) . '.jpg', 'duration' => 200];
                $state['players'][$pid]['mode'] = 'play';
                $state['players'][$pid]['time'] = 0.0;
                $state['players'][$pid]['tracks'] = 5;
                $state['players'][$pid]['power'] = 1;
            }
        }
        break;

    default:
        $result = [];
}

$save();
header('Content-Type: application/json');
echo json_encode(['id' => $req['id'] ?? 1, 'method' => 'slim.request', 'params' => $req['params'], 'result' => $result]);
return true;
