<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/LyrionHelper.php';

use LyrionMultiroom\Helper;

/**
 * Lyrion Multiroom
 *
 * Eigenständige Anbindung des Lyrion Music Server (LMS) mit HTML-Kachel für die Kachel-Visualisierung.
 * - Befehle und Abfragen laufen über JSON-RPC (http://<host>:<webport>/jsonrpc.js).
 * - Live-Ereignisse kommen über den CLI-Port (Client Socket als Parent, "subscribe").
 * - Player werden dynamisch erkannt; Zonen ergeben sich aus den Sync-Gruppen.
 * - Spotify über das LMS-Plugin SpotOn (Kontowahl pro Player über "playerpref").
 */
class LyrionMultiroom extends IPSModuleStrict
{
    private const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const IO_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    private const STATUS_NO_HOST = 104;
    private const STATUS_UNREACHABLE = 202;

    private const MAX_COVERS = 10;
    private const MAX_THUMBS = 60;
    private const THUMB_SIZE = 120;
    private const MAX_IMAGE_BYTES = 600000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('WebPort', 9000);
        $this->RegisterPropertyInteger('CliPort', 9090);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Players', '[]');
        $this->RegisterPropertyString('SpotifyPlugin', 'spoton');
        $this->RegisterPropertyInteger('CoverSize', 500);
        $this->RegisterPropertyBoolean('PowerOnJoin', true);
        $this->RegisterPropertyInteger('PollInterval', 30);

        $this->RegisterTimer('Debounce', 0, 'IPS_RequestAction($_IPS["TARGET"], "Debounce", 0);');
        $this->RegisterTimer('Poll', 0, 'IPS_RequestAction($_IPS["TARGET"], "Poll", 0);');

        $this->SetVisualizationType(1);

        $this->SetBuffer('ParentID', '0');
        $this->SetBuffer('RX', '');
        $this->SetBuffer('Dirty', '[]');
        $this->SetBuffer('State', '{}');
        $this->SetBuffer('Covers', '{}');
        $this->SetBuffer('LastHash', '');

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetBuffer('RX', '');
        $this->SetBuffer('LastHash', '');
        $this->TrackParent();
        $this->ConfigureParent();

        if ($this->Host() === '') {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(self::STATUS_NO_HOST);
            return;
        }

