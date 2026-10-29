# Uketsuke: Datenschutzhinweise

Dieser Text ist ein Baustein für die Datenschutzerklärung des Betreibers. Er beschreibt
die Umsetzung des Moduls. Er ist keine Rechtsberatung. Rechtsgrundlage, Speicherdauer,
Betroffenenrechte und Hosting legt der Betreiber für seine Website fest.

## Was passiert

- Digistore24 meldet dem Modul jedes Ereignis zu einer Bestellung (Zahlung, Erstattung,
  Rückbuchung und weitere Ereignisse) per Server-zu-Server-Meldung (IPN).
- Das Modul prüft die Signatur, speichert die Meldung und bei einer Zahlung den Kauf.
- Das Modul sendet **keine Anfragen nach außen**. Es übermittelt keine Daten an
  Digistore24, an einen Dienst oder an Dritte. E-Mails versendet ausschließlich der
  Code der Website, den der Betreiber selbst einrichtet.
- Keine Cookies, kein Browserspeicher, keine Analyse.

## Was gespeichert wird

- **Käufe** (`purchases`, eine Zeile je Produkt einer Bestellung): Bestellnummer, Produkt, E-Mail-Adresse
  des Käufers, Vor- und Nachname, Land, Partner, Zusatzfeld, Käufer-ID, Betrag,
  Währung, Status und Zeitpunkt der Zahlung.
- **Lieferungen** (`deliveries`, eine Zeile je Kauf und Kanal): vom Betreiber-Code
  geschrieben, etwa Status einer versendeten E-Mail, Fehlertext und Nachrichtenkennung.
  E-Mail-Adressen im Fehlertext werden vor dem Speichern entfernt.
- **Meldungen** (`notifications`, eine Zeile je empfangener Meldung): Ereignis,
  Bestellnummer, Transaktionsnummer, Produktnummer, Empfangszeit und eine feste Liste
  nicht personenbezogener Felder (etwa Produktname, Zahlungsmodus, Abrechnungsstatus,
  Menge, Bestelldatum). Namen, E-Mail-Adressen und Anschriften werden dort nicht gespeichert.

Die Datenbank liegt unter `storage/uketsuke/uketsuke.sqlite`, außerhalb des Web-Roots.
Das Modul verweigert den Betrieb, wenn dieser Ort im Web-Root liegt.

## Speicherdauer

Meldungen werden nach der Einstellung `retention` gelöscht (Standard: 90 Tage). Käufe und
Lieferungen bleiben bestehen, bis der Betreiber sie löscht. Sicherungen des
Speicherverzeichnisses brauchen eine eigene Aufbewahrungsregel.

## Löschen

Einen Kauf löscht der Betreiber, indem er die Zeilen mit der Bestellnummer aus
`deliveries` und `purchases` entfernt (etwa mit einem SQLite-Client), oder indem er das
Speicherverzeichnis entfernt. Vorher das Modul deaktivieren (Option `active` auf `false`).

## Protokoll-Route

Die Route `…/protokoll` gibt Käufe mit Lieferstatus aus, ohne Namen, E-Mail-Adressen und
Anschriften. Sie ist nur mit dem Bearer-Token der Option `token` erreichbar. Die Route
`…/wiederholen` löst die Zahlungs-Hooks erneut aus, sie braucht dasselbe Token.
