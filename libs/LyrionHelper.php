<?php

declare(strict_types=1);

namespace LyrionMultiroom;

/**
 * Reine Hilfsfunktionen ohne Symcon-Abhängigkeit (separat testbar).
 */
final class Helper
{
    /** Befehle, deren Benachrichtigung eine komplette Aktualisierung erfordert. */
    private const FULL_REFRESH_COMMANDS = ['client', 'sync', 'name', 'rescan'];

    /** Befehle, die nur den betroffenen Player (und seine Zone) betreffen. */
    private const PLAYER_REFRESH_COMMANDS = ['playlist', 'mixer', 'pause', 'play', 'stop', 'power', 'newmetadata', 'time', 'button'];

    /**
     * Zerlegt eine CLI-Zeile ("aa%3Abb... playlist newsong Titel 3") in Player, Befehl und Argumente.
     *
     * @return array{player: ?string, cmd: string, args: string[]}|null
     */
    public static function ParseNotification(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $tokens = array_map('rawurldecode', explode(' ', $line));
        $player = null;
        if (self::IsPlayerID($tokens[0])) {
            $player = strtolower(array_shift($tokens));
        }
        if (count($tokens) === 0) {
            return null;
        }
        $cmd = array_shift($tokens);
        return ['player' => $player, 'cmd' => $cmd, 'args' => $tokens];
    }