        $this->SetTimerInterval('Poll', max(0, $this->ReadPropertyInteger('PollInterval')) * 1000);
        if ($this->HasActiveParent()) {
            $this->Subscribe();
        }
        $this->FullRefresh();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->UnregisterMessage(0, IPS_KERNELSTARTED);
                $this->ApplyChanges();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->TrackParent();
                $this->ConfigureParent();
                if ($this->HasActiveParent()) {
                    $this->Subscribe();
                    $this->QueueRefresh('*');
                }
                break;
            case IM_CHANGESTATUS:
                if ($SenderID === (int) $this->GetBuffer('ParentID') && ($Data[0] ?? 0) === IS_ACTIVE) {
                    $this->SetBuffer('RX', '');
                    $this->Subscribe();
                    $this->QueueRefresh('*');
                }
                break;
        }
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [self::CLIENT_SOCKET]]);
    }

    public function GetConfigurationForParent(): string
    {
        $host = $this->Host();
        return json_encode([
            'Host' => $host,
            'Port' => $this->ReadPropertyInteger('CliPort'),
            'Open' => $host !== ''
        ]);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $state = $this->LoadState();
        $live = $state['players'] ?? [];
        $rows = [];
        $seen = [];
        foreach ($this->PlayerSettingsList() as $row) {
            $id = $row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $entry = [
                'id'      => $id,
                'name'    => (string) ($live[$id]['name'] ?? ($row['name'] ?? $id)),
                'area'    => (string) ($row['area'] ?? ''),
                'visible' => (bool) ($row['visible'] ?? true)
            ];
            if (!isset($live[$id])) {
                $entry['rowColor'] = '#DFDFDF'; // aktuell nicht im LMS bekannt
            }
            $rows[] = $entry;
        }
        $new = array_values(array_filter(array_keys($live), static fn ($id) => !isset($seen[$id])));
        usort($new, static fn ($a, $b) => strcasecmp((string) $live[$a]['name'], (string) $live[$b]['name']));
        foreach ($new as $id) {
            // neu entdeckt, noch nicht gespeichert
            $rows[] = ['id' => $id, 'name' => (string) $live[$id]['name'], 'area' => '', 'visible' => true, 'rowColor' => '#E8F5E9'];
        }
        $this->SetFormListValues($form['elements'], 'Players', $rows);
        array_unshift($form['actions'], ['type' => 'Label', 'caption' => $this->ConnectionInfo()]);
        return json_encode($form);
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $chunk = (string) ($data['Buffer'] ?? '');
        if ($chunk !== '' && strlen($chunk) % 2 === 0 && ctype_xdigit($chunk)) {
            $chunk = (string) hex2bin($chunk);
        }
        $buffer = $this->GetBuffer('RX') . $chunk;
        $lines = explode("\n", $buffer);
        $rest = (string) array_pop($lines);
        $this->SetBuffer('RX', strlen($rest) > 65536 ? '' : $rest);

        $namespace = $this->PrefNamespace();
        foreach ($lines as $line) {
            $notification = Helper::ParseNotification($line);
            if ($notification === null) {
                continue;
            }
            $scope = Helper::RefreshScope($notification, $namespace);
            if ($scope !== null) {
                $this->SendDebug('Event', trim($line), 0);
                $this->QueueRefresh($scope);
            }
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Debounce':
                $this->SetTimerInterval('Debounce', 0);
                $this->ProcessDirty();
                return;
            case 'Poll':
                $this->FullRefresh();
                return;
            case 'UI':
                $this->HandleUI((string) $Value);
                return;
            case 'TestConnection':
                echo $this->TestConnection();
                return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/module.html');
        $ui = $this->BuildUIState($this->LoadState());
        $covers = $this->LoadCovers();
        $needed = [];
        foreach ($ui['zones'] as $zone) {
            $key = $zone['cover'] ?? null;
            if ($key !== null && isset($covers[$key])) {
                $needed[$key] = $covers[$key];
            }
        }
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $init = '<script>';
        if (count($needed) > 0) {
            $init .= 'handleMessage(' . json_encode(json_encode(['t' => 'covers', 'covers' => $needed]), $flags) . ');';
        }
        $init .= 'handleMessage(' . json_encode(json_encode($ui), $flags) . ');';
        $init .= '</script>';
        return $html . $init;
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen (LMR_*)
    // ---------------------------------------------------------------------

    /** Liest Player, Zonen und Konten neu ein. */
    public function Refresh(): bool
    {
        return $this->FullRefresh();
    }

    /** Liefert den Zustand (Player, Zonen, Konten) als JSON. */
    public function GetState(): string
    {
        return json_encode($this->BuildUIState($this->LoadState()));
    }

    /** Liefert alle bekannten Player als JSON (ID, Name, Bereich, Status). */
    public function GetPlayers(): string
    {
        $ui = $this->BuildUIState($this->LoadState());
        return json_encode(array_values($ui['players']));
    }

    public function Play(string $Player): bool
    {
        $id = $this->ResolvePlayer($Player);
        return $id !== null && $this->ResumeOrPlay($id);
    }

    public function Pause(string $Player): bool
    {
        return $this->PlayerCommand($Player, ['pause', '1']);
    }

    public function TogglePause(string $Player): bool
    {
        $id = $this->ResolvePlayer($Player);
        if ($id === null) {
            return false;
        }
        $mode = $this->LoadState()['players'][$id]['mode'] ?? 'stop';
        return $mode === 'play' ? $this->PlayerCommand($id, ['pause', '1']) : $this->ResumeOrPlay($id);
    }

    public function Stop(string $Player): bool
    {
        return $this->PlayerCommand($Player, ['stop']);
    }

    public function Next(string $Player): bool
    {
        return $this->PlayerCommand($Player, ['playlist', 'index', '+1']);
    }

    public function Previous(string $Player): bool
    {
        return $this->PlayerCommand($Player, ['playlist', 'index', '-1']);
    }

    public function SetVolume(string $Player, int $Volume): bool
    {
        return $this->PlayerCommand($Player, ['mixer', 'volume', (string) max(0, min(100, $Volume))]);
    }

    public function SetPower(string $Player, bool $Power): bool
    {
        return $this->PlayerCommand($Player, ['power', $Power ? '1' : '0']);
    }

    public function PlayURL(string $Player, string $URL): bool
    {
        return $this->PlayerCommand($Player, ['playlist', 'play', $URL]);
    }

    /**
     * Setzt die Mitglieder einer Zone. $Members: kommagetrennte Player-IDs oder Namen, der erste ist Master.
     */
    public function SetZone(string $Master, string $Members): bool
    {
        $master = $this->ResolvePlayer($Master);
        if ($master === null) {
            return false;
        }
        $wanted = [];
        foreach (explode(',', $Members) as $member) {
            $member = trim($member);
            if ($member === '') {
                continue;
            }
            $id = $this->ResolvePlayer($member);
            if ($id === null) {
                trigger_error('Unknown player: ' . $member, E_USER_NOTICE);
                return false;
            }
            $wanted[] = $id;
        }
        return $this->ApplyZone($master, $wanted);
    }

    public function JoinZone(string $Master, string $Player): bool
    {
        $master = $this->ResolvePlayer($Master);
        $player = $this->ResolvePlayer($Player);
        if ($master === null || $player === null) {
            return false;
        }
        $members = $this->ZoneMembers($master);
        $members[] = $player;
        return $this->ApplyZone($members[0], $members);
    }

    public function LeaveZone(string $Player): bool
    {
        return $this->PlayerCommand($Player, ['sync', '-'], '*');
    }

    /** Liefert die SpotOn-Konten als JSON [{id, name}]. */
    public function GetSpotifyAccounts(): string
    {
        return json_encode($this->LoadState()['accounts'] ?? []);
    }

    /** Wählt das Spotify-Konto für einen Player (in einer Zone zählt der Master). */
    public function SetSpotifyAccount(string $Player, string $Account): bool
    {
        $id = $this->ResolvePlayer($Player);
        $account = $this->ResolveAccount($Account);
        if ($id === null || $account === null) {
            return false;
        }
        return $this->PlayerCommand($id, ['playerpref', $this->PrefNamespace() . ':activeAccount', $account]);
    }

    /**
     * Sendet einen beliebigen LMS-Befehl. $Command ist ein JSON-Array, z. B. '["playlist","play","http://..."]'.
     * Für Serverbefehle $Player leer lassen. Liefert das Ergebnis als JSON.
     */
    public function SendCommand(string $Player, string $Command): string
    {
        $cmd = json_decode($Command, true);
        if (!is_array($cmd) || !array_is_list($cmd)) {
            trigger_error('Command must be a JSON array', E_USER_NOTICE);
            return 'null';
        }
        $id = '';
        if ($Player !== '') {
            $id = $this->ResolvePlayer($Player);
            if ($id === null) {
                return 'null';
            }
        }
        $result = $this->Rpc($id, array_map(static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $cmd));
        if ($id !== '') {
            $this->QueueRefresh($id);
        }
        return json_encode($result);
    }

    // ---------------------------------------------------------------------
    // Bedienung aus der Kachel
    // ---------------------------------------------------------------------

    private function HandleUI(string $payload): void
    {
        $msg = json_decode($payload, true);
        if (!is_array($msg) || !is_string($msg['c'] ?? null)) {
            return;
        }
        $this->SendDebug('UI', $payload, 0);
        $state = $this->LoadState();
        $players = $state['players'] ?? [];
        $c = $msg['c'];
        $p = strtolower((string) ($msg['p'] ?? ''));
        $v = $msg['v'] ?? null;

        $global = ['hello', 'refresh', 'pauseAll'];
        if (!in_array($c, $global, true) && !isset($players[$p])) {
            $this->SendDebug('UI', 'Unbekannter Player: ' . $p, 0);
            return;
        }

        switch ($c) {
            case 'hello':
                $this->SetBuffer('LastHash', '');
                $this->Publish();
                $this->QueueRefresh('*');
                return;
            case 'refresh':
                $this->FullRefresh();
                return;
            case 'toggle':
                if (($players[$p]['mode'] ?? '') === 'play') {
                    $this->Rpc($p, ['pause', '1']);
                } else {
                    $this->ResumeOrPlay($p);
                }
                break;
            case 'next':
                $this->Rpc($p, ['playlist', 'index', '+1']);
                break;
            case 'prev':
                $this->Rpc($p, ['playlist', 'index', '-1']);
                break;
            case 'vol':
                $this->Rpc($p, ['mixer', 'volume', (string) max(0, min(100, (int) $v))]);
                break;
            case 'mute':
                $this->Rpc($p, ['mixer', 'muting', $v ? '1' : '0']);
                break;
            case 'zoneVol':
                $this->SetZoneVolume($p, (int) $v);
                $this->QueueRefresh('*');
                return;
            case 'power':
                $this->Rpc($p, ['power', $v ? '1' : '0']);
                break;
            case 'seek':
                $this->Rpc($p, ['time', (string) max(0, (float) $v)]);
                break;
            case 'shuffle':
                $this->Rpc($p, ['playlist', 'shuffle', (string) max(0, min(2, (int) $v))]);
                break;
            case 'repeat':
                $this->Rpc($p, ['playlist', 'repeat', (string) max(0, min(2, (int) $v))]);
                break;
            case 'setZone':
                $members = array_values(array_filter(
                    array_map(static fn ($id) => strtolower((string) $id), is_array($v) ? $v : []),
                    static fn ($id) => isset($players[$id])
                ));
                $this->ApplyZone($p, $members);
                return;
            case 'pauseAll':
                foreach ($players as $id => $player) {
                    if (($player['mode'] ?? '') === 'play') {
                        $this->Rpc($id, ['pause', '1']);
                    }
                }
                $this->QueueRefresh('*');
                return;
            case 'account':
                $account = $this->ResolveAccount((string) $v);
                if ($account !== null) {
                    $this->Rpc($p, ['playerpref', $this->PrefNamespace() . ':activeAccount', $account]);
                }
                break;
            case 'browse':
                $this->Browse($p, (string) ($msg['id'] ?? ''), (string) ($msg['q'] ?? ''), (string) ($msg['req'] ?? ''));
                return;
            case 'playItem':
                $mode = in_array($v, ['play', 'add', 'insert'], true) ? $v : 'play';
                $item = (string) ($msg['id'] ?? '');
                if ($item !== '') {
                    $this->Rpc($p, [$this->Plugin(), 'playlist', $mode, 'item_id:' . $item], 15);
                }
                break;
            default:
                $this->SendDebug('UI', 'Unbekannter Befehl: ' . $c, 0);
                return;
        }
        $this->QueueRefresh($p);
    }

    private function Browse(string $player, string $itemId, string $query, string $req): void
    {
        $cmd = [$this->Plugin(), 'items', '0', '200', 'want_url:0'];
        if ($itemId !== '') {
            $cmd[] = 'item_id:' . $itemId;
        }
        if ($query !== '') {
            $cmd[] = 'search:' . $query;
        }
        $result = $this->Rpc($player, $cmd, 20);
        if ($result === null) {
            $this->UpdateVisualizationValue(json_encode(['t' => 'browse', 'req' => $req, 'error' => true, 'items' => []]));
            return;
        }
        $items = [];
        $thumbs = [];
        foreach ($result['loop_loop'] ?? [] as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $image = (string) ($row['image'] ?? '');
            // Den Kontoumschalter von SpotOn ausblenden, das erledigt die Kachel selbst.
            if (str_ends_with($image, '/account.png')) {
                continue;
            }
            $id = (string) $row['id'];
            $items[] = [
                'id'    => $id,
                'name'  => (string) ($row['name'] ?? ($row['title'] ?? '')),
                'type'  => (string) ($row['type'] ?? ''),
                'audio' => (bool) ($row['isaudio'] ?? false),
                'items' => (bool) ($row['hasitems'] ?? false)
            ];
            if ($image !== '' && count($thumbs) < self::MAX_THUMBS) {
                $path = Helper::ThumbPath($image, self::THUMB_SIZE);
                if ($path !== null) {
                    $thumbs[$id] = $path;
                }
            }
        }
        $this->UpdateVisualizationValue(json_encode([
            't'     => 'browse',
            'req'   => $req,
            'id'    => $itemId,
            'title' => (string) ($result['title'] ?? ''),
            'items' => $items
        ]));
        if (count($thumbs) > 0) {
            $images = $this->FetchImages($thumbs);
            if (count($images) > 0) {
                $this->UpdateVisualizationValue(json_encode(['t' => 'thumbs', 'req' => $req, 'images' => $images]));
            }
        }
    }

    // ---------------------------------------------------------------------
    // Zonen
    // ---------------------------------------------------------------------

    private function ApplyZone(string $master, array $wanted): bool
    {
        $state = $this->LoadState();
        $plan = Helper::PlanZone($state['groups'] ?? [], $master, $wanted, $this->ReadPropertyBoolean('PowerOnJoin'));
        $this->SendDebug('Zone', json_encode(['master' => $master, 'wanted' => $wanted, 'plan' => $plan]), 0);
        $ok = true;
        foreach ($plan as [$player, $cmd]) {
            $ok = ($this->Rpc($player, $cmd) !== null) && $ok;
        }
        $this->QueueRefresh('*');
        return $ok;
    }

    private function ZoneMembers(string $player): array
    {
        foreach ($this->LoadState()['groups'] ?? [] as $group) {
            if (in_array($player, $group, true)) {
                return $group;
            }
        }
        return [$player];
    }

    /** Verschiebt alle Lautstärken der Zone gemeinsam, Abstände bleiben erhalten. */
    private function SetZoneVolume(string $master, int $target): void
    {
        $state = $this->LoadState();
        $members = $this->ZoneMembers($master);
        $volumes = [];
        foreach ($members as $id) {
            $volumes[$id] = (int) ($state['players'][$id]['volume'] ?? 0);
        }
        $avg = count($volumes) > 0 ? array_sum($volumes) / count($volumes) : 0;
        $delta = max(0, min(100, $target)) - $avg;
        foreach ($volumes as $id => $volume) {
            $this->Rpc($id, ['mixer', 'volume', (string) (int) round(max(0, min(100, $volume + $delta)))]);
        }
    }

    // ---------------------------------------------------------------------
    // Zustand laden
    // ---------------------------------------------------------------------

    private function FullRefresh(): bool
    {
        if ($this->Host() === '') {
            return false;
        }
        if (!$this->Lock()) {
            $this->QueueRefresh('*');
            return false;
        }
        try {
            $server = $this->Rpc('', ['serverstatus', '0', '999']);
            if ($server === null) {
                if ($this->GetStatus() !== self::STATUS_UNREACHABLE) {
                    $this->SetStatus(self::STATUS_UNREACHABLE);
                }
                $state = $this->LoadState();
                $state['online'] = false;
                $this->SaveState($state);
                $this->Publish();
                return false;
            }
            if ($this->GetStatus() !== IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }

            $players = [];
            foreach ($server['players_loop'] ?? [] as $row) {
                $id = strtolower((string) ($row['playerid'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $players[$id] = [
                    'id'        => $id,
                    'name'      => (string) ($row['name'] ?? $id),
                    'model'     => (string) ($row['modelname'] ?? ($row['model'] ?? '')),
                    'connected' => (bool) ($row['connected'] ?? false),
                    'power'     => (bool) ($row['power'] ?? false)
                ];
            }

            $groups = [];
            $sync = $this->Rpc('', ['syncgroups', '?']);
            foreach ($sync['syncgroups_loop'] ?? [] as $row) {
                $members = array_values(array_filter(array_map('strtolower', explode(',', (string) ($row['sync_members'] ?? '')))));
                if (count($members) > 1) {
                    $groups[] = $members;
                }
            }

            $plugin = $this->Plugin();
            $can = $this->Rpc('', ['can', $plugin, 'items', '?']);
            $spotify = ((int) ($can['_can'] ?? 0)) === 1;
            $accounts = [];
            $defaultAccount = '';
            if ($spotify) {
                $raw = $this->Rpc('', ['pref', $this->PrefNamespace() . ':accounts', '?']);
                $accounts = Helper::NormalizeAccounts($raw['_p2'] ?? null);
                $default = $this->Rpc('', ['pref', $this->PrefNamespace() . ':activeAccount', '?']);
                $defaultAccount = is_string($default['_p2'] ?? null) ? $default['_p2'] : '';
            }

            $state = [
                'online'         => true,
                'players'        => $players,
                'groups'         => $groups,
                'spotify'        => $spotify,
                'accounts'       => $accounts,
                'defaultAccount' => $defaultAccount
            ];
            foreach (array_keys($players) as $id) {
                $state['players'][$id] = array_merge($players[$id], $this->FetchPlayer($id, $state));
            }
            $this->SaveState($state);
        } finally {
            $this->Unlock();
        }
        $this->Publish();
        return true;
    }

    private function ProcessDirty(): void
    {
        $dirty = json_decode($this->GetBuffer('Dirty') ?: '[]', true) ?: [];
        $this->SetBuffer('Dirty', '[]');
        if (count($dirty) === 0) {
            return;
        }
        $state = $this->LoadState();
        if (in_array('*', $dirty, true) || !isset($state['players'])) {
            $this->FullRefresh();
            return;
        }
        $ids = [];
        foreach ($dirty as $id) {
            if (!isset($state['players'][$id])) {
                // unbekannter Player: komplett neu einlesen
                $this->FullRefresh();
                return;
            }
            foreach ($this->ZoneMembers($id) as $member) {
                $ids[$member] = true;
            }
        }
        if (!$this->Lock()) {
            $this->QueueRefresh('*');
            return;
        }
        try {
            $state = $this->LoadState();
            foreach (array_keys($ids) as $id) {
                if (isset($state['players'][$id])) {
                    $state['players'][$id] = array_merge($state['players'][$id], $this->FetchPlayer($id, $state));
                }
            }
            $state['online'] = true;
            $this->SaveState($state);
        } finally {
            $this->Unlock();
        }
        $this->Publish();
    }

    private function FetchPlayer(string $id, array $state): array
    {
        $status = $this->Rpc($id, ['status', '-', '1', 'tags:aclKNdxJ']);
        if ($status === null) {
            return ['connected' => false];
        }
        $data = Helper::NormalizeStatus($status);
        $data['fetchedAt'] = microtime(true);
        if (count($state['accounts'] ?? []) > 0) {
            $pref = $this->Rpc($id, ['playerpref', $this->PrefNamespace() . ':activeAccount', '?']);
            $account = is_string($pref['_p2'] ?? null) ? $pref['_p2'] : '';
            $data['account'] = $account !== '' ? $account : (string) ($state['defaultAccount'] ?? '');
        } else {
            $data['account'] = '';
        }
        return $data;
    }

    private function QueueRefresh(string $scope): void
    {
        if (!IPS_SemaphoreEnter('LMR_Dirty_' . $this->InstanceID, 2000)) {
            return;
        }
        $dirty = json_decode($this->GetBuffer('Dirty') ?: '[]', true) ?: [];
        if (!in_array($scope, $dirty, true)) {
            $dirty[] = $scope;
        }
        $this->SetBuffer('Dirty', json_encode($dirty));
        IPS_SemaphoreLeave('LMR_Dirty_' . $this->InstanceID);
        $this->SetTimerInterval('Debounce', 250);
    }

    // ---------------------------------------------------------------------
    // Veröffentlichen an die Kachel
    // ---------------------------------------------------------------------

    private function Publish(): void
    {
        $state = $this->LoadState();
        $ui = $this->BuildUIState($state);

        $newCovers = $this->EnsureCovers($state, $ui['zones']);
        if (count($newCovers) > 0) {
            $this->UpdateVisualizationValue(json_encode(['t' => 'covers', 'covers' => $newCovers]));
        }

        $json = json_encode($ui);
        $hash = md5($json);
        if ($hash === $this->GetBuffer('LastHash')) {
            return;
        }
        $this->SetBuffer('LastHash', $hash);
        $this->UpdateVisualizationValue($json);
    }

    private function BuildUIState(array $state): array
    {
        $players = $state['players'] ?? [];
        $settings = [];
        foreach ($this->PlayerSettingsList() as $row) {
            $settings[$row['id']] = $row;
        }
        $order = Helper::OrderPlayers($players, array_values($settings));
        $isVisible = static fn (string $id): bool => (bool) ($settings[$id]['visible'] ?? true);

        $uiPlayers = [];
        foreach ($order as $id) {
            if (!$isVisible($id)) {
                continue;
            }
            $p = $players[$id];
            $uiPlayers[] = [
                'id'        => $id,
                'name'      => (string) ($p['name'] ?? $id),
                'area'      => (string) ($settings[$id]['area'] ?? ''),
                'model'     => (string) ($p['model'] ?? ''),
                'connected' => (bool) ($p['connected'] ?? false),
                'power'     => (bool) ($p['power'] ?? false),
                'mode'      => (string) ($p['mode'] ?? 'stop'),
                'volume'    => (int) ($p['volume'] ?? 0),
                'muted'     => (bool) ($p['muted'] ?? false)
            ];
        }

        $accountNames = [];
        foreach ($state['accounts'] ?? [] as $account) {
            $accountNames[$account['id']] = $account['name'];
        }

        $zones = [];
        foreach (Helper::BuildZones($order, $state['groups'] ?? []) as $zone) {
            $members = array_values(array_filter($zone['members'], $isVisible));
            if (count($members) === 0) {
                continue;
            }
            $m = $players[$zone['id']];
            $account = (string) ($m['account'] ?? '');
            $mode = (string) ($m['mode'] ?? 'stop');
            $duration = (float) ($m['duration'] ?? 0);
            $time = (float) ($m['time'] ?? 0);
            if ($mode === 'play' && isset($m['fetchedAt'])) {
                // Position auf den Zeitpunkt des Versands hochrechnen
                $time += max(0, microtime(true) - (float) $m['fetchedAt']);
                if ($duration > 0) {
                    $time = min($time, $duration);
                }
            }
            $zones[] = [
                'id'          => $zone['id'],
                'members'     => $members,
                'mode'        => $mode,
                'power'       => (bool) ($m['power'] ?? false),
                'connected'   => (bool) ($m['connected'] ?? false),
                'time'        => round($time, 1),
                'duration'    => $duration,
                'canSeek'     => (bool) ($m['canSeek'] ?? false),
                'hasTrack'    => (bool) ($m['hasTrack'] ?? false),
                'title'       => (string) ($m['title'] ?? ''),
                'artist'      => (string) ($m['artist'] ?? ''),
                'album'       => (string) ($m['album'] ?? ''),
                'remote'      => (bool) ($m['remote'] ?? false),
                'cover'       => $m['coverKey'] ?? null,
                'shuffle'     => (int) ($m['shuffle'] ?? 0),
                'repeat'      => (int) ($m['repeat'] ?? 0),
                'account'     => $account,
                'accountName' => $accountNames[$account] ?? ''
            ];
        }

        return [
            't'        => 'state',
            'online'   => (bool) ($state['online'] ?? false),
            'players'  => $uiPlayers,
            'zones'    => $zones,
            'spotify'  => (bool) ($state['spotify'] ?? false),
            'accounts' => array_values($state['accounts'] ?? [])
        ];
    }

    /** Lädt fehlende Cover der Zonen und gibt die neu geladenen zurück. */
    private function EnsureCovers(array $state, array $zones): array
    {
        $covers = $this->LoadCovers();
        $missing = [];
        foreach ($zones as $zone) {
            $key = $zone['cover'] ?? null;
            if ($key === null || isset($covers[$key]) || isset($missing[$key])) {
                continue;
            }
            $track = $state['players'][$zone['id']]['track'] ?? [];
            $missing[$key] = Helper::CoverPath($track, $zone['id'], max(150, min(1000, $this->ReadPropertyInteger('CoverSize'))));
        }
        if (count($missing) === 0) {
            return [];
        }
        $loaded = $this->FetchImages($missing);
        if (count($loaded) === 0) {
            return [];
        }
        foreach ($loaded as $key => $uri) {
            unset($covers[$key]);
            $covers[$key] = $uri;
        }
        while (count($covers) > self::MAX_COVERS) {
            array_shift($covers);
        }
        $this->SetBuffer('Covers', json_encode($covers));
        return $loaded;
    }

    // ---------------------------------------------------------------------
    // Kommunikation mit dem LMS
    // ---------------------------------------------------------------------

    /**
     * JSON-RPC an den LMS. Liefert das Ergebnis-Array oder null bei Fehlern.
     */
    private function Rpc(string $player, array $cmd, int $timeout = 5): ?array
    {
        $host = $this->Host();
        if ($host === '') {
            return null;
        }
        $body = json_encode(['id' => 1, 'method' => 'slim.request', 'params' => [$player, array_values($cmd)]]);
        $ch = curl_init($this->BaseURL() . '/jsonrpc.js');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout
        ]);
        $this->ApplyAuth($ch);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($response) || $code !== 200) {
            $this->SendDebug('RPC Fehler', $body . ' => HTTP ' . $code . ' ' . $error, 0);
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->SendDebug('RPC Fehler', $body . ' => ' . substr($response, 0, 200), 0);
            return null;
        }
        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }

    /**
     * Lädt mehrere Bilder parallel und liefert data-URIs (Schlüssel bleiben erhalten).
     *
     * @param array<string, string> $paths Schlüssel => Pfad relativ zum LMS
     * @return array<string, string>
     */
    private function FetchImages(array $paths): array
    {
        if (count($paths) === 0 || $this->Host() === '') {
            return [];
        }
        $multi = curl_multi_init();
        $handles = [];
        foreach ($paths as $key => $path) {
            $ch = curl_init($this->BaseURL() . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 10
            ]);
            $this->ApplyAuth($ch);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.5);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $result = [];
        foreach ($handles as $key => $ch) {
            $content = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            if ($code !== 200 || !is_string($content) || $content === '' || strlen($content) > self::MAX_IMAGE_BYTES) {
                continue;
            }
            $mime = $this->ImageMime($type, $content);
            if ($mime === null) {
                continue;
            }
            $result[(string) $key] = 'data:' . $mime . ';base64,' . base64_encode($content);
        }
        curl_multi_close($multi);
        return $result;
    }

    private function ImageMime(string $contentType, string $content): ?string
    {
        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $mime) {
            if (str_starts_with($contentType, $mime)) {
                return $mime;
            }
        }
        // Manche Quellen liefern keinen korrekten Content-Type: an der Signatur erkennen
        return match (true) {
            str_starts_with($content, "\xFF\xD8\xFF")                                 => 'image/jpeg',
            str_starts_with($content, "\x89PNG")                                      => 'image/png',
            str_starts_with($content, 'GIF8')                                         => 'image/gif',
            str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP' => 'image/webp',
            default                                                                   => null
        };
    }

    private function ApplyAuth(CurlHandle $ch): void
    {
        $user = $this->ReadPropertyString('Username');
        if ($user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $this->ReadPropertyString('Password'));
        }
    }

    private function Subscribe(): void
    {
        $lines = [];
        $user = $this->ReadPropertyString('Username');
        if ($user !== '') {
            $lines[] = 'login ' . rawurlencode($user) . ' ' . rawurlencode($this->ReadPropertyString('Password'));
        }
        $lines[] = 'subscribe client,sync,name,power,playlist,mixer,pause,play,stop,newmetadata,prefset,rescan';
        foreach ($lines as $line) {
            $this->SendDebug('CLI', $line, 0);
            try {
                $this->SendDataToParent(json_encode(['DataID' => self::IO_TX, 'Buffer' => bin2hex($line . "\n")]));
            } catch (Throwable $e) {
                $this->SendDebug('CLI Fehler', $e->getMessage(), 0);
                return;
            }
        }
    }

    private function TrackParent(): void
    {
        $old = (int) $this->GetBuffer('ParentID');
        $new = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($old === $new) {
            return;
        }
        if ($old > 0 && IPS_InstanceExists($old)) {
            $this->UnregisterMessage($old, IM_CHANGESTATUS);
        }
        if ($new > 0) {
            $this->RegisterMessage($new, IM_CHANGESTATUS);
        }
        $this->SetBuffer('ParentID', (string) $new);
    }

    /**
     * Richtet den Client Socket selbst ein (Host, CLI-Port, geöffnet), damit nur am Modul konfiguriert werden muss.
     * Ein vom Benutzer vergebener Name bleibt erhalten; nur der Standardname wird ersetzt.
     */
    private function ConfigureParent(): void
    {
        $parent = (int) $this->GetBuffer('ParentID');
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return;
        }
        if ((IPS_GetInstance($parent)['ModuleInfo']['ModuleID'] ?? '') !== self::CLIENT_SOCKET) {
            return;
        }
        $host = $this->Host();
        $wanted = ['Host' => $host, 'Port' => $this->ReadPropertyInteger('CliPort'), 'Open' => $host !== ''];
        $changed = false;
        foreach ($wanted as $name => $value) {
            try {
                if (IPS_GetProperty($parent, $name) !== $value) {
                    IPS_SetProperty($parent, $name, $value);
                    $changed = true;
                }
            } catch (Throwable $e) {
                $this->SendDebug('Client Socket', $name . ': ' . $e->getMessage(), 0);
            }
        }
        if ($changed) {
            $this->SendDebug('Client Socket', json_encode($wanted), 0);
            IPS_ApplyChanges($parent);
        }
        $name = IPS_GetName($parent);
        if ($host !== '' && ($name === 'Client Socket' || str_starts_with($name, 'Lyrion CLI'))) {
            $label = 'Lyrion CLI (' . $host . ')';
            if ($name !== $label) {
                IPS_SetName($parent, $label);
            }
        }
    }

    private function ConnectionInfo(): string
    {
        $parent = (int) $this->GetBuffer('ParentID');
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return $this->Translate('Live events: no Client Socket connected.');
        }
        if ($this->HasActiveParent()) {
            return sprintf($this->Translate('Live events: active via #%d.'), $parent);
        }
        return sprintf($this->Translate('Live events: Client Socket #%d is not connected. Check host and CLI port (default 9090).'), $parent);
    }

    private function TestConnection(): string
    {
        if ($this->Host() === '') {
            return $this->Translate('Please enter the host of the Lyrion Music Server.');
        }
        $server = $this->Rpc('', ['serverstatus', '0', '999']);
        if ($server === null) {
            return $this->Translate('Lyrion Music Server is not reachable.');
        }
        $this->FullRefresh();
        $state = $this->LoadState();
        $text = sprintf($this->Translate('Connected to Lyrion %s. Players: %d.'), (string) ($server['version'] ?? '?'), count($state['players'] ?? []));
        if (!($state['spotify'] ?? false)) {
            $text .= ' ' . sprintf($this->Translate('Spotify plugin "%s" not found.'), $this->Plugin());
        } else {
            $text .= ' ' . sprintf($this->Translate('Spotify accounts: %d.'), count($state['accounts'] ?? []));
        }
        $text .= ' ' . $this->ConnectionInfo();
        return $text;
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    private function ResumeOrPlay(string $id): bool
    {
        $player = $this->LoadState()['players'][$id] ?? [];
        if (!($player['power'] ?? true)) {
            $this->Rpc($id, ['power', '1']);
        }
        $ok = ($player['mode'] ?? 'stop') === 'pause'
            ? $this->Rpc($id, ['pause', '0']) !== null
            : $this->Rpc($id, ['play']) !== null;
        $this->QueueRefresh($id);
        return $ok;
    }

    private function PlayerCommand(string $player, array $cmd, string $refresh = ''): bool
    {
        $id = $this->ResolvePlayer($player);
        if ($id === null) {
            return false;
        }
        $ok = $this->Rpc($id, $cmd) !== null;
        $this->QueueRefresh($refresh !== '' ? $refresh : $id);
        return $ok;
    }

    /** Akzeptiert Player-ID (MAC) oder Namen (ohne Groß-/Kleinschreibung). */
    private function ResolvePlayer(string $player): ?string
    {
        $players = $this->LoadState()['players'] ?? [];
        $key = strtolower(trim($player));
        if (isset($players[$key])) {
            return $key;
        }
        foreach ($players as $id => $p) {
            if (strcasecmp((string) ($p['name'] ?? ''), trim($player)) === 0) {
                return $id;
            }
        }
        if (Helper::IsPlayerID($key)) {
            return $key; // noch nicht eingelesen, aber formal gültig
        }
        trigger_error('Unknown player: ' . $player, E_USER_NOTICE);
        return null;
    }

    private function ResolveAccount(string $account): ?string
    {
        foreach ($this->LoadState()['accounts'] ?? [] as $entry) {
            if ($entry['id'] === $account || strcasecmp($entry['name'], $account) === 0) {
                return $entry['id'];
            }
        }
        trigger_error('Unknown Spotify account: ' . $account, E_USER_NOTICE);
        return null;
    }

    private function PlayerSettingsList(): array
    {
        $rows = json_decode($this->ReadPropertyString('Players'), true);
        if (!is_array($rows)) {
            return [];
        }
        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $row['id'] = strtolower((string) $row['id']);
            $list[] = $row;
        }
        return $list;
    }

    private function SetFormListValues(array &$elements, string $name, array $values): bool
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element['values'] = $values;
                return true;
            }
            if (isset($element['items']) && is_array($element['items']) && $this->SetFormListValues($element['items'], $name, $values)) {
                return true;
            }
        }
        return false;
    }

    private function LoadState(): array
    {
        $state = json_decode($this->GetBuffer('State') ?: '{}', true);
        return is_array($state) ? $state : [];
    }

    private function SaveState(array $state): void
    {
        $this->SetBuffer('State', json_encode($state));
    }

    private function LoadCovers(): array
    {
        $covers = json_decode($this->GetBuffer('Covers') ?: '{}', true);
        return is_array($covers) ? $covers : [];
    }

    private function Lock(): bool
    {
        return IPS_SemaphoreEnter('LMR_State_' . $this->InstanceID, 8000);
    }

    private function Unlock(): void
    {
        IPS_SemaphoreLeave('LMR_State_' . $this->InstanceID);
    }

    private function Host(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        $host = (string) preg_replace('#^https?://#i', '', $host);
        $host = rtrim($host, '/');
        // versehentlich angegebenen Port entfernen (nicht bei IPv6-Adressen in Klammern)
        if (!str_starts_with($host, '[') && substr_count($host, ':') === 1) {
            $host = explode(':', $host)[0];
        }
        return $host;
    }

    private function BaseURL(): string
    {
        return 'http://' . $this->Host() . ':' . $this->ReadPropertyInteger('WebPort');
    }

    private function Plugin(): string
    {
        $plugin = strtolower(trim($this->ReadPropertyString('SpotifyPlugin')));
        return preg_match('/^[a-z0-9_]+$/', $plugin) ? $plugin : 'spoton';
    }

    private function PrefNamespace(): string
    {
        return 'plugin.' . $this->Plugin();
    }
}
