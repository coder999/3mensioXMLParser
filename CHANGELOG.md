# Changelog

Notable changes to the 3mensio XML Parser. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions are the
value shown in the page footer.

Entries before 1.1 were reconstructed from the git log after the fact, so they
cover user-visible behaviour only — for infrastructure and deployment history,
read `git log`.

## [1.1] - 2026-08-29

### Added

- Support for 3mensio Structural Heart **10.7** mitral exports. 10.7 renamed
  the annulus measurements to carry the cardiac phase
  (`MitralAnnulusAnnulusArea` became `MitralAnnulusDiastoleAnnulusArea`, and
  likewise for `PerimeterTotal`, `CustomDistance` and `AorticMitralAngle`), so
  four of the six measured lines in the mitral report rendered `N/A` against a
  10.7 file.
- Mitral report now states the annulus, LVOT and neo-LVOT with **diastole and
  systole side by side**, and adds the septal-lateral (AP) and
  trigone-to-trigone annulus distances, LVOT and neo-LVOT perimeters and
  diameters, the reconstruction phase percentages, and the simulated valve's
  frame dimensions — all newly available in 10.7 and none of them previously
  used.

### Changed

- Mitral impression line 2 is filled in automatically: the smaller of the two
  neo-LVOT areas, the virtual frame inflow diameter, and the phase that minimum
  came from. Each falls back independently to `***` so the line stays a fill-in
  prompt on an export that lacks the value.
- Areas are converted to mm² from whatever unit the export used, rather than a
  hardcoded ×100. Annulus areas arrive as `CentimeterSquare` and LVOT/neo-LVOT
  areas as `MillimeterSquare`, so the old assumption would have been wrong
  applied to the new LVOT fields.
- A measurement placeholder may now list several candidate ids
  (`{{M:NewId|OldId}}`), so one template renders 10.6 and 10.7 exports alike.
  10.7 emits the `Measurement` element with no `Value` attribute for anything
  that was not drawn, so a present-but-blank id falls through to the next
  candidate.
- A report line whose values are all missing is dropped rather than printed as
  scaffolding, and a min × max pair with neither value reads `N/A` instead of
  `N/A x N/A`.

Verified by loading the page in headless Chromium and uploading four files: a
real 10.7 mitral export, a synthetic 10.6-style mitral export (legacy ids
resolve into the diastole column, systole reads `N/A`, the phase and virtual
frame lines drop out), a mitral export with no measurements (impression reads
all `***`), and a synthetic aortic export (output unchanged).

## [1.0] - 2026-06-09

### Added

- Browser-based parser for 3mensio XML exports, detecting the aortic, mitral
  and LAA workflows and rendering an editable report from a per-workflow
  template, with rich-text copy to the clipboard for pasting into Epic.
- Iliofemoral measurements in the aortic report, omitted when the export has
  none (2026-06-10).
- Admin section behind single-auth login, with a Google Analytics dashboard
  (2026-08-13).

### Changed

- Aortic calcium score reads the `TotalAgatston` field when present, falling
  back to the previous regex over the comments text (2026-08-14).
- Hosting moved from IONOS to the VPS; production deploys are manual only
  (2026-08-22).

[1.1]: https://github.com/coder999/3mensioXMLParser/releases/tag/v1.1
