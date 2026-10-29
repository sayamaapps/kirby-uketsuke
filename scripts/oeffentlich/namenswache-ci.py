#!/usr/bin/env python3
"""namenswache-ci — Prüfung auf verbotene Begriffe im CI eines öffentlichen Repos.

Prüft: verfolgte Textdateien des Checkouts (Inhalt und Dateiname), Commit-Nachrichten
und hinzugefügte Zeilen der Commits im Bereich, PR-Titel und PR-Text, Branch-Name.

Das Log eines öffentlichen Repos ist öffentlich. Deshalb gibt das Skript je Kategorie
nur Anzahlen aus, bei Dateiinhalten Pfad und Zeile, bei Commits den kurzen Hash.
Begriffe und Zeileninhalte erscheinen nie in der Ausgabe.

Umgebung:
  NAMENSWACHE_BEGRIFFE  Pflicht. Ein Muster je Zeile, Groß-/Kleinschreibung egal
                        (Repo-Secret).
                        Leer = Abbruch mit Exit 2.
  BASE_SHA, HEAD_SHA    Commit-Bereich. Leer oder 0000… = alle Commits, die auf keinem
                        Remote-Zweig liegen. Nicht erreichbar (nach einem Force-Push):
                        die Commits des Branches, die auf keinem anderen Remote-Zweig liegen.
  PR_TITLE, PR_BODY     Nur bei pull_request; leer = nicht geprüft.
  BRANCH                Branch-Name; leer = nicht geprüft.
  REPO_DIR              Checkout-Verzeichnis (Standard: aktuelles).

Exit: 0 sauber · 1 Treffer · 2 Konfigurations- oder Lesefehler.
Nur Standardbibliothek.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
from pathlib import Path

NULL = "0" * 40
BINAER_PRUEFUNG = 8000  # Bytes; NUL darin = Binärdatei, übersprungen


class Fehler(Exception):
    """Konfigurations- oder Lesefehler; Meldung enthält nie Muster oder Inhalte."""


def lade_muster(text: str) -> list[re.Pattern]:
    muster = []
    for nr, zeile in enumerate(text.splitlines(), 1):
        zeile = zeile.strip()
        if not zeile:
            continue
        try:
            muster.append(re.compile(zeile, re.I))
        except re.error:
            raise Fehler(f"Zeile {nr} der Liste ist kein gültiges Muster")
    if not muster:
        raise Fehler("Liste enthält keine Muster")
    return muster


def treffer_zeilen(text: str, muster: list[re.Pattern]) -> list[int]:
    return [nr for nr, zeile in enumerate(text.splitlines(), 1)
            if any(m.search(zeile) for m in muster)]


def git(repo: Path, *args: str) -> str:
    r = subprocess.run(["git", "-C", str(repo), *args], capture_output=True)
    if r.returncode != 0:
        raise Fehler(f"git {args[0]} fehlgeschlagen")
    return r.stdout.decode("utf-8", "replace")


def pruefe_dateien(repo: Path, muster) -> tuple[int, list[str], int]:
    """(Zahl Dateinamen-Treffer, Fundstellen 'Pfad:Zeile', geprüfte Dateien)."""
    namen = 0
    fundstellen: list[str] = []
    geprueft = 0
    for d in git(repo, "ls-files", "-z").split("\0"):
        if not d:
            continue
        name_trifft = any(m.search(d) for m in muster)
        if name_trifft:
            namen += 1
        try:
            inhalt = (repo / d).read_bytes()
        except OSError:  # Submodul, Verzeichnis, gelöscht
            continue
        if b"\0" in inhalt[:BINAER_PRUEFUNG]:
            continue
        geprueft += 1
        for nr in treffer_zeilen(inhalt.decode("utf-8", "replace"), muster):
            # Ein Pfad, der selbst trifft, bleibt aus dem öffentlichen Log.
            fundstellen.append(f"{'(Dateiname verborgen)' if name_trifft else d}:{nr}")
    return namen, fundstellen, geprueft


def erreichbar(repo: Path, sha: str) -> bool:
    return subprocess.run(["git", "-C", str(repo), "cat-file", "-e", f"{sha}^{{commit}}"],
                          capture_output=True).returncode == 0


def pruefe_commits(repo: Path, base: str, head: str, muster, branch: str = "") -> tuple[list[str], list[str]]:
    """(Commits mit Treffer in der Nachricht, Commits mit Treffer in hinzugefügten Zeilen)."""
    if not base or base == NULL:
        bereich = ["HEAD", "--not", "--remotes=origin"]
    elif not erreichbar(repo, base):
        # Force-Push: der alte Stand ist weg. Im push-Lauf liegt HEAD schon auf
        # origin/<branch>, deshalb diesen Zweig ausnehmen, sonst bliebe nichts zu prüfen.
        print("namenswache-ci: BASE_SHA nicht erreichbar (Force-Push?), geprüft werden die "
              "Commits des Branches, die auf keinem anderen Remote-Zweig liegen")
        bereich = [head or "HEAD", "--not", *([f"--exclude=origin/{branch}"] if branch else []),
                   "--remotes=origin"]
    else:
        bereich = [f"{base}..{head or 'HEAD'}"]
    log = git(repo, "log", "--format=%x1e%H%x1f%B%x1f", "-p", "--no-color",
              "--no-ext-diff", *bereich)
    nachricht_treffer: list[str] = []
    diff_treffer: list[str] = []
    for eintrag in log.split("\x1e")[1:]:
        teile = eintrag.split("\x1f", 2)
        h, msg = teile[0], teile[1]
        diff = teile[2] if len(teile) > 2 else ""
        if treffer_zeilen(msg, muster):
            nachricht_treffer.append(h[:8])
        hinzu = "\n".join(z[1:] for z in diff.splitlines()
                          if z.startswith("+") and not z.startswith("+++"))
        if treffer_zeilen(hinzu, muster):
            diff_treffer.append(h[:8])
    return nachricht_treffer, diff_treffer


def ausgabe(titel: str, anzahl: int, zusatz: list[str]) -> None:
    if anzahl == 0:
        print(f"✓ {titel}: sauber")
        return
    print(f"✗ {titel}: {anzahl} Treffer")
    for z in zusatz[:60]:
        print("    " + z)
    if len(zusatz) > 60:
        print(f"    … und {len(zusatz) - 60} weitere")


def main() -> int:
    text = os.environ.get("NAMENSWACHE_BEGRIFFE", "")
    if not text.strip():
        print("namenswache-ci: Secret NAMENSWACHE_BEGRIFFE fehlt oder ist leer — "
              "nichts geprüft, blockiert. Secret im Repo mit diesem Namen setzen.", file=sys.stderr)
        return 2
    try:
        muster = lade_muster(text)
        repo = Path(os.environ.get("REPO_DIR") or ".").resolve()
        base = os.environ.get("BASE_SHA", "").strip()
        head = os.environ.get("HEAD_SHA", "").strip()
        titel = os.environ.get("PR_TITLE", "")
        body = os.environ.get("PR_BODY", "")
        branch = os.environ.get("BRANCH", "")

        print(f"namenswache-ci: {len(muster)} Muster geladen")
        namen, fundstellen, geprueft = pruefe_dateien(repo, muster)
        ausgabe(f"Dateiinhalte ({geprueft} Dateien)", len(fundstellen), fundstellen)
        if namen:
            # Dateinamen enthalten den Begriff selbst — deshalb nicht im Log, nur die Zahl.
            ausgabe("Dateinamen", namen, [])
        msg_treffer, diff_treffer = pruefe_commits(repo, base, head, muster, branch)
        ausgabe("Commit-Nachrichten", len(msg_treffer),
                [f"Commit {h}" for h in msg_treffer])
        ausgabe("Commit-Diffs (hinzugefügte Zeilen)", len(diff_treffer),
                [f"Commit {h}" for h in diff_treffer])
        if titel:
            ausgabe("PR-Titel", 1 if treffer_zeilen(titel, muster) else 0, [])
        if body:
            zeilen = treffer_zeilen(body, muster)
            ausgabe("PR-Text", len(zeilen), [])
        if branch:
            ausgabe("Branch-Name", 1 if treffer_zeilen(branch, muster) else 0, [])

        treffer = bool(namen or fundstellen or msg_treffer or diff_treffer
                       or (titel and treffer_zeilen(titel, muster))
                       or (body and treffer_zeilen(body, muster))
                       or (branch and treffer_zeilen(branch, muster)))
    except Fehler as e:
        print(f"namenswache-ci: {e}", file=sys.stderr)
        return 2
    if treffer:
        print("\nÖffentliches Repo: keine Kundennamen, Kundendomains, Kunden-Hoster oder "
              "internen Hostnamen — nur erfundene Beispiele. Regel: CLAUDE.md des Repos.")
        return 1
    print("✓ Ergebnis: sauber")
    return 0


if __name__ == "__main__":
    sys.exit(main())
