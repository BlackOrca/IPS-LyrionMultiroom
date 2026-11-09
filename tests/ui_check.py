"""Rendert die Kachel im Browser (Chrome headless) mit echten Moduldaten und prüft die Bedienung.

python3 tests/ui_check.py <dump-ordner multi> <dump-ordner single> <screenshot-ordner>
"""
import json
import pathlib
import sys

from playwright.sync_api import sync_playwright

MULTI, SINGLE, OUT = (pathlib.Path(p).resolve() for p in sys.argv[1:4])
NOSPOTIFY = pathlib.Path(sys.argv[4]).resolve() if len(sys.argv) > 4 else None
OUT.mkdir(parents=True, exist_ok=True)

# Nachbildung der Symcon-Kachelumgebung: Designvariablen, body-Ränder aus den Query-Parametern
# (margintop/marginside/marginbottom) und der von Symcon überlagerte Kacheltitel.
THEMES = {
    "light": {"accent": "#1DCFAE", "content": "#1B1F24", "card": "#FFFFFF", "page": "#ECEDEF"},
    "dark": {"accent": "#1DCFAE", "content": "#E6E8EB", "card": "#20242B", "page": "#121418"},
}

HARNESS = """<!doctype html><html lang="de"><head><meta charset="utf-8"><style>
:root { --accent-color: %(accent)s; --content-color: %(content)s; --card-color: %(card)s; }
html { background: %(page)s; }
body { font-family: 'Poppins', 'Segoe UI', Roboto, sans-serif; color: var(--content-color); background: var(--card-color);
       margin: 52px 16px 14px; user-select: none; }
#symcon-title { position: fixed; top: 14px; left: 16px; font-size: 22px; font-weight: 500; color: var(--content-color); pointer-events: none; }
#symcon-expand { position: fixed; top: 16px; right: 16px; width: 20px; height: 20px; border: 2px solid var(--content-color); opacity: .5; pointer-events: none; }
</style><script>
window.__sent = [];
window.__responses = %(responses)s;
function requestAction(ident, value) {
  window.__sent.push(JSON.parse(value));
  var m = JSON.parse(value);
  if (m.c === 'browse') {
    var kind = m.q ? 'search' : (m.id === 'ab12.3' ? 'long' : (m.id ? 'item' : 'root'));
    var key = (m.src || '') + ':' + (m.root || '') + ':' + kind;
    setTimeout(function () {
      (window.__responses[key] || []).forEach(function (x) { x = JSON.parse(JSON.stringify(x)); x.req = m.req; handleMessage(JSON.stringify(x)); });
    }, 60);
  }
}
</script></head><body><div id="symcon-title">LMS Multiroom</div><div id="symcon-expand"></div>%(tile)s</body></html>"""


def page_for(browser, folder, w, h, name, theme="light"):
    html = HARNESS % dict(THEMES[theme], responses=(folder / "responses.json").read_text(), tile=(folder / "tile.html").read_text())
    path = OUT / f"{name}.html"
    path.write_text(html)
    ctx = browser.new_context(viewport={"width": w, "height": h}, device_scale_factor=2)
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
    page.goto(path.as_uri())
    page.wait_for_timeout(400)
    return page, errors


def sent(page):
    return page.evaluate("window.__sent")


