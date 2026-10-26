# Iran province and city names

Source: https://github.com/sajaddp/list-of-cities-in-Iran (author: Sajad Dehshiri).
Downloaded 2026-10-10 from `dist/json/provinces.json` and `dist/json/cities-filtered.json`.

These vendored files retain the source records and Persian names without modification:
- `provinces.json`: 31 provinces.
- `cities.json`: 1,481 city entries; each references a valid province.

`cities-filtered` excludes the project's 191 classified urban zones (municipal areas), rather than treating them as separate cities. Cities are distinct from counties. Province/city IDs remain stable identifiers; native names are displayed in both interface languages.

The author's README describes the source as administrative divisions through the end of Iranian year 1404. This is a dataset snapshot, not a live official registry. Future boundary changes require an updated snapshot and review of stored IDs.

Source license: GPL-3.0, reproduced in `IRAN-DATA-LICENSE`. This applies to the vendored dataset. Preserve attribution and license when distributing it.
