"""Rendert die Kachel im Browser (Chrome headless) mit echten Moduldaten und prüft die Bedienung.

python3 tests/ui_check.py <dump-ordner multi> <dump-ordner single> <screenshot-ordner>
"""
import json
import pathlib
import sys

from playwright.sync_api import sync_playwright

MULTI, SINGLE, OUT = (pathlib.Path(p).resolve() for p in sys.argv[1:4])
OUT.mkdir(parents=True, exist_ok=True)

HARNESS = """<!doctype html><html lang="de"><head><meta charset="utf-8"><script>
window.__sent = [];
window.__responses = %s;
function requestAction(ident, value) {
  window.__sent.push(JSON.parse(value));
  var m = JSON.parse(value);
  if (m.c === 'browse') {
    var key = m.id ? (m.q ? 'search' : 'list') : 'root';
    setTimeout(function () {
      (window.__responses[key] || []).forEach(function (x) { x = JSON.parse(JSON.stringify(x)); x.req = m.req; handleMessage(JSON.stringify(x)); });
    }, 60);
  }
}
</script></head><body>%s</body></html>"""


def page_for(browser, folder, w, h, name):
    html = HARNESS % ((folder / "responses.json").read_text(), (folder / "tile.html").read_text())
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
    check("Zonenkarte EG sichtbar", page.locator("article.card.live").count() >= 1)
    check("Bereiche gruppiert", page.get_by_text("Obergeschoss").count() >= 1)

    page.locator("button[data-act=join]").first.click()
    s = sent(page)[-1]
    check("+ hängt Raum an aktive Zone", s["c"] == "setZone" and len(s["v"]) == 3, json.dumps(s))

    page.locator("button.zhead").first.click()
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / "02_phone_nowplaying.png"))
    check("Now Playing zeigt Titel", page.locator(".np .title").inner_text() == "Golden Hour Drive")

    page.locator(".ctl.big").click()
    check("Play/Pause sendet toggle", sent(page)[-1]["c"] == "toggle")
    page.wait_for_timeout(100)
    check("Optimistisch auf Pause umgeschaltet", page.locator(".ctl.big").get_attribute("aria-label") == "Wiedergabe")

    rng = page.locator("input[data-act=zoneVol]")
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
    page.screenshot(path=str(OUT / "05_phone_spotify.png"))
    check("Spotify-Root geladen (Suche als Feld)", page.locator("form.search input").count() == 1)
    check("Kontoumschalter mit 2 Konten", page.locator(".seg button").count() == 2)
    page.locator(".seg button", has_text="Familie").click()
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
    page.locator("form.search input").fill("nordlicht")
    page.locator("form.search input").press("Enter")
    page.wait_for_timeout(500)
    page.screenshot(path=str(OUT / "07_phone_search.png"))
    check("Suche zeigt Treffer", page.locator("button.open", has_text="Treffer 1").count() == 1)
    page.locator("button.open", has_text="Treffer 1").click()
    page.wait_for_timeout(200)
    page.screenshot(path=str(OUT / "08_phone_item_sheet.png"))
    page.locator("button[data-act=itemMode][data-v=insert]").click()
    s = sent(page)[-1]
    check("Titel als Nächstes einreihen", s["c"] == "playItem" and s["v"] == "insert", json.dumps(s))
    check("Keine JS-Fehler (Smartphone)", not errors, "; ".join(errors))

    # ---------------------------------------------------------------- Smartphone, ein Player
    print("== Smartphone, 1 Player")
    page, errors = page_for(browser, SINGLE, 390, 844, "phone_single")
    page.screenshot(path=str(OUT / "09_phone_single.png"))
    check("Ein Player: keine Zonen-Funktionen", page.locator("[data-act=editZone], [data-act=party]").count() == 0)
    check("Ein Player: Hinweis auf weitere Räume", page.get_by_text("Weitere Player erscheinen").count() == 1)
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