results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(("  ok   " if ok else "  FAIL ") + name + ("" if ok else f"  → {detail}"))


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])

    # ---------------------------------------------------------------- Smartphone, mehrere Räume
    print("== Smartphone (390x844), 4 Player")
    page, errors = page_for(browser, MULTI, 390, 844, "phone_multi")
    page.screenshot(path=str(OUT / "01_phone_rooms.png"))
    check("hello gesendet", any(m["c"] == "hello" for m in sent(page)))
    top = page.evaluate("document.querySelector('#paneRooms .h1').getBoundingClientRect().top")
    check("Inhalt beginnt unter dem Symcon-Kacheltitel", top >= 50, str(top))
    bg = page.evaluate("getComputedStyle(document.querySelector('.card')).backgroundColor")
    check("Karten leiten sich vom hellen Design ab (kein Dunkelgrau)", "23, 26, 33" not in bg, bg)
    ink = page.evaluate("getComputedStyle(document.querySelector('.play')).color")
    check("Schrift auf Akzent ohne --on-accent-color berechnet (dunkel auf Türkis)", ink in ("rgb(17, 20, 24)",), ink)
    check("Zonenkarte EG sichtbar", page.locator("article.zcard.live").count() >= 1)
    check("Räume passen ohne Überlauf", page.evaluate("[...document.querySelectorAll('#paneRooms .pagelist')].every(e => e.scrollHeight <= e.clientHeight + 1)"))
    check("Bereiche gruppiert", page.get_by_text("Obergeschoss").count() >= 1)

    page.locator("button[data-act=join]").first.click()
    s = sent(page)[-1]
    check("+ hängt Raum an aktive Zone", s["c"] == "setZone" and len(s["v"]) == 3, json.dumps(s))

    page.locator("button.zhead").first.click()
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / "02_phone_nowplaying.png"))
    check("Now Playing zeigt Titel", page.locator(".np .title").inner_text() == "Golden Hour Drive")
    check("Now Playing passt ohne Überlauf", page.evaluate("(e => e.scrollHeight <= e.clientHeight + 1)(document.querySelector('#paneZone .np'))"))
    cov = page.evaluate("document.querySelector('#paneZone .bigcover').getBoundingClientRect().width")
    check("Cover nutzt den Restplatz (≥ 200 px)", cov >= 200, str(cov))
    page.locator("#paneZone button[data-act=volSheet]").click()
    page.wait_for_timeout(200)
    page.screenshot(path=str(OUT / "02c_phone_volumes.png"))
    check("Lautstärke je Raum im eigenen Fenster", page.locator(".sheet input[data-act=vol]").count() == 2)
    page.locator("button[data-act=closeSheet]").click()

    page.locator(".ctl.big").click()
    check("Play/Pause sendet toggle", sent(page)[-1]["c"] == "toggle")
    page.wait_for_timeout(100)
    check("Optimistisch auf Pause umgeschaltet", page.locator(".ctl.big").get_attribute("aria-label") == "Wiedergabe")

    rng = page.locator("#paneZone input[data-act=zoneVol]")
    rng.fill("60")
    rng.dispatch_event("change")
    zv = [m for m in sent(page) if m["c"] == "zoneVol"]
    check("Zonen-Lautstärke gesendet", zv and zv[-1]["v"] == 60, json.dumps(zv))

    page.locator("#paneZone button[data-act=editZone]").click()
    page.wait_for_timeout(200)
    page.screenshot(path=str(OUT / "03_phone_zone_sheet.png"))
    page.locator("label.checkrow", has_text="Kinderzimmer").click()
    page.wait_for_timeout(100)
    check("Warnung beim Übernehmen spielender Räume", page.locator(".warn").count() == 1)
    page.screenshot(path=str(OUT / "04_phone_zone_sheet_warn.png"))
    page.locator("button[data-act=applyZone]").click()
    s = sent(page)[-1]
    check("Zone übernehmen sendet Auswahl", s["c"] == "setZone" and "aa:bb:cc:00:00:03" in s["v"], json.dumps(s))

    page.locator("button.tab[data-v=browse]").click()
    page.wait_for_timeout(500)
    check("Tab heißt Musik", page.locator("button.tab[data-v=browse]").inner_text().strip() == "Musik")
    check("Quellenwahl: Spotify, Bibliothek, Favoriten", page.locator(".seg[aria-label=Musikquelle] button").all_inner_texts() == ["Spotify", "Bibliothek", "Favoriten"], str(page.locator(".seg[aria-label=Musikquelle] button").all_inner_texts()))
    page.screenshot(path=str(OUT / "05_phone_spotify.png"))
    check("Spotify-Root geladen (Suche als Feld)", page.locator("form.search input").count() == 1)
    check("Kontoumschalter mit 2 Konten", page.locator(".seg[aria-label=Spotify-Konto] button").count() == 2)
    page.locator(".seg[aria-label=Spotify-Konto] button", has_text="Familie").click()
    s = [m for m in sent(page) if m["c"] == "account"][-1]
    check("Kontowechsel für Ziel-Zone", s["v"] == "acc_family", json.dumps(s))
    page.wait_for_timeout(700)
    page.locator("button.open", has_text="Home").click()
    page.wait_for_timeout(500)
    page.screenshot(path=str(OUT / "06_phone_spotify_list.png"))
    check("Liste mit Vorschaubildern", page.locator(".thumb img").count() >= 3)
    page.locator("button[data-act=playItem]").first.click()
    s = sent(page)[-1]
    check("Playlist direkt abspielen", s["c"] == "playItem" and s["v"] == "play", json.dumps(s))
    page.locator("button.open", has_text="Discover Weekly").click()
    page.wait_for_timeout(500)
    check("Playlist öffnet Unterliste", page.locator(".btop .h").inner_text() == "Discover Weekly")
    page.locator("button[data-act=browseBack]").click()
    page.locator("button[data-act=browseBack]").click()
    page.wait_for_timeout(500)
    # Bildschirmtastatur: Die Kachel wird kürzer und es kommen Statusmeldungen – das Suchfeld muss bleiben
    inp = page.locator("form.search input")
    inp.click()
    inp.type("nord")
    page.evaluate("document.activeElement.__probe = 1")
    page.set_viewport_size({"width": 390, "height": 230})
    page.wait_for_timeout(300)
    page.evaluate("handleMessage(JSON.stringify(S.st))")
    page.wait_for_timeout(200)
    check("Suchfeld behält Fokus bei Tastatur und Statusmeldung", page.evaluate("!!(document.activeElement && document.activeElement.__probe)"))
    check("Layout wechselt beim Tippen nicht in den Mini-Player", page.evaluate("document.getElementById('app').getAttribute('data-layout')") == "phone")
    check("Schrift im Suchfeld mindestens 16 px (kein Zoom auf dem iPhone)", page.evaluate("parseFloat(getComputedStyle(document.querySelector('form.search input')).fontSize)") >= 16)
    page.set_viewport_size({"width": 390, "height": 844})
    page.evaluate("document.activeElement.blur()")
    page.wait_for_timeout(300)
    page.evaluate("handleMessage(JSON.stringify(S.st))")
    page.wait_for_timeout(200)
    check("Eingegebener Text bleibt nach dem Neuzeichnen erhalten", page.locator("form.search input").input_value() == "nord", page.locator("form.search input").input_value())
    page.locator("form.search input").fill("nordlicht")
    page.locator("form.search input").press("Enter")
    page.wait_for_timeout(500)
    check("Tastatur schließt nach dem Suchen", page.evaluate("!document.activeElement || document.activeElement.tagName !== 'INPUT'"))
    page.screenshot(path=str(OUT / "07_phone_search.png"))
    check("Suche zeigt Treffer", page.locator("button.open", has_text="Treffer 1").count() == 1)
    page.locator("button[data-act=browseBack]").click()
    page.wait_for_timeout(400)
    page.locator("button.open", has_text="Bibliothek").click()
    page.wait_for_timeout(500)
    page.screenshot(path=str(OUT / "07b_phone_paging.png"))
    pager = page.locator("#paneBrowse .pager span").inner_text()
    check("Lange Liste wird geblättert", pager.startswith("Seite 1 von ") and int(pager.split()[-1]) > 1, pager)
    check("Liste passt ohne Überlauf", page.evaluate("(e => e.scrollHeight <= e.clientHeight + 1)(document.querySelector('#paneBrowse .pagelist'))"))
    page.locator("#paneBrowse button[data-act=page][data-v='1']").click()
    page.wait_for_timeout(100)
    check("Nächste Seite zeigt weitere Einträge", page.locator("#paneBrowse .pager span").inner_text().startswith("Seite 2") and page.evaluate("document.querySelector('#paneBrowse .item .grow').textContent") != "Playlist 1", page.evaluate("document.querySelector('#paneBrowse .item .grow').textContent"))
    page.mouse.move(195, 400)
    page.mouse.wheel(0, 300)
    page.wait_for_timeout(150)
    check("Mausrad blättert weiter", page.locator("#paneBrowse .pager span").inner_text().startswith("Seite 3"), page.locator("#paneBrowse .pager span").inner_text())
    page.locator("button[data-act=browseBack]").click()
    page.wait_for_timeout(400)
    # Lokale Bibliothek
    page.locator(".seg[aria-label=Musikquelle] button", has_text="Bibliothek").click()
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / "07c_phone_library.png"))
    check("Bibliothek zeigt Einstiege", page.locator("#paneBrowse button.open").all_inner_texts()[:3] == ["Suche", "Interpreten", "Alben"], str(page.locator("#paneBrowse button.open").all_inner_texts()))
    check("Bibliothek: keine Konto-Auswahl", page.locator(".seg[aria-label=Spotify-Konto]").count() == 0)
    page.locator("button.open", has_text="Interpreten").click()
    page.wait_for_timeout(400)
    check("Interpreten geladen", page.locator("button.open", has_text="Hans Zimmer").count() == 1)
    req = [m for m in sent(page) if m["c"] == "browse"][-1]
    check("Browse-Anfrage mit Quelle und Einstieg", req.get("src") == "library" and req.get("root") == "artists", json.dumps(req))
    page.locator("button.open", has_text="Hans Zimmer").click()
    page.wait_for_timeout(400)
    page.screenshot(path=str(OUT / "07d_phone_library_artist.png"))
    page.locator("button[data-act=playItem]").first.click()
    s = sent(page)[-1]
    check("Titel aus Bibliothek abspielen", s["c"] == "playItem" and s["src"] == "library" and s["root"] == "artists", json.dumps(s))
    page.locator("button[data-act=browseBack]").click()
    page.locator("button[data-act=browseBack]").click()
    page.wait_for_timeout(300)
    page.locator(".seg[aria-label=Musikquelle] button", has_text="Favoriten").click()
    page.wait_for_timeout(400)
    check("Favoriten geladen", page.locator("button.open", has_text="Deutschlandfunk").count() == 1)
    page.locator(".seg[aria-label=Musikquelle] button", has_text="Spotify").click()
    page.wait_for_timeout(400)
    page.locator("form.search input").fill("nordlicht")
    page.locator("form.search input").press("Enter")
    page.wait_for_timeout(500)
    page.locator("button.open", has_text="Treffer 1").click()
    page.wait_for_timeout(200)
    page.screenshot(path=str(OUT / "08_phone_item_sheet.png"))
    page.locator("button[data-act=itemMode][data-v=insert]").click()
    s = sent(page)[-1]
    check("Titel als Nächstes einreihen", s["c"] == "playItem" and s["v"] == "insert", json.dumps(s))
    check("Keine JS-Fehler (Smartphone)", not errors, "; ".join(errors))

    # ---------------------------------------------------------------- Kleine Kachel: Räume werden geblättert
    print("== Kleine Kachel (390x520)")
    page, errors = page_for(browser, MULTI, 390, 520, "phone_small")
    page.screenshot(path=str(OUT / "01c_phone_small.png"))
    check("Räume-Liste wird bei wenig Höhe geblättert", page.locator("#paneRooms .pager").count() == 1)
    check("Kleine Kachel ohne Überlauf", page.evaluate("[...document.querySelectorAll('#paneRooms .pagelist')].every(e => e.scrollHeight <= e.clientHeight + 1)"))
    page.locator("button.zhead").first.click()
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / "02d_phone_small_np.png"))
    check("Now Playing passt auch klein", page.evaluate("(e => e.scrollHeight <= e.clientHeight + 1)(document.querySelector('#paneZone .np'))"))
    check("Keine JS-Fehler (klein)", not errors, "; ".join(errors))

    # ---------------------------------------------------------------- Ohne SpotOn
    if NOSPOTIFY:
        print("== Ohne Spotify (SpotOn abgeschaltet)")
        page, errors = page_for(browser, NOSPOTIFY, 390, 844, "phone_nospotify")
        check("Ohne Spotify: kein Konto-Symbol in den Karten", page.locator("#paneRooms .zsub .avatar").count() == 0)
        page.locator("button.zhead").first.click()
        page.wait_for_timeout(300)
        check("Ohne Spotify: kein Konto-Knopf, aber Musik-Knopf", page.locator("#paneZone [data-act=accountSheet]").count() == 0 and page.locator("#paneZone .actions", has_text="Musik").count() == 1)
        page.locator("button.tab[data-v=browse]").click()
        page.wait_for_timeout(400)
        page.screenshot(path=str(OUT / "14_phone_nospotify_music.png"))
        check("Ohne Spotify: Quellen Bibliothek und Favoriten", page.locator(".seg[aria-label=Musikquelle] button").all_inner_texts() == ["Bibliothek", "Favoriten"], str(page.locator(".seg[aria-label=Musikquelle] button").all_inner_texts()))
        check("Keine JS-Fehler (ohne Spotify)", not errors, "; ".join(errors))

    # ---------------------------------------------------------------- Dunkles Design
    print("== Smartphone, dunkles Design")
    page, errors = page_for(browser, MULTI, 390, 844, "phone_dark", theme="dark")
    page.screenshot(path=str(OUT / "01b_phone_rooms_dark.png"))
    page.locator("button.zhead").first.click()
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / "02b_phone_nowplaying_dark.png"))
    check("Keine JS-Fehler (dunkel)", not errors, "; ".join(errors))

    # ---------------------------------------------------------------- Smartphone, ein Player
    print("== Smartphone, 1 Player")
    page, errors = page_for(browser, SINGLE, 390, 844, "phone_single")
    page.screenshot(path=str(OUT / "09_phone_single.png"))
    check("Ein Player: keine Zonen-Funktionen", page.locator("[data-act=editZone], [data-act=party]").count() == 0)
    check("Ein Player: Hinweis auf weitere Bereiche", page.get_by_text("Weitere Player erscheinen").count() == 1)
    page.locator("button.zhead").click()
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / "10_phone_single_np.png"))
    check("Shuffle/Repeat aktiv dargestellt", page.locator(".ctl.small.on").count() == 2)
    check("Keine JS-Fehler (1 Player)", not errors, "; ".join(errors))

    # ---------------------------------------------------------------- Tablet
    print("== Wandtablet (1280x800)")
    page, errors = page_for(browser, MULTI, 1280, 800, "tablet")
    page.wait_for_timeout(600)
    page.screenshot(path=str(OUT / "11_tablet.png"))
    check("Drei Spalten sichtbar", all(page.locator(f"#{i}").is_visible() for i in ["paneRooms", "paneZone", "paneBrowse"]))
    check("Spotify im Tablet direkt geladen", page.locator("#paneBrowse button.open").count() >= 2)
    page.locator("button.zhead", has_text="Kinderzimmer").click()
    page.wait_for_timeout(300)
    check("Raum wählen wechselt mittlere Spalte", "Waldbande" in page.locator("#paneZone .title").inner_text())
    page.screenshot(path=str(OUT / "12_tablet_kids.png"))
    check("Keine JS-Fehler (Tablet)", not errors, "; ".join(errors))

    # ---------------------------------------------------------------- Mini-Kachel
    print("== Mini-Kachel (280x110)")
    page, errors = page_for(browser, MULTI, 280, 110, "mini")
    page.screenshot(path=str(OUT / "13_mini.png"))
    check("Mini-Player zeigt laufende Zone", page.locator("#mini .ztitle").inner_text() == "Golden Hour Drive")
    check("Keine JS-Fehler (Mini)", not errors, "; ".join(errors))

    browser.close()

print(f"\n{sum(results)} bestanden, {len(results) - sum(results)} fehlgeschlagen")
sys.exit(0 if all(results) else 1)