    public static function IsPlayerID(string $value): bool
    {
        return (bool) preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $value);
    }

    /**
     * Liefert '*' für komplette Aktualisierung, eine Player-ID oder null (ignorieren).
     */
    public static function RefreshScope(array $notification, string $prefNamespace): ?string
    {
        $cmd = $notification['cmd'];
        $player = $notification['player'];
        if (in_array($cmd, self::FULL_REFRESH_COMMANDS, true)) {
            return '*';
        }
        if ($cmd === 'prefset') {
            // "<player> prefset plugin.spoton activeAccount <id>"
            $args = $notification['args'];
            if (($args[0] ?? '') === $prefNamespace && ($args[1] ?? '') === 'activeAccount') {
                return $player ?? '*';
            }
            return null;
        }
        if ($player !== null && in_array($cmd, self::PLAYER_REFRESH_COMMANDS, true)) {
            return $player;
        }
        return null;
    }

    /**
     * Bildet Zonen aus den Sync-Gruppen. Der erste Eintrag einer Gruppe ist der Master.
     * Nicht synchronisierte Player werden zu Einzelzonen.
     *
     * @param string[] $playerIDs bekannte Player in gewünschter Reihenfolge
     * @param string[][] $groups Sync-Gruppen (Master zuerst)
     * @return array<int, array{id: string, members: string[]}>
     */
    public static function BuildZones(array $playerIDs, array $groups): array
    {
        $known = array_flip($playerIDs);
        $assigned = [];
        $zonesByMaster = [];
        foreach ($groups as $group) {
            $members = array_values(array_filter($group, static fn ($id) => isset($known[$id]) && !isset($assigned[$id])));
            if (count($members) === 0) {
                continue;
            }
            foreach ($members as $id) {
                $assigned[$id] = true;
            }
            $zonesByMaster[$members[0]] = $members;
        }
        $zones = [];
        foreach ($playerIDs as $id) {
            if (isset($zonesByMaster[$id])) {
                $members = $zonesByMaster[$id];
                // Mitglieder in der Reihenfolge der Playerliste, Master bleibt vorn
                $rest = array_values(array_filter($playerIDs, static fn ($p) => $p !== $id && in_array($p, $members, true)));
                $zones[] = ['id' => $id, 'members' => array_merge([$id], $rest)];
            } elseif (!isset($assigned[$id])) {
                $zones[] = ['id' => $id, 'members' => [$id]];
            }
        }
        return $zones;
    }

    /**
     * Plant die Sync-Befehle, um aus dem aktuellen Gruppenzustand die gewünschte Zone zu machen.
     *
     * @param string[][] $groups aktuelle Sync-Gruppen (Master zuerst)
     * @param string[] $wanted gewünschte Mitglieder, erster Eintrag wird Master
     * @return array<int, array{0: string, 1: array}> Liste von [PlayerID, Befehl]
     */
    public static function PlanZone(array $groups, string $master, array $wanted, bool $powerOnJoin): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('strval', $wanted), static fn ($id) => $id !== '')));
        $groupOf = [];
        foreach ($groups as $group) {
            foreach ($group as $id) {
                $groupOf[$id] = $group;
            }
        }
        $commands = [];
        $detached = [];
        $detach = static function (string $id) use (&$commands, &$detached, $groupOf): void {
            if (isset($groupOf[$id]) && !isset($detached[$id])) {
                $commands[] = [$id, ['sync', '-']];
                $detached[$id] = true;
            }
        };

        if (count($wanted) === 0) {
            // Zone auflösen: alle außer dem Gruppen-Master verlassen die Gruppe
            $group = $groupOf[$master] ?? [];
            foreach ($group as $id) {
                if ($id !== $group[0]) {
                    $detach($id);
                }
            }
            return $commands;
        }

        $target = in_array($master, $wanted, true) ? $master : $wanted[0];

        // Bisherige Mitglieder (dieser Zone und der Ziel-Gruppe), die nicht mehr gewünscht sind
        foreach (array_merge($groupOf[$master] ?? [], $groupOf[$target] ?? []) as $id) {
            if (!in_array($id, $wanted, true)) {
                $detach($id);
            }
        }

        // Der Ziel-Master darf nicht Slave einer anderen Gruppe sein
        if (isset($groupOf[$target]) && $groupOf[$target][0] !== $target) {
            $detach($target);
        }

        $currentMembers = (isset($groupOf[$target]) && !isset($detached[$target]))
            ? array_values(array_filter($groupOf[$target], static fn ($id) => !isset($detached[$id])))
            : [$target];

        foreach ($wanted as $id) {
            if ($id === $target || in_array($id, $currentMembers, true)) {
                continue;
            }
            $detach($id);
            if ($powerOnJoin) {
                $commands[] = [$id, ['power', '1']];
            }
            $commands[] = [$target, ['sync', $id]];
        }
        return $commands;
    }

    /**
     * Schlüssel für das Cover des aktuellen Titels (ändert sich, wenn sich das Bild ändert).
     */
    public static function CoverKey(array $track): ?string
    {
        $art = (string) ($track['artwork_url'] ?? '');
        $coverid = (string) ($track['coverid'] ?? '');
        $title = (string) ($track['title'] ?? '');
        if ($art === '' && $coverid === '' && $title === '') {
            return null;
        }
        return substr(md5($art . '|' . $coverid . '|' . ($art === '' && $coverid === '' ? $title : '')), 0, 16);
    }

    /**
     * Pfad (relativ zum LMS-Webserver) für das Cover in gewünschter Größe.
     */
    public static function CoverPath(array $track, string $player, int $size): string
    {
        $art = (string) ($track['artwork_url'] ?? '');
        $coverid = (string) ($track['coverid'] ?? '');
        $spec = $size . 'x' . $size;
        if (preg_match('#^https?://#i', $art)) {
            return '/imageproxy/' . rawurlencode($art) . '/image_' . $spec . '.jpg';
        }
        if (preg_match('/^[0-9a-f]{8}$/i', $coverid)) {
            return '/music/' . $coverid . '/cover_' . $spec . '.jpg';
        }
        if ($art !== '' && !str_contains($art, '..')) {
            return '/' . ltrim($art, '/');
        }
        return '/music/current/cover_' . $spec . '.jpg?player=' . rawurlencode($player);
    }

    /**
     * Pfad für ein kleines Vorschaubild aus einer Browse-Liste (Feld "image" des XMLBrowsers).
     */
    public static function ThumbPath(string $image, int $size): ?string
    {
        if ($image === '' || str_contains($image, '..')) {
            return null;
        }
        if (preg_match('#^https?://#i', $image)) {
            return '/imageproxy/' . rawurlencode($image) . '/image_' . $size . 'x' . $size . '.jpg';
        }
        $path = '/' . ltrim($image, '/');
        // /imageproxy/<url>/image.png  ->  /imageproxy/<url>/image_120x120.png
        if (str_starts_with($path, '/imageproxy/')) {
            return preg_replace('#/image(\.\w+)$#', '/image_' . $size . 'x' . $size . '$1', $path) ?? $path;
        }
        if (preg_match('#^/music/[^/]+/cover(\.\w+)?$#', $path)) {
            return preg_replace('#/cover(\.\w+)?$#', '/cover_' . $size . 'x' . $size . '.jpg', $path) ?? $path;
        }
        return $path;
    }

    /**
     * Normalisiert die Status-Antwort eines Players auf die Felder, die die Oberfläche braucht.
     */
    public static function NormalizeStatus(array $status): array
    {
        $track = $status['remoteMeta'] ?? ($status['playlist_loop'][0] ?? []);
        if (!is_array($track)) {
            $track = [];
        }
        $loopTrack = $status['playlist_loop'][0] ?? [];
        if (is_array($loopTrack)) {
            // fehlende Felder aus dem Playlist-Eintrag ergänzen
            $track += $loopTrack;
        }
        $volume = (int) ($status['mixer volume'] ?? 0);
        $title = (string) ($track['title'] ?? '');
        $artist = (string) ($track['artist'] ?? ($track['trackartist'] ?? ''));
        $remoteTitle = (string) ($track['remote_title'] ?? '');
        if ($title === '' && isset($status['current_title'])) {
            $title = (string) $status['current_title'];
        }
        if ($artist === '' && $remoteTitle !== '' && $remoteTitle !== $title) {
            $artist = $remoteTitle;
        }
        $hasTrack = ((int) ($status['playlist_tracks'] ?? 0)) > 0 || $title !== '';
        return [
            'mode'     => (string) ($status['mode'] ?? 'stop'),
            'power'    => (bool) ($status['power'] ?? true),
            'time'     => (float) ($status['time'] ?? 0),
            'duration' => (float) ($status['duration'] ?? ($track['duration'] ?? 0)),
            'canSeek'  => (bool) ($status['can_seek'] ?? false),
            'volume'   => abs($volume),
            'muted'    => $volume < 0,
            'shuffle'  => (int) ($status['playlist shuffle'] ?? 0),
            'repeat'   => (int) ($status['playlist repeat'] ?? 0),
            'connected'=> (bool) ($status['player_connected'] ?? true),
            'hasTrack' => $hasTrack,
            'title'    => $title,
            'artist'   => $artist,
            'album'    => (string) ($track['album'] ?? ''),
            'remote'   => (bool) ($status['remote'] ?? ($track['remote'] ?? false)),
            'coverKey' => $hasTrack ? self::CoverKey($track) : null,
            'track'    => [
                'artwork_url' => (string) ($track['artwork_url'] ?? ''),
                'coverid'     => (string) ($track['coverid'] ?? ''),
                'title'       => $title
            ]
        ];
    }

    /**
     * Wandelt die SpotOn-Kontoliste (Hash accountId => {displayName, ...}) in eine Liste um.
     * Es werden bewusst nur ID und Anzeigename übernommen.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function NormalizeAccounts(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $list = [];
        foreach ($raw as $id => $entry) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $name = is_array($entry) ? (string) ($entry['displayName'] ?? '') : '';
            $list[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }
        usort($list, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $list;
    }

    /**
     * Ordnet die Player nach der Reihenfolge der Konfigurationsliste, neue Player alphabetisch dahinter.
     *
     * @param array<string, array> $players Player nach ID
     * @param array<int, array> $settings Konfigurationsliste [{id, area, visible}]
     * @return string[] sortierte IDs
     */
    public static function OrderPlayers(array $players, array $settings): array
    {
        $order = [];
        foreach ($settings as $row) {
            $id = strtolower((string) ($row['id'] ?? ''));
            if (isset($players[$id]) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        $rest = array_values(array_diff(array_keys($players), $order));
        usort($rest, static fn ($a, $b) => strcasecmp((string) $players[$a]['name'], (string) $players[$b]['name']));
        return array_merge($order, $rest);
    }
}
